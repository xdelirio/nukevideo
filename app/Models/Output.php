<?php

namespace App\Models;

use App\Enums\VideoStatus;
use App\Jobs\PackageVideoJob;
use App\Observers\OutputObserver;
use App\Services\ChunkTranscodeService;
use App\Services\CreateVideoStreamsService;
use App\Services\ManifestEditor;
use App\Services\PackagerCommandBuilder;
use App\Services\VodLinkService;
use App\Support\Bitrate;
use Closure;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Throwable;

#[ObservedBy(OutputObserver::class)]
class Output extends Model
{
    protected $fillable = [
        'video_id',
        'template_index',
        'status',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->ulid = (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'status' => VideoStatus::class,
            'packaged_formats' => 'array',
        ];
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function streams(): BelongsToMany
    {
        return $this->belongsToMany(Stream::class);
    }

    public function packagePrefix(): string
    {
        return $this->video->playPrefix();
    }

    /**
     * This output's manifest filename for a format, optionally capped to a height: `{ulid}.m3u8` /
     * `{ulid}.720.m3u8` (HLS) or `{ulid}.mpd` / `{ulid}.720.mpd` (DASH). Keyed by the output's own
     * ulid (not a fixed `master`/`manifest` name) so two outputs of the same video — which can land
     * on the same cap if their video renditions share a height — never collide on the same S3 key;
     * segments stay shared at `{videoUlid}/play/{streamUlid}/…` regardless, only the manifest is
     * scoped per output. Shared with {@see PackagerCommandBuilder} so the packager output and
     * the served path can never diverge.
     */
    public function manifestFile(string $format, ?int $cap = null): string
    {
        $ext = $format === 'hls' ? 'm3u8' : 'mpd';

        return $cap === null ? "{$this->ulid}.{$ext}" : "{$this->ulid}.{$cap}.{$ext}";
    }

    /** Public path of this output's manifest, mirroring its S3 key (`{videoUlid}/play/file`), optionally capped. */
    public function manifestPath(string $format, ?int $cap = null): string
    {
        return "{$this->packagePrefix()}/".$this->manifestFile($format, $cap);
    }

    /**
     * Manifest cap for the max resolution a client can play: the largest rendition whose SHORT
     * edge is <= $resolution (the smallest if every one is above it). Null (full master) when
     * unset, at or above the top rung, or when the top rung is the one that fits.
     *
     * Compared on the short edge because that is what "1080p" means in either orientation — on
     * height, a vertical 1080x1920 ladder answered `resolution=1080` with its 480x854 rung. The
     * returned cap is still the rung's HEIGHT: that is what the capped manifests are named after
     * ({@see manifestFile}), and within one ladder heights and short edges sort the same way. It
     * also keeps portrait videos packaged before rungs turned with the source playable: their
     * capped files are named after the heights they have.
     */
    public function resolveCap(?int $resolution): ?int
    {
        if ($resolution === null) {
            return null;
        }

        $rungs = $this->streams
            ->where('type', 'video')
            ->filter(fn (Stream $stream) => $stream->height)
            ->map(fn (Stream $stream) => [
                'height' => (int) $stream->height,
                'short' => min((int) $stream->height, (int) ($stream->width ?: $stream->height)),
            ])
            ->unique('height')
            ->sortBy('height')
            ->values();

        if ($rungs->isEmpty() || $resolution >= $rungs->last()['short']) {
            return null;
        }

        $target = ($rungs->filter(fn (array $rung) => $rung['short'] <= $resolution)->last()
            ?? $rungs->first())['height'];

        // The max height's manifest is the uncapped master (no `.{height}.` file exists for it).
        return $target === $rungs->last()['height'] ? null : $target;
    }

    /**
     * Streaming formats this output actually serves. Frozen in `packaged_formats` at package time
     * ({@see recordFormats}) rather than recomputed live: a later stream deletion edits manifests in
     * place but never deletes the file ({@see ManifestEditor::removeStream}), so a live
     * recomputation from the currently-attached streams' codecs could silently drift from what's
     * really packaged on S3 — e.g. deleting the only Opus (DASH-only) audio stream from an output
     * would make a live computation claim HLS too, even though no `.m3u8` was ever packaged, breaking
     * {@see VodLinkService::forVideo}. Falls back to the live computation only
     * before packaging has run (`packaged_formats` is still null).
     *
     * @return list<string>
     */
    public function formats(): array
    {
        return $this->packaged_formats ?? $this->computedFormats();
    }

    /**
     * Live computation from this output's streams' codecs: the intersection of each codec's
     * supported protocols (config/ffmpeg.php). One CMAF package emits a manifest per format over
     * shared segments, so e.g. H.264+AAC yields both HLS and DASH, Opus only DASH. Authoritative only
     * at package time ({@see PackageVideoJob::packageOutput}, which freezes the result via
     * {@see recordFormats}); use {@see formats()} everywhere else.
     *
     * @return list<string>
     */
    public function computedFormats(): array
    {
        $codecs = collect(config('ffmpeg.codecs'));

        $protocolSets = $this->streams
            ->filter(fn (Stream $stream) => in_array($stream->type, ['video', 'audio'], true))
            ->map(fn (Stream $stream) => $codecs->firstWhere('codec', $this->streamCodec($stream))['protocols'] ?? [])
            ->filter()
            ->values();

        if ($protocolSets->isEmpty()) {
            return [];
        }

        return array_values(array_intersect(...$protocolSets->all()));
    }

    /** Freeze the formats actually packaged so {@see formats()} never has to recompute (and can't
     *  drift) after. Call once packaging for this output has genuinely finished. */
    public function recordFormats(array $formats): void
    {
        $this->forceFill(['packaged_formats' => $formats])->save();
    }

    /**
     * The decodable video format this output serves (`h264`, `hevc`, `av1`), or null when no
     * rendition records one.
     *
     * Read live from the attached streams rather than frozen like {@see formats()}. The freeze
     * exists because deleting a stream edits the manifests but never deletes the manifest FILE, so
     * the packaged formats can outlive their streams. Codecs have no such gap: removing a stream
     * takes its codec out of the manifest too ({@see ManifestEditor::removeStream}), so what is
     * attached is what is served.
     */
    public function videoCodec(): ?string
    {
        return $this->codecFamily('video');
    }

    /**
     * The nominal rate (bits/s) of the heaviest audio rendition this output serves: what its HLS
     * variants' declared peak already counts. Null when one of them states no rate (VBR), so a
     * caller cannot mistake "unknown" for "none" ({@see ManifestEditor::hlsAddAudio}).
     */
    public function nominalAudioPeakBps(): ?int
    {
        $rates = $this->streams->where('type', 'audio')
            // No codec pinned is the fixed 128k default encode ({@see ChunkTranscodeService::buildAudioArguments}).
            ->map(fn (Stream $stream) => data_get($stream->input_params, 'audio_codec') === null
                ? 128_000
                : Bitrate::toBps(data_get($stream->input_params, 'audio_bitrate')));

        return $rates->contains(null) ? null : (int) $rates->max();
    }

    /** The decodable audio format this output serves (`aac`, `opus`), or null when it carries no
     *  audio at all — a source with no audio track produces exactly that. */
    public function audioCodec(): ?string
    {
        return $this->codecFamily('audio');
    }

    /**
     * The codec family of this output's streams of one type, in the catalogue's terms: the ENCODER
     * the row records (`libx264`, `h264_nvenc`) mapped to the bitstream it writes, which is the
     * only half a player can act on.
     *
     * One value per type, not a list, because that is what the template can express. An output's
     * `variants` are one video codec's ABR ladder, and its `audio` block is a single `audio_codec`
     * applied to every source track — what varies per audio stream is the language and the channel
     * layout ({@see CreateVideoStreamsService::getOrCreateAudioStreams}). A second codec of either
     * kind is a second output. The streams are still scanned rather than the first one read, so a
     * row that somehow disagrees surfaces here instead of being silently represented by whichever
     * stream happened to sort first.
     */
    private function codecFamily(string $type): ?string
    {
        $catalogue = collect(config('ffmpeg.codecs'));

        $families = $this->streams
            ->where('type', $type)
            ->map(fn (Stream $stream) => $catalogue->firstWhere('codec', $this->streamCodec($stream))['family'] ?? null)
            ->filter()
            ->unique()
            ->values();

        if ($families->count() > 1) {
            // Nothing can produce this today, and if something ever does, the output's formats are
            // already the intersection of the codecs' protocols ({@see computedFormats()}) — so a
            // single reported family would name a codec the manifests do not all use.
            Log::warning("Output {$this->ulid} carries several {$type} codecs: ".$families->implode(', '));
        }

        return $families->first();
    }

    private function streamCodec(Stream $stream): ?string
    {
        return match ($stream->type) {
            'video' => data_get($stream->input_params, 'video_codec'),
            'audio' => data_get($stream->input_params, 'audio_codec', 'aac'),
            default => null,
        };
    }

    /**
     * Encode progress lives in a Redis hash (field per chunk index = percent), not a DB column:
     * it's a transient UX value. Tracked per output since every rendition shares the same chunks.
     */
    private static function chunkProgressKey(int $outputId): string
    {
        return "output-progress:{$outputId}";
    }

    /** Seed one field per (chunk × rendition) at 0% so the averaged percent has a stable divisor
     *  from tick one. `$streamIds` are this output's video stream ids. */
    public function seedChunkProgress(int $chunks, array $streamIds): void
    {
        if ($chunks < 1 || empty($streamIds)) {
            return;
        }

        $key = self::chunkProgressKey($this->id);
        $seed = [];
        for ($i = 0; $i < $chunks; $i++) {
            foreach ($streamIds as $streamId) {
                $seed["{$i}:{$streamId}"] = 0;
            }
        }

        $this->bestEffort('seed', function () use ($key, $seed) {
            Redis::hmset($key, $seed);
            Redis::expire($key, 86400); // 24h
        });
    }

    /** HSET per (chunk, rendition) field, so retries are idempotent and parallel workers never
     *  clobber. The TTL is refreshed on every report so an active pipeline never loses the hash,
     *  while an abandoned one still expires (a report can't resurrect an expired key forever). */
    public function reportChunkProgress(int $chunkIndex, int $streamId, int $percent): void
    {
        $key = self::chunkProgressKey($this->id);

        $this->bestEffort('report', function () use ($key, $chunkIndex, $streamId, $percent) {
            Redis::hset($key, "{$chunkIndex}:{$streamId}", max(0, min(100, $percent)));
            Redis::expire($key, 86400);
        });
    }

    /**
     * Average percent across seeded chunks. Falls back to status once the hash is gone, so a
     * finished output reads 100 rather than 0.
     */
    public function progress(): int
    {
        // A completed output has already had its hash cleared ({@see CompletesVideo}), so the
        // round-trip below could only ever return the fallback anyway. Skipping it matters
        // because the panel polls the video list: this is one Redis call per output per tick,
        // on pages that are almost entirely finished videos.
        if ($this->status === VideoStatus::COMPLETED) {
            return 100;
        }

        $values = Redis::hvals(self::chunkProgressKey($this->id));

        if ($values === []) {
            return 0;
        }

        return (int) min(100, round(array_sum(array_map('intval', $values)) / count($values)));
    }

    public function clearChunkProgress(): void
    {
        $this->bestEffort('clear', fn () => Redis::del(self::chunkProgressKey($this->id)));
    }

    /**
     * Progress is a display value, and every write of it happens inside work that matters more: a
     * chunk's encode (the ffmpeg progress callback), the fan-out, a video closing out after its
     * packaging. Workers can sit on the far side of a WAN from Redis, where a connection drops now
     * and then — and a failed write here used to throw out of the encode it was describing,
     * killing it mid-way (about twenty chunk encodes in four days of production). So log and go
     * on: the next report rewrites the field, a missed clear expires with the hash's TTL, and a
     * missed seed only leaves the percent without its divisor until the chunks report.
     *
     * Throwable rather than RedisException: which client throws what depends on the connector.
     */
    private function bestEffort(string $write, Closure $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            Log::warning("Chunk progress {$write} failed; carrying on without it", [
                'output' => $this->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
