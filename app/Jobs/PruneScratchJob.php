<?php

namespace App\Jobs;

use App\Models\Stream;
use App\Models\Video;
use App\Services\UppyS3Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Uid\Ulid;

/**
 * Backstop that reclaims local staging and chunk-store leftovers from failed or crashed runs
 * (the success path drops a video's `{ulid}` on completion). A `{ulid}` is removed only when
 * its video is terminal (or gone) and past the grace window, so an in-flight run is never wiped.
 */
class PruneScratchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    /** Leave anything that became terminal (or was last written) within this window. */
    private const GRACE_SECONDS = 1800; // 30 min

    /** Uploads that never became a video are the user's only copy, so reclaim them far more
     *  conservatively than internal scratch — a full day for any manual recovery/retry. */
    private const UPLOAD_GRACE_SECONDS = 86400; // 24 h

    /** Twice an added track's job timeout ({@see AddTrackJob::$timeout}). */
    private const TRACK_SCRATCH_MAX_AGE_SECONDS = 3600;

    public function handle(UppyS3Service $uploads): void
    {
        $this->pruneLocalScratch();
        $this->pruneTrackScratch();
        $this->pruneChunkStore();
        $this->pruneUploadTmp($uploads);
        $this->pruneStaleMultipartUploads();
    }

    /**
     * Scratch of added tracks ({@see AddTrackJob::SCRATCH_DIR}) left by a run killed before its own
     * cleanup — a timeout ends the process outside any `finally`. A run recreates its directory on
     * start, so anything older than one run's longest life is an orphan.
     */
    private function pruneTrackScratch(): void
    {
        $disk = Storage::disk('local');

        foreach ($disk->directories(AddTrackJob::SCRATCH_DIR) as $dir) {
            $modified = @filemtime($disk->path($dir));

            if ($modified !== false && $modified < now()->subSeconds(self::TRACK_SCRATCH_MAX_AGE_SECONDS)->getTimestamp()) {
                $disk->deleteDirectory($dir);
                Log::info('Pruned orphaned track scratch', ['dir' => $dir]);
            }
        }
    }

    private function pruneLocalScratch(): void
    {
        $disk = Storage::disk('local');
        $root = rtrim($disk->path(''), DIRECTORY_SEPARATOR);

        foreach (@scandir($root) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $absolute = $root.DIRECTORY_SEPARATOR.$name;

            // Our staging dirs are named with the video ULID; never touch anything else.
            if (! is_dir($absolute) || ! Str::isUlid($name)) {
                continue;
            }

            $video = Video::where('ulid', $name)->first();

            if ($video) {
                if ($this->withinGrace($video)) {
                    continue;
                }
            } elseif ($this->tooYoung($absolute)) {
                continue; // orphaned but recently written — be safe
            }

            if ($disk->deleteDirectory($name)) {
                Log::info('Pruned orphaned staging', ['ulid' => $name, 'video' => $video?->id]);
            }
        }
    }

    private function pruneChunkStore(): void
    {
        $disk = Storage::disk('chunks');

        foreach ($disk->directories() as $path) {
            $name = basename($path);

            if (! Str::isUlid($name)) {
                continue;
            }

            $video = Video::where('ulid', $name)->first();

            // No mtime on object storage, so an orphan is dropped outright; a known video is
            // kept until terminal + past the grace window.
            if ($video && $this->withinGrace($video)) {
                continue;
            }

            // Only our own subtrees (the store reuses the default bucket), never the whole prefix.
            // FINAL_DIR (single-pass tracks) is included so a video that failed before
            // finalizeVideoIfReady — the only other place that clears it — doesn't leak it.
            $pruned = false;
            foreach ([Video::CHUNKS_DIR, Video::SOURCE_DIR, Video::FINAL_DIR] as $sub) {
                $pruned = $disk->deleteDirectory("{$name}/{$sub}") || $pruned;
            }

            if ($pruned) {
                Log::info('Pruned orphaned internal store', ['ulid' => $name, 'video' => $video?->id]);
            }
        }
    }

    /**
     * Reclaim raw uploads orphaned when ingest failed before a Video row existed (OnVideoUploaded
     * retains them "for age-based GC" — this is that GC). An upload that ingested successfully is
     * its `original` stream's `path` until cleanup, so those are skipped; the rest are keyed by a
     * ULID filename whose embedded timestamp gives the age with no object mtime.
     */
    private function pruneUploadTmp(UppyS3Service $uploads): void
    {
        $folder = (string) config('uppy-s3-multipart-upload.s3.bucket.folder');

        if ($folder === '') {
            return;
        }

        $disk = Storage::disk('s3');
        $keys = $disk->files($folder);

        if ($keys === []) {
            return;
        }

        $referenced = array_flip(Stream::whereIn('path', $keys)->pluck('path')->all());

        foreach ($keys as $key) {
            if (isset($referenced[$key])) {
                continue; // belongs to a live video's original stream
            }

            // Uploaded object with live metadata but no ingested stream: the storage webhook was
            // most likely lost. Re-dispatch ingestion (idempotent) once instead of letting the
            // user's upload silently age out below.
            if ($uploads->getUploadMeta($key)) {
                if (Cache::add("reingest:{$key}", 1, self::UPLOAD_GRACE_SECONDS * 2)) {
                    OnVideoUploaded::dispatch($key, (int) $disk->size($key));
                    Log::warning('Re-dispatched ingestion for un-ingested upload (lost webhook?)', ['key' => $key]);
                }

                continue;
            }

            $ulid = pathinfo($key, PATHINFO_FILENAME);

            if (! Str::isUlid($ulid) || $this->uploadWithinGrace($ulid)) {
                continue;
            }

            if ($disk->delete($key)) {
                Log::info('Pruned orphaned upload', ['key' => $key]);
            }
        }
    }

    /**
     * Abort multipart uploads abandoned mid-flight (browser closed, client crashed): their parts
     * are invisible to `files()` yet billed forever, and nothing else reclaims them. Best-effort —
     * not every S3-compatible store supports the listing, so failures only log.
     */
    private function pruneStaleMultipartUploads(): void
    {
        try {
            $disk = Storage::disk('s3');
            $result = $disk->getClient()->listMultipartUploads([
                'Bucket' => (string) config('filesystems.disks.s3.bucket'),
                'Prefix' => (string) config('uppy-s3-multipart-upload.s3.bucket.folder'),
            ]);

            foreach ($result['Uploads'] ?? [] as $upload) {
                $initiated = $upload['Initiated'] ?? null;

                if (! $initiated instanceof \DateTimeInterface
                    || $initiated->getTimestamp() > now()->subSeconds(self::UPLOAD_GRACE_SECONDS)->getTimestamp()) {
                    continue;
                }

                $disk->getClient()->abortMultipartUpload([
                    'Bucket' => (string) config('filesystems.disks.s3.bucket'),
                    'Key' => $upload['Key'],
                    'UploadId' => $upload['UploadId'],
                ]);

                Log::info('Aborted stale multipart upload', ['key' => $upload['Key']]);
            }
        } catch (\Throwable $e) {
            Log::info("Stale multipart sweep skipped: {$e->getMessage()}");
        }
    }

    /** True while an upload's ULID timestamp is younger than the upload grace window. */
    private function uploadWithinGrace(string $ulid): bool
    {
        try {
            $createdAt = Ulid::fromString($ulid)->getDateTime()->getTimestamp();
        } catch (\Throwable) {
            return true; // unparseable timestamp — never delete on a guess
        }

        return $createdAt > now()->subSeconds(self::UPLOAD_GRACE_SECONDS)->getTimestamp();
    }

    /** True while the video is still active or only just became terminal. */
    private function withinGrace(Video $video): bool
    {
        if (in_array($video->status, Video::ACTIVE_STATUSES, true)) {
            return true;
        }

        return (bool) $video->updated_at?->gt(now()->subSeconds(self::GRACE_SECONDS));
    }

    private function tooYoung(string $absolutePath): bool
    {
        $mtime = @filemtime($absolutePath);

        return $mtime === false || (time() - $mtime) < self::GRACE_SECONDS;
    }
}
