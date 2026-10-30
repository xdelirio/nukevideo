<?php

namespace App\Services;

use App\Enums\StreamStatus;
use App\Enums\VideoStatus;
use App\Exceptions\TrackAbortedException;
use App\Jobs\AddTrackJob;
use App\Jobs\PackageVideoJob;
use App\Models\Stream;
use App\Models\Video;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The last step of adding a track to a published video ({@see AddTrackJob}): put its
 * segments and raw file on primary S3, then graft it into the live manifests and mark it ready.
 *
 * The files go up first and outside any lock: they sit under the track's own new `{ulid}/`, which
 * nothing references until a manifest does, and an audio track can be a hundred megabytes. The
 * manifests are edited under the same video-row lock the edit and delete endpoints take
 * ({@see StreamManagementService}), and from a copy read INSIDE it — every track of a video
 * shares the same manifest files, so an edit computed from an earlier read would silently revert
 * whatever changed in between. That is also why the edits arrive as callables, not as results.
 */
class TrackPublisher
{
    /**
     * `path` is each stream's final key, `raw` a local file to keep in its download zone.
     *
     * @param  list<array{stream: Stream, path: string, outputs: list<int>, raw: ?string}>  $tracks
     * @param  array<string, list<callable(string): ?string>>  $edits  manifest key → grafts to apply in order
     *
     * @throws TrackAbortedException when the video or the tracks changed underneath
     */
    public function publish(Video $video, array $tracks, string $packageDir, array $edits): void
    {
        $disk = Storage::disk('s3');
        $uploaded = [];
        $sizes = [];
        $written = [];

        try {
            foreach ($tracks as $track) {
                $stream = $track['stream'];
                $uploaded[] = $stream->segmentsPath($video);
                $packageSize = $this->uploadSegments($video, $stream, "{$packageDir}/{$stream->ulid}");

                $stored = "{$video->downloadPrefix()}/".preg_replace('#^[^/]+/#', '', $track['path'], 1);
                $fileSize = null;

                if ($track['raw'] !== null) {
                    $uploaded[] = $stored;
                    $this->put($stored, $track['raw']);
                    $fileSize = (int) filesize($track['raw']);
                }

                $sizes[$stream->id] = [$packageSize, $fileSize];
            }

            DB::transaction(function () use ($video, $tracks, $edits, $disk, &$sizes, &$written) {
                $locked = Video::whereKey($video->id)->lockForUpdate()->first();

                if (! $locked || $locked->status !== VideoStatus::COMPLETED->value) {
                    throw TrackAbortedException::videoChanged();
                }

                $ids = array_map(fn (array $track) => $track['stream']->id, $tracks);

                if (Stream::whereKey($ids)->count() !== count($ids)) {
                    throw TrackAbortedException::tracksGone();
                }

                foreach ($edits as $path => $grafts) {
                    $original = (string) $disk->get($path);
                    $edited = $original;

                    foreach ($grafts as $graft) {
                        $edited = $graft($edited) ?? $edited;
                    }

                    if ($edited !== $original) {
                        $written[$path] = $original;
                        $disk->put($path, $edited, ['ContentType' => ManifestEditor::contentType(self::format($path))]);
                    }
                }

                foreach ($tracks as $track) {
                    [$packageSize, $fileSize] = $sizes[$track['stream']->id];

                    $track['stream']->update([
                        'status' => StreamStatus::READY,
                        'error_log' => null,
                        'path' => $track['path'],
                        'package_size' => $packageSize,
                        'file_size' => $fileSize,
                    ]);
                    $track['stream']->outputs()->sync($track['outputs']);
                }
            });
        } catch (Throwable $e) {
            // The DB rolled back, but S3 did not: a manifest already rewritten would keep pointing
            // at segments deleted just below, and break playback of the whole output. Put the
            // copies read under the lock back first, then drop what this track uploaded.
            foreach ($written as $path => $original) {
                $disk->put($path, $original, ['ContentType' => ManifestEditor::contentType(self::format($path))]);
            }

            foreach ($uploaded as $key) {
                $disk->deleteDirectory($key);
                $disk->delete($key);
            }

            Log::info('Added track not published; manifests left as they were', ['video' => $video->id, 'reason' => $e->getMessage()]);

            throw $e;
        }
    }

    private static function format(string $path): string
    {
        return str_ends_with($path, '.mpd') ? 'dash' : 'hls';
    }

    /** Every file of one track's packaged tree, typed by extension as the main sync types them. */
    private function uploadSegments(Video $video, Stream $stream, string $dir): int
    {
        $total = 0;

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $relative = substr($file->getPathname(), strlen($dir) + 1);
            $this->put("{$stream->segmentsPath($video)}/{$relative}", $file->getPathname());
            $total += $file->getSize();
        }

        return $total;
    }

    private function put(string $key, string $localPath): void
    {
        $extension = strtolower(pathinfo($localPath, PATHINFO_EXTENSION));
        $handle = fopen($localPath, 'r');

        try {
            $options = isset(PackageVideoJob::SYNC_CONTENT_TYPES[$extension])
                ? ['ContentType' => PackageVideoJob::SYNC_CONTENT_TYPES[$extension]]
                : [];

            if ($handle === false || ! Storage::disk('s3')->writeStream($key, $handle, $options)) {
                throw new \RuntimeException("Failed to upload {$localPath} to {$key}");
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }
}
