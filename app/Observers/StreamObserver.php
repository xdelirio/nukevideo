<?php

namespace App\Observers;

use App\Enums\VideoStatus;
use App\Jobs\AddTrackJob;
use App\Jobs\CleanupVideoResourcesJob;
use App\Models\Stream;
use App\Services\UppyS3Service;
use App\Services\WebhookDispatcher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class StreamObserver
{
    /**
     * Columns a consumer never sees, so an update confined to them would queue a delivery identical
     * to the last one. `path` is the one that happens: archiving an `original` rewrites it on an
     * already-completed video ({@see CleanupVideoResourcesJob::archiveOriginal}). `updated_at` is
     * listed because every update carries it — leave it out and the diff below is never empty, so
     * the `path` filter would never fire. `status` and `error_log` move while a track is being added
     * to a completed video; that job announces its own outcome ({@see AddTrackJob}),
     * so each flip would only be a duplicate of it.
     */
    private const SILENT_COLUMNS = ['path', 'updated_at', 'status', 'error_log'];

    /** {@see withoutAnnouncing} */
    private static bool $announcing = true;

    public function __construct(private UppyS3Service $uploads) {}

    /**
     * Run `$callback` without announcing the stream changes it makes. Deleting a video deletes its
     * `original` through Eloquent so this observer reclaims the object ({@see VideoObserver}), and
     * an update for a video that is disappearing would only race its own `video.deleted`. Scoped to
     * the operation rather than flagged on the video, so it cannot outlive the delete that set it —
     * not even if that delete throws.
     */
    public static function withoutAnnouncing(callable $callback): void
    {
        self::$announcing = false;

        try {
            $callback();
        } finally {
            self::$announcing = true;
        }
    }

    /**
     * `updated` rather than `saved`: the insert path never calls `syncChanges()`, so `getChanges()`
     * is empty after a create and stale after a no-op `save()` — the first would read as "nothing
     * changed" and the second would announce the previous edit twice. Nothing creates a stream on a
     * terminal video anyway; a run mints them all while the video is still active.
     */
    public function updated(Stream $stream): void
    {
        if (array_diff(array_keys($stream->getChanges()), self::SILENT_COLUMNS)) {
            $this->announce($stream);
        }
    }

    public function deleted(Stream $stream): void
    {
        $this->announce($stream);
    }

    /**
     * Tell the project's webhook that the *video* changed. Never the track on its own: every event
     * carries the same payload as `GET /api/videos/{ulid}`, so a consumer stores `data` and lets
     * `event` decide the side effects — one shape to handle instead of a partial per event.
     *
     * Restricted to a video in a terminal status, which is what keeps a run quiet: the probe, the
     * per-title CRF, the quality bitrate, the encode rates and the packaged sizes all write streams
     * while the video is still active, and each of them would otherwise cost a delivery carrying a
     * video the consumer was told to poll anyway. What is left is exactly what an integrator cannot
     * see coming — a track edited or deleted through the API, and the `original` being reclaimed
     * after a successful run ({@see CleanupVideoResourcesJob}).
     */
    private function announce(Stream $stream): void
    {
        if (! self::$announcing) {
            return;
        }

        $video = $stream->video;

        if (! $video || ! in_array($video->status, [VideoStatus::COMPLETED->value, VideoStatus::FAILED->value], true)) {
            return;
        }

        WebhookDispatcher::forVideo('video.updated', $video);
    }

    public function deleting(Stream $stream): void
    {
        foreach ($this->keysFor($stream) as $key) {
            if (Storage::exists($key) && ! Storage::delete($key)) {
                Log::warning("Failed to delete storage file for stream {$stream->id}: {$key}");
            }
        }

        if ($stream->type === 'original') {
            // The upload metadata outlives the object on purpose (it is what lets a lost bucket
            // webhook be replayed, {@see PruneScratchJob}) — but an original whose row is gone
            // must not be replayable: if the delete above failed and left the object behind, the
            // sweep would re-ingest it and hand the user back the video they just deleted.
            $this->uploads->forgetUploadMeta($stream->path);

            return;
        }

        // The packaged segments are this stream's own, not the video's: a re-probe replaces the
        // stream under a new ULID and the video keeps living, so nothing else ever reclaims the
        // old directory. A video delete wipes the whole prefix anyway; this is idempotent with it.
        if ($video = $stream->video) {
            Storage::deleteDirectory($stream->segmentsPath($video));
        }
    }

    /**
     * `path` is the authoritative key only for an `original`, which
     * {@see CleanupVideoResourcesJob::archiveOriginal} rewrites when it files the upload
     * away. For every other type `path` just carries the filename and the real object lives in the
     * video's download zone, so both keys are tried — otherwise deleting a stream silently deletes
     * nothing and leaks the heaviest objects we store.
     *
     * @return list<string>
     */
    private function keysFor(Stream $stream): array
    {
        $keys = array_filter([$stream->path]);

        if ($stream->type !== 'original' && $video = $stream->video) {
            $keys[] = $stream->storedPath($video);
        }

        return array_values(array_unique($keys));
    }
}
