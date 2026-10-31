<?php

namespace App\Services;

use App\Enums\StreamStatus;
use App\Enums\VideoStatus;
use App\Exceptions\TrackAbortedException;
use App\Jobs\Concerns\SyncsViaS5cmd;
use App\Models\Stream;
use App\Models\Video;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/**
 * The last step of adding a track to a published video ({@see AddTrackService}): put its segments
 * and raw file on primary S3, then graft it into the live manifests and mark it ready.
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
    use SyncsViaS5cmd;

    /** Set while {@see publish} holds its transaction open ({@see abandonOpenPublish}). */
    private static int $openTransactionLevel = 0;

    /**
     * `path` is each stream's final key, `raw` a local file to keep in its download zone.
     *
     * @param  list<array{stream: Stream, path: string, outputs: list<int>, raw: ?string}>  $tracks
     * @param  array<string, list<callable(string): ?string>>  $edits  manifest key → grafts to apply in order
     *
     * @throws TrackAbortedException when the video or the tracks changed underneath
     */
    public function publish(Video $video, array $tracks, string $packageDir, array $edits, int $timeout): void
    {
        $disk = Storage::disk('s3');
        $uploaded = [];
        $written = [];

        try {
            foreach ($tracks as $track) {
                $segments = $track['stream']->segmentsPath($video);
                $uploaded[] = $segments;

                // Each track's own tree to its own new prefix, typed as the main package is. Per
                // track, not the whole package dir: anything else in it (a previous attempt's
                // leftovers) must never reach the playback zone, and each pass lists only a
                // destination that is still empty, never the video's thousands of published objects.
                $this->s5cmdSyncTyped('s3', "{$packageDir}/{$track['stream']->ulid}/", $this->s3Uri('s3', "{$segments}/"), $video, $timeout);

                if ($track['raw'] !== null) {
                    $uploaded[] = $stored = "{$video->downloadPrefix()}/".preg_replace('#^[^/]+/#', '', $track['path'], 1);

                    // The disk does not throw on failure; a lost VTT is the one input a subtitle
                    // repair can read back, and a track marked downloadable would 404.
                    if ($disk->putFileAs(dirname($stored), $track['raw'], basename($stored)) === false) {
                        throw new RuntimeException("Could not store the track file at {$stored}.");
                    }
                }
            }

            DB::transaction(function () use ($video, $tracks, $edits, $disk, $packageDir, &$written) {
                self::$openTransactionLevel = DB::transactionLevel();

                $locked = Video::whereKey($video->id)->lockForUpdate()->first();

                if (! $locked || $locked->status !== VideoStatus::COMPLETED->value) {
                    throw TrackAbortedException::videoChanged();
                }

                $ids = array_map(fn (array $track) => $track['stream']->id, $tracks);

                if (Stream::whereKey($ids)->count() !== count($ids)) {
                    throw TrackAbortedException::tracksGone();
                }

                foreach ($edits as $path => $grafts) {
                    $original = $disk->get($path);

                    // An unreadable manifest grafted as empty would publish a manifest of one track.
                    if ($original === null || $original === '') {
                        throw new RuntimeException("Could not read the published manifest {$path}.");
                    }

                    $edited = $original;

                    foreach ($grafts as $graft) {
                        $edited = $graft($edited) ?? $edited;
                    }

                    if ($edited !== $original) {
                        $written[$path] = $original;

                        if (! $disk->put($path, $edited, ['ContentType' => ManifestEditor::contentType(self::format($path))])) {
                            throw new RuntimeException("Could not write the published manifest {$path}.");
                        }
                    }
                }

                foreach ($tracks as $track) {
                    $track['stream']->update([
                        'status' => StreamStatus::READY,
                        'error_log' => null,
                        'path' => $track['path'],
                        'package_size' => self::treeSize("{$packageDir}/{$track['stream']->ulid}"),
                        'file_size' => $track['raw'] !== null ? (int) filesize($track['raw']) : null,
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
        } finally {
            self::$openTransactionLevel = 0;
        }
    }

    /**
     * Roll back a publish transaction its job is being failed from inside of — a timeout signal
     * lands wherever the job is, and {@see AddTrackService::fail} must write outside it. Unwinds
     * only that transaction, never one a caller (or a test) opened around it.
     */
    public static function abandonOpenPublish(): void
    {
        while (self::$openTransactionLevel > 0 && DB::transactionLevel() >= self::$openTransactionLevel) {
            DB::rollBack();
        }

        self::$openTransactionLevel = 0;
    }

    private static function format(string $path): string
    {
        return str_ends_with($path, '.mpd') ? 'dash' : 'hls';
    }

    /** Bytes of one track's packaged tree: what serves its playback. */
    private static function treeSize(string $dir): int
    {
        if (! is_dir($dir)) {
            return 0;
        }

        $total = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $total += $file->getSize();
        }

        return $total;
    }
}
