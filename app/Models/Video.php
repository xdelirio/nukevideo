<?php

namespace App\Models;

use App\Enums\VideoStatus;
use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\VideoController;
use App\Jobs\CleanupVideoResourcesJob;
use App\Jobs\Concerns\CompletesVideo;
use App\Jobs\PrepareVideoJob;
use App\Jobs\PruneScratchJob;
use App\Observers\VideoObserver;
use App\Services\AddTrackService;
use App\Services\WebhookDispatcher;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[ObservedBy(VideoObserver::class)]
class Video extends Model
{
    protected $fillable = [
        'template_id',
        'template_version_id',
        'user_id',
        'project_id',
        'node_id',
        'name',
        'duration',
        'aspect_ratio',
        'chunk_count',
        'status',
        'priority',
        'external_user_id',
        'external_resource_id',
        'last_heartbeat_at',
    ];

    // The column default alone leaves a just-created model without `priority` in memory, and the
    // upload webhook and API responses are built from that instance.
    protected $attributes = [
        'priority' => 'normal',
    ];

    /** Non-terminal statuses: the video is still being processed. */
    public const ACTIVE_STATUSES = [
        VideoStatus::PENDING->value,
        VideoStatus::DOWNLOADING->value,
        VideoStatus::RUNNING->value,
        VideoStatus::UPLOADING->value,
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->ulid = (string) Str::ulid();
        });
    }

    public function casts()
    {
        return [
            'duration' => 'double',
            'chunk_count' => 'integer',
            'last_heartbeat_at' => 'datetime',
        ];
    }

    /** Liveness signal beaten by every processing stage; a dead worker stops it and the reaper recovers the video. */
    public function heartbeat(): void
    {
        $this->forceFill(['last_heartbeat_at' => now()])->saveQuietly();
    }

    /*
     * Storage layout — the sub-dir names (one source of truth) plus the few keys that are built in
     * more than one place. One-off keys (e.g. the mirrored source, a rendition) are inlined at their
     * single call site. Disk selection stays at the call site; these return keys only.
     */

    /** Sub-dir names used across the internal mirror ('chunks' disk) and local scratch.
     *
     *  These must never name a primary-S3 zone. Both disks are configured with the SAME bucket and
     *  differ only by endpoint, so wherever they resolve to one store, the three mirror sweeps
     *  ({@see PruneScratchJob}, {@see CompletesVideo} and
     *  {@see CleanupVideoResourcesJob}, this last one on the happy path) delete these
     *  directories by name and would take the primary object with them. That is why the archived
     *  original lives under {@see ORIGINAL_DIR} and not here. */
    public const SOURCE_DIR = 'source';

    public const CHUNKS_DIR = 'chunks';

    public const FINAL_DIR = 'final';

    public const GATHER_DIR = 'gather';

    public const CHUNKSTAGE_DIR = 'chunkstage';

    public const SIDECAR_DIR = 'sidecar';

    /** Local-scratch sub-dir holding the raw renditions a template chose to keep, while they wait
     *  for their own sync. Not a primary-S3 name: on S3 they land in {@see downloadPrefix}. */
    public const PROCESSED_DIR = 'processed';

    /*
     * Primary-S3 zones, one per authorization boundary.
     *
     * A CDN token authorizes the *directory* of the URL it signs and the edge compares that ACL as a
     * raw byte prefix ({@see \App\Services\Cdn\EdgeLinkSigner::aclFor}), so whatever directory a
     * manifest sits in is exactly how far every playback link reaches. Keeping playback in its own
     * sub-dir is therefore the whole mechanism: it is what stops a playback link from also fetching
     * the full-quality masters and the archived original.
     *
     * Lowercase names, and a stream ULID is 26 chars of uppercase base32 — they can never collide
     * with a segment directory.
     */

    /** Manifests and CMAF segments: exactly what a playback token must reach, and nothing else. */
    public const PLAY_DIR = 'play';

    /** The tracks a user may fetch, plus muxed derivatives later. */
    public const DOWNLOAD_DIR = 'download';

    /** Thumbnail and storyboard. Its own zone so an image link cannot unlock playback. */
    public const ASSETS_DIR = 'assets';

    /** The retained upload. Deliberately NOT `source`, which the mirror already uses — see SOURCE_DIR. */
    public const ORIGINAL_DIR = 'original';

    /** Filename of every video's thumbnail/storyboard assets, shared by the jobs that produce them
     *  and the controller/DTOs that serve/link them. */
    public const THUMBNAIL_FILENAME = 'thumbnail.jpg';

    public const STORYBOARD_VTT_FILENAME = 'storyboard.vtt';

    /** printf-style pattern ffmpeg fills in (`%d`) when emitting one sprite sheet per call; mirrors
     *  {@see storyboardSpriteFilename} so the writer and reader of these files never disagree. */
    public const STORYBOARD_SPRITE_PATTERN = 'storyboard_%d.jpg';

    public static function storyboardSpriteFilename(int $index): string
    {
        return sprintf(self::STORYBOARD_SPRITE_PATTERN, $index);
    }

    /** Glob matching every chunk filename `chunkKey()` produces, for jobs that need to enumerate
     *  staged chunks rather than address one by index. */
    public const CHUNK_FILENAME_GLOB = 'chunk_*.mp4';

    /**
     * Zero-padding of the chunk index. Wide enough that it never overflows: the planner floors a
     * window at 8s, so even a 24h source stays under 11k chunks. It used to be 3, which a long
     * 4K source overruns — and past the padding, lexicographic order stops matching numeric
     * order, so anything that concatenated by sorted name spliced the timeline out of order.
     */
    private const CHUNK_INDEX_PAD = 6;

    /** Legacy 3-wide padding; still resolved when reading so a video staged by an older build
     *  (or mid-flight across a deploy) still packages on retry. */
    private const LEGACY_CHUNK_INDEX_PAD = 3;

    /** Internal-mirror key of one encoded chunk: `{ulid}/chunks/{streamUlid}/chunk_NNNNNN.mp4` (built in two jobs). */
    public function chunkKey(Stream $stream, int $index): string
    {
        return sprintf('%s/%s/%s', $this->chunksDir(), $stream->ulid, self::chunkFilename($index));
    }

    public static function chunkFilename(int $index): string
    {
        return sprintf('chunk_%0'.self::CHUNK_INDEX_PAD.'d.mp4', $index);
    }

    /**
     * Every filename one index may legitimately carry on disk, newest padding first. Readers must
     * try them all; writers only ever produce the first.
     *
     * @return array<int, string>
     */
    public static function chunkFilenameCandidates(int $index): array
    {
        return array_values(array_unique([
            self::chunkFilename($index),
            sprintf('chunk_%0'.self::LEGACY_CHUNK_INDEX_PAD.'d.mp4', $index),
        ]));
    }

    /** Internal-mirror dir holding every stream's staged chunks: `{ulid}/chunks` (synced/pruned by several jobs). */
    public function chunksDir(): string
    {
        return self::chunksDirFor($this->ulid);
    }

    /** Same as {@see chunksDir()} for call sites that only have the bare ulid (e.g. scratch-dir sweeps). */
    public static function chunksDirFor(string $ulid): string
    {
        return "{$ulid}/".self::CHUNKS_DIR;
    }

    /** Internal-mirror dir holding every single-pass staged asset: `{ulid}/final` (synced/pruned by several jobs). */
    public function finalDir(): string
    {
        return "{$this->ulid}/".self::FINAL_DIR;
    }

    /** Internal-mirror key of the mirrored source upload: `{ulid}/source/original.{ext}`. */
    public function sourceMirrorPath(string $extension): string
    {
        return "{$this->ulid}/".self::SOURCE_DIR."/original.{$extension}";
    }

    /** Local scratch path for one sidecar track before it's staged to the mirror: `{ulid}/sidecar/{streamUlid}.{ext}`. */
    public function sidecarPath(Stream $stream, string $extension): string
    {
        return "{$this->ulid}/".self::SIDECAR_DIR."/{$stream->ulid}.{$extension}";
    }

    /** Local scratch dir (relative) where renditions are concatenated and packaged. */
    public function gatherDir(): string
    {
        return "{$this->ulid}/".self::GATHER_DIR;
    }

    /** Local scratch dir (relative) where chunks are staged before concat. */
    public function chunkstageDir(): string
    {
        return "{$this->ulid}/".self::CHUNKSTAGE_DIR;
    }

    /**
     * PUBLIC URL path of a thumbnail/storyboard asset: `{ulid}/{filename}`, served by
     * {@see VideoController::getAsset} under `/api/videos/…`.
     *
     * Deliberately NOT the storage key ({@see assetKey}) and deliberately unchanged by the zoned
     * layout: `routes/api.php` whitelists these filenames, the responses are cached for a week, and
     * every panel/API consumer already holds these URLs. The zone is resolved server-side instead.
     */
    public static function assetPath(string $ulid, string $filename): string
    {
        return "{$ulid}/{$filename}";
    }

    /** Local scratch dir where retained renditions wait for their own sync: `{ulid}/processed`. */
    public function processedDir(): string
    {
        return "{$this->ulid}/".self::PROCESSED_DIR;
    }

    /** Primary-S3 prefix a playback token authorizes: manifests and every stream's segment dir. */
    public function playPrefix(): string
    {
        return $this->zonePrefix(self::PLAY_DIR);
    }

    /** Primary-S3 prefix of the downloadable tracks — the full-quality masters a playback token
     *  must NOT reach. */
    public function downloadPrefix(): string
    {
        return $this->zonePrefix(self::DOWNLOAD_DIR);
    }

    /** Primary-S3 prefix of the thumbnail/storyboard objects. */
    public function assetsPrefix(): string
    {
        return $this->zonePrefix(self::ASSETS_DIR);
    }

    /** Primary-S3 prefix of the archived original. No manifest and no issued token ever names it. */
    public function originalPrefix(): string
    {
        return $this->zonePrefix(self::ORIGINAL_DIR);
    }

    /** Primary-S3 key of a thumbnail/storyboard object. See {@see assetPath} for the public URL. */
    public function assetKey(string $filename): string
    {
        return self::assetKeyFor($this->ulid, $filename);
    }

    /** Same key for call sites that only hold the ulid, e.g. building a listing payload. */
    public static function assetKeyFor(string $ulid, string $filename): string
    {
        return "{$ulid}/".self::ASSETS_DIR."/{$filename}";
    }

    private function zonePrefix(string $zone): string
    {
        return "{$this->ulid}/{$zone}";
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function streams(): HasMany
    {
        return $this->hasMany(Stream::class);
    }

    public function outputs(): HasMany
    {
        return $this->hasMany(Output::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    /**
     * Every stream one track upload became ({@see AddTrackService}): a 5.1 dub can
     * be several rungs, which share the upload key in `meta.track_upload`. Filtered in PHP — the
     * schema runs on SQLite too, so no JSON path queries — over a video's handful of streams.
     *
     * @return Collection<int, Stream>
     */
    public function streamsOfTrackUpload(string $key): Collection
    {
        return $this->streams()->whereIn('type', ['audio', 'subtitle'])->get()
            ->filter(fn (Stream $stream) => ($stream->meta['track_upload'] ?? null) === $key)
            ->values();
    }

    /** The immutable encoding definition this video was ingested with ({@see TemplateVersion}). */
    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(TemplateVersion::class);
    }

    /**
     * What this video encodes with: its pinned version, so a template edited after the upload
     * changes nothing for it. The live template only stands in for a row that has no version —
     * one the versions backfill could not reach.
     *
     * @return array<string, mixed>
     */
    public function encodingQuery(): array
    {
        return $this->encodingDefinition()?->query ?? [];
    }

    /** {@see encodingQuery}, as the model that answers what it needs of the fleet. */
    public function encodingDefinition(): TemplateVersion|Template|null
    {
        return $this->templateVersion ?? $this->template;
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public function getSourceHeight(): ?int
    {
        return $this->streams()
            ->where('type', 'video')
            ->get()
            ->max(fn (Stream $s) => $s->meta['source_height'] ?? null);
    }

    /**
     * @param  string|null  $reason  What went wrong. Stream-scoped failures also write it to that
     *                               stream's `error_log`; this one carries the ones no single
     *                               stream owns (a stalled worker, a source that never arrived),
     *                               which otherwise leave a failed video explainable only by log
     *                               forensics. Rides the activity entry, read back through
     *                               {@see ActivityLogController}.
     */
    public function markAsFailed(?string $reason = null): void
    {
        // Guarded + atomic: only an active video may move to FAILED, so a reaper/prune sweep
        // can't clobber one a slow-but-alive worker just COMPLETED, nor double-fire the webhook.
        $transitioned = static::query()
            ->whereKey($this->id)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->update(['status' => VideoStatus::FAILED->value]);

        if (! $transitioned) {
            return;
        }

        $this->setAttribute('status', VideoStatus::FAILED->value);
        // Never clobber an output a concurrent success path already COMPLETED (mirrors PackageVideoJob::failed).
        $this->outputs()
            ->whereNotIn('status', [VideoStatus::COMPLETED->value, VideoStatus::FAILED->value])
            ->update(['status' => VideoStatus::FAILED->value]);

        // Every terminal-failure path funnels through here, so this is the one place that can
        // guarantee the fleet stops. Without it a reaped video keeps its queued chunks running
        // while `videos:dispatch` hands the freed slot to a new video, and a mixed CPU+GPU
        // template leaves the sibling hardware batch encoding a video already declared dead.
        $this->cancelEncodeBatches();

        activity('video')
            ->performedOn($this)
            ->causedBy($this->user)
            ->event('video_failed')
            ->withProperties(array_filter(['reason' => self::trimReason($reason)]))
            ->log("Video processing failed: {$this->name}");

        WebhookDispatcher::forVideo('video.error', $this);
    }

    /**
     * Stops the encode batches of a video that can no longer complete, so their jobs don't keep
     * burning encode slots (or die confusingly against artifacts the failure path removed).
     * Matches how {@see PrepareVideoJob::fanOut()} names them: one batch per hardware queue,
     * "encode video {id} {queue}" — so a mixed CPU+GPU video cancels both, not only the queue
     * whose chunk happened to fail.
     */
    public function cancelEncodeBatches(): void
    {
        $ids = DB::table('job_batches')
            ->where('name', 'like', "encode video {$this->id} %")
            ->whereNull('finished_at')
            ->pluck('id');

        foreach ($ids as $id) {
            Bus::findBatch($id)?->cancel();
        }
    }

    /** ffmpeg pours its whole stderr into an exception; keep the head, where the cause is. */
    public static function trimReason(?string $reason): ?string
    {
        $reason = trim((string) $reason);

        return $reason === '' ? null : Str::limit($reason, 1000);
    }
}
