<?php

namespace App\Services;

use App\Jobs\PrepareVideoJob;
use App\Models\Video;
use App\Services\Concerns\DetectsStreamCopy;
use App\Services\Concerns\ResolvesRateControl;
use App\Support\Bitrate;
use App\Support\MediaSource;
use Exception;
use FFMpeg\FFProbe;
use FFMpeg\FFProbe\DataMapping\Format;
use FFMpeg\FFProbe\DataMapping\Stream as FFStream;
use FFMpeg\FFProbe\DataMapping\StreamCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Intl\Languages;

/**
 * Probes the local source file (downloaded by {@see PrepareVideoJob}, never main S3) and
 * creates the video's rendition, audio and subtitle streams, filling the probe-derived fields left
 * empty at ingestion.
 */
class CreateVideoStreamsService
{
    use Concerns\ResolvesScale;

    /** Subtitle codecs convertible to webvtt; bitmap formats (PGS, DVD/DVB, xsub) are skipped. */
    private const TEXT_SUBTITLE_CODECS = ['subrip', 'srt', 'ass', 'ssa', 'webvtt', 'mov_text', 'text'];

    /** How far back from the container's end a track's last packet is looked for before scanning the whole file. */
    private const TAIL_PROBE_SECONDS = 300.0;

    private string $localPath = '';

    /** Where the source's own timestamps start ({@see MediaSource::startTime}); read once. */
    private ?float $sourceStartTime = null;

    private float $containerDuration = 0.0;

    /** Where each audio track's packets stop, keyed by ffprobe stream index. */
    private array $trackEnds = [];

    private array $videoStreamCache = [];

    private array $audioStreamCache = [];

    /** Track names already used in this video, bucketed per codec type, to keep each label unique within its type. */
    private array $usedNames = [];

    /** Per-track metadata from the mkvmerge header probe, keyed by track index (== ffprobe stream index):
     *  `[index => ['language' => ?string BCP-47, 'name' => ?string]]`. Empty for non-Matroska sources. */
    private array $trackMeta = [];

    /** Fallback for {@see sourceBitRate} when the video track states no rate of its own. */
    private int $containerVideoBitRate = 0;

    public function handle(Video $video, string $localPath): void
    {
        // The version pinned at ingest, never the template as edited since: a retry or re-probe
        // must encode what the first run did.
        $outputs = $video->encodingQuery()['outputs'] ?? [];

        if (empty($outputs)) {
            throw new Exception("No outputs configured for template {$video->template?->ulid}");
        }

        // Before anything opens it: a playlist is read by following what it lists.
        if (MediaSource::isPlaylist(MediaSource::head($localPath))) {
            throw new Exception("The source of video {$video->ulid} is a playlist, not a media file.");
        }

        $this->trackMeta = $this->probeContainerTracks($localPath);

        $mediaInfo = $this->probeMedia($video, $localPath);

        $streamCollection = $mediaInfo['streamCollection'];

        $this->localPath = $localPath;
        $this->containerDuration = (float) $mediaInfo['duration'];
        $this->trackEnds = [];

        $this->validateSourceVideo($streamCollection->videos()->first());

        foreach ($streamCollection->audios() as $audio) {
            $this->validateSourceAudio($audio);

            // Probed here, before the write transaction: the local source is only around during
            // this job, and one probe per track serves however many renditions reuse it.
            $index = (int) $audio->get('index');
            $this->trackEnds[$index] = $this->trackEnd($index);
        }

        DB::transaction(function () use ($video, $streamCollection, $outputs, $mediaInfo) {
            $this->createOutputsAndStreams($video, $streamCollection, $outputs);

            $video->update([
                'duration' => $mediaInfo['duration'],
                'aspect_ratio' => $mediaInfo['aspectRatio'],
            ]);
        });
    }

    private function probeMedia(Video $video, string $localPath): array
    {
        $ffprobe = FFProbe::create();

        // Probe once and reuse the collection: each streams() call re-parses the file.
        $streamCollection = $ffprobe->streams($localPath);

        $videoStream = $streamCollection->videos()->first();

        if ($videoStream?->get('codec_type') != 'video') {
            throw new Exception("Invalid video file for video {$video->ulid}");
        }

        $format = $ffprobe->format($localPath);

        $duration = $format->get('duration')
            ?? $videoStream->get('duration');

        // A duration-less source would only die much later (the segment planner yields no
        // windows); reject it here where the error is attributable to the file.
        if (! is_numeric($duration) || (float) $duration <= 0) {
            throw new Exception("Could not determine a positive duration for video {$video->ulid}");
        }

        $this->containerVideoBitRate = $this->containerVideoBitRate($format, $streamCollection);

        return [
            'streamCollection' => $streamCollection,
            'duration' => $duration,
            // ffprobe's display_aspect_ratio is computed from the coded frame and ignores the
            // display matrix, so a rotated source reports 16:9 for a picture that shows 9:16.
            // Derive it from the display dimensions whenever the frame is turned.
            'aspectRatio' => $this->sourceAspectRatio($videoStream),
        ];
    }

    /**
     * What the container spends on video, for sources whose video track states no rate of its own.
     * The container's rate covers every track, so every other track that states one is discounted —
     * in its `bit_rate` or, in a Matroska that went through mkvmerge, only in its BPS tag, which this
     * used to miss: a remux carrying 384k of 5.1 audio read as 1.71 Mbps of video for 1.2 real, and
     * every ceiling scaled from it let renditions outweigh the source unnoticed. What remains still
     * bounds the video from above rather than measuring it, which is all its readers ask of it — a
     * ceiling. Zero when the container states no rate either.
     */
    private function containerVideoBitRate(Format $format, StreamCollection $streams): int
    {
        $containerRate = $format->get('bit_rate');

        if (! is_numeric($containerRate)) {
            return 0;
        }

        $video = $streams->videos()->first();
        $stated = 0;

        foreach ($streams as $stream) {
            if ($stream->get('index') !== $video?->get('index')) {
                $stated += self::statedBitRate($stream) ?? 0;
            }
        }

        return max(0, (int) $containerRate - $stated);
    }

    /**
     * A track's own rate: its `bit_rate`, else an mkvmerge BPS tag. A stated 0 is no statement, so
     * it falls through like a missing one instead of reading as a track that costs nothing.
     */
    private static function statedBitRate(FFStream $stream): ?int
    {
        $bitRate = $stream->get('bit_rate');

        if (is_numeric($bitRate) && (int) $bitRate > 0) {
            return (int) $bitRate;
        }

        foreach (($stream->get('tags') ?? []) as $tag => $value) {
            if (stripos((string) $tag, 'BPS') === 0 && is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * ffprobe only surfaces the ISO 639-2 language and drops the BCP-47 region (`es-419`/`pt-PT`),
     * so probe the container with mkvmerge for the richer `language_ietf`. Returns an empty map for
     * non-Matroska inputs (MP4 carries no BCP-47) or any failure — callers fall back to ffprobe tags.
     *
     * @return array<int, array{language: ?string, name: ?string}>
     */
    private function probeContainerTracks(string $localPath): array
    {
        try {
            $result = Process::timeout(60)->run(['mkvmerge', '-J', $localPath]);

            $map = [];

            foreach (data_get(json_decode($result->output(), true), 'tracks', []) as $track) {
                $props = $track['properties'] ?? [];

                $map[(int) $track['id']] = [
                    // language_ietf carries the region (es-419); mkvmerge derives it from the ISO code
                    // when absent, so it's always the richest form. Blank/null for MP4.
                    'language' => $props['language_ietf'] ?? $props['language'] ?? null,
                    'name' => $props['track_name'] ?? null,
                ];
            }

            return $map;
        } catch (\Throwable $e) {
            Log::warning('mkvmerge probe failed; falling back to ffprobe language tags', ['error' => $e->getMessage()]);

            return [];
        }
    }

    private function validateSourceVideo(?FFStream $source): void
    {
        if (! $source) {
            throw new Exception('Video stream not found in source media');
        }

        $width = $source->get('width');
        $height = $source->get('height');
        $codec = $source->get('codec_name');

        if (! $width || ! is_numeric($width) || (int) $width <= 0) {
            throw new Exception("Invalid source video width: {$width}");
        }

        if (! $height || ! is_numeric($height) || (int) $height <= 0) {
            throw new Exception("Invalid source video height: {$height}");
        }

        if (! $codec || ! is_string($codec)) {
            throw new Exception('Source video codec not detected');
        }
    }

    private function validateSourceAudio(?FFStream $source): void
    {
        if (! $source) {
            return;
        }

        $codec = $source->get('codec_name');

        if (! $codec || ! is_string($codec)) {
            throw new Exception('Source audio codec not detected');
        }
    }

    private function createOutputsAndStreams(Video $video, StreamCollection $streamCollection, array $outputs): void
    {
        $this->videoStreamCache = [];
        $this->audioStreamCache = [];
        $this->usedNames = [];

        foreach ($outputs as $index => $outputConfig) {
            $this->createRenditionOutput($video, $streamCollection, $outputConfig, $index);
        }

        $this->createSubtitleStreams($video, $streamCollection->all());
    }

    private function createRenditionOutput(
        Video $video,
        StreamCollection $streamCollection,
        array $outputConfig,
        int $index,
    ): void {
        // The index is what lets a track added after completion find this output's audio block
        // again ({@see TrackParamsResolver}).
        $output = $video->outputs()->create(['template_index' => $index]);
        $sourceVideo = $streamCollection->videos()->first();

        $videoIds = $this->resolveVideoStreams(
            $video,
            $sourceVideo,
            $outputConfig['variants'] ?? [],
            $outputConfig['video_codec'] ?? null,
        );

        // A misconfigured template (e.g. an empty/malformed `variants` list) would otherwise attach
        // zero video streams; PackageVideoJob only discovers that deep into packaging, as an empty
        // input list to shaka-packager. Fail loudly here instead.
        if (empty($videoIds)) {
            throw new Exception("Output {$index} resolved no video renditions from template {$video->template?->ulid}");
        }

        $audioConfig = $outputConfig['audio'] ?? [];
        $audioIds = $this->getOrCreateAudioStreams($video, $streamCollection, $audioConfig);

        $output->streams()->attach([...$videoIds, ...$audioIds]);
    }

    /**
     * Which template variants THIS source gets, decided by what each would actually produce.
     * {@see ResolvesScale::resolveOutputDimensions} never upscales, so comparing nominal sizes was
     * both redundant as an upscale guard and wrong at the edges: a real 4K master is rarely 3840
     * exactly (cinema crops — 3832x1596 — run a few pixels short) and lost its 4K rung to that
     * shortfall. Instead, resolve every variant against the source and keep one per DISTINCT
     * output size; among variants that would produce the same pixels, the nominally smallest wins —
     * its quality knobs are the ones tuned for that resolution. Template order is preserved.
     */
    private function filterVariants(FFStream $sourceVideo, array $variants): array
    {
        [$sourceWidth, $sourceHeight] = $this->displayDimensions($sourceVideo);

        $bySize = collect($variants)
            ->sortBy(fn (array $variant) => max($variant['width'] ?? 0, $variant['height'] ?? 0));

        $seen = [];
        $keptKeys = [];

        foreach ($bySize as $key => $variant) {
            [$width, $height] = $this->resolveOutputDimensions($variant, $sourceWidth, $sourceHeight);

            if (isset($seen["{$width}x{$height}"])) {
                continue;
            }

            $seen["{$width}x{$height}"] = true;
            $keptKeys[$key] = true;
        }

        return array_values(array_intersect_key($variants, $keptKeys));
    }

    private function resolveVideoStreams(Video $video, FFStream $sourceVideo, array $variants, ?string $videoCodec): array
    {
        $streamIds = [];

        $derivedGop = $this->deriveGopSize($this->sourceFrameRate($sourceVideo));

        $ladder = array_map(function (array $variantConfig) use ($videoCodec, $derivedGop) {
            // The output's codec wins. It is the one the template was validated against and the
            // one the video was routed to hardware by; a variant carrying its own (the rules now
            // refuse it, but stored templates are not re-validated) would be encoded as something
            // neither had seen. Only a template from before the codec moved to the output, which
            // has none there, keeps what its variants say.
            $variantConfig = $videoCodec === null
                ? $variantConfig
                : array_merge($variantConfig, ['video_codec' => $videoCodec]);

            if (empty($variantConfig['gop_size']) && $derivedGop) {
                $variantConfig['gop_size'] = $derivedGop;
            }

            return $variantConfig;
        }, $this->filterVariants($sourceVideo, $variants));

        $toneMap = $this->tonesMapOutput($sourceVideo, $ladder);

        foreach ($ladder as $variantConfig) {
            // Part of the identity: the same parameters tone-mapped in one output and kept HDR in
            // another are two different encodes, and must not be shared.
            $key = self::streamSignature($variantConfig).($toneMap ? ':tone-map' : '');

            if (! isset($this->videoStreamCache[$key])) {
                $stream = $this->createStream(
                    video: $video,
                    stream: $sourceVideo,
                    codecType: 'video',
                    inputParams: $variantConfig,
                    toneMap: $toneMap,
                );
                $this->videoStreamCache[$key] = $stream->id;
            }

            $streamIds[] = $this->videoStreamCache[$key];
        }

        return $streamIds;
    }

    /**
     * Whether an output maps an HDR source down to SDR: all of its renditions or none. HLS and
     * DASH both let a ladder mix dynamic ranges, but a player switching between them does it
     * mid-playback — the picture visibly jumps in brightness on every quality change, and shaka
     * does not filter by range the way AVPlayer does. So an output stays HDR only when every rung
     * can carry it ({@see ChunkTranscodeService::carriesHdr}); one 8-bit or H.264 rung tone-maps
     * the whole ladder. An output that wants both is two outputs: a 10-bit HEVC/AV1 one for HDR
     * screens, an H.264 one for everything else.
     */
    private function tonesMapOutput(FFStream $sourceVideo, array $ladder): bool
    {
        if (! ChunkTranscodeService::isHdrTransfer($sourceVideo->get('color_transfer'))) {
            return false;
        }

        $sourceFormat = $sourceVideo->get('pix_fmt');

        return ! collect($ladder)->every(fn (array $params) => ChunkTranscodeService::carriesHdr($params, $sourceFormat));
    }

    /**
     * One audio rendition per (source track × distinct resolved channel count). The template's
     * `channels` list is a LADDER, not a lookup: each entry resolves against the track — clamped
     * to the track's own channels, never upmixed, mirroring {@see filterVariants} — and entries
     * that resolve to the same count collapse into the nominally smallest, whose bitrate was tuned
     * for that layout. A 5.1 source with a [stereo, 5.1] template yields both (the stereo one a
     * downmix, `-ac 2`); a stereo source yields only its stereo. The old semantics matched entries
     * to tracks by exact channel count, so a 5.1-only source silently produced no stereo at all.
     */
    private function getOrCreateAudioStreams(Video $video, StreamCollection $streamCollection, array $audioConfig): array
    {
        $streamIds = [];

        foreach ($streamCollection->audios() as $stream) {
            $rungs = self::audioRungs(
                $audioConfig,
                max(1, (int) $stream->get('channels')),
                $stream->get('codec_name'),
                $stream->get('profile'),
                self::statedBitRate($stream),
            );

            foreach ($rungs as $channels => $inputParams) {
                $key = $stream->get('index').':'.self::streamSignature($inputParams);

                if (! isset($this->audioStreamCache[$key])) {
                    $created = $this->createStream(
                        video: $video,
                        stream: $stream,
                        codecType: $stream->get('codec_type'),
                        inputParams: $inputParams,
                        // Only when the track splits into several rungs: the layout is what tells
                        // "Español" apart from "Español" in every player menu (and keeps the
                        // packager from merging same-language same-label sets).
                        nameSuffix: count($rungs) > 1 ? ' ('.self::channelLayoutLabel($channels).')' : '',
                    );
                    $this->audioStreamCache[$key] = $created->id;
                }

                $streamIds[] = $this->audioStreamCache[$key];
            }
        }

        return $streamIds;
    }

    /**
     * The resolved encode parameters of every rung one source track gets from a template's `audio`
     * block, keyed by resolved channel count ({@see getOrCreateAudioStreams} for the ladder's
     * semantics). Public because a track added after the video completed goes through the very same
     * resolution ({@see TrackParamsResolver}).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function audioRungs(array $audioConfig, int $sourceChannels, ?string $sourceCodec, ?string $sourceProfile, ?int $sourceRate): array
    {
        $ladder = collect($audioConfig['channels'] ?? [])
            ->sortBy(fn (array $config) => (int) ($config['channels'] ?? PHP_INT_MAX))
            ->values();
        $sharedAudioParams = collect($audioConfig)->except('channels')->toArray();
        $sourceChannels = max(1, $sourceChannels);

        // Keyed by resolved count: the first (nominally smallest) entry wins each rung.
        $rungs = [];
        foreach ($ladder as $config) {
            $channels = min((int) ($config['channels'] ?? 0) ?: $sourceChannels, $sourceChannels);
            $rungs[$channels] ??= $config;
        }

        // No cap against a source more efficient than the target: HE-AAC or Opus matched
        // bit for bit in AAC-LC would sound worse than what came in, the same reason the video
        // ceiling skips a source codec that outranks the target's.
        $cap = self::audioCodecRank($sourceCodec, $sourceProfile)
            > self::audioCodecRank($sharedAudioParams['audio_codec'] ?? null, $sharedAudioParams['audio_profile'] ?? null)
            ? null
            : $sourceRate;

        $resolved = [];
        foreach ($rungs as $channels => $config) {
            $resolved[$channels] = self::capAudioBitrateToSource(
                // The block's codec wins over a rung's, for the reason the output's video codec
                // wins over a variant's ({@see resolveVideoStreams}).
                array_merge(
                    $sharedAudioParams,
                    $config,
                    array_filter(['audio_codec' => $sharedAudioParams['audio_codec'] ?? null]),
                    ['channels' => (string) $channels],
                ),
                $cap,
            );
        }

        return $resolved;
    }

    /**
     * A re-encode must not outweigh its source, audio included: the template's 128k Opus over a
     * 96 kbps mono AC3 track shipped a track a third heavier than the one it came from, and a 96k
     * AAC stereo one grew by a quarter. The template's rate stands whenever the source spent as
     * much or more, and when the source states no rate at all. Only a rate-driven encode is
     * touched: AAC's `-q:a` and fdk's `-vbr` pick their own rate and ignore `-b:a`. The floor is
     * there for a bogus probe value, not a real track; nothing ships under 32k.
     */
    private static function capAudioBitrateToSource(array $params, ?int $sourceRate): array
    {
        if ($sourceRate === null || empty($params['audio_bitrate']) || isset($params['audio_vbr']) || isset($params['audio_vbr_fdk'])) {
            return $params;
        }

        $asked = (int) Bitrate::toBps($params['audio_bitrate']);
        $cap = max(self::MIN_AUDIO_BPS, $sourceRate);

        if ($asked > $cap) {
            // Down to whole kbps, so the target never lands above the source's own rate.
            $params['audio_bitrate'] = intdiv($cap, 1000).'k';
        }

        return $params;
    }

    private const MIN_AUDIO_BPS = 32_000;

    /**
     * Rough efficiency ordering of audio codecs, for a source track (ffprobe codec and profile) or
     * a target (encoder and `audio_profile`). Lossless and legacy codecs rank lowest; their rates
     * sit far above any template's anyway.
     */
    private static function audioCodecRank(?string $codec, ?string $profile): int
    {
        $profile = strtolower((string) $profile);

        return match (true) {
            in_array($codec, ['opus', 'libopus'], true) => 3,
            $codec === 'vorbis' => 2,
            // HE-AAC / HE-AACv2 / xHE-AAC as ffprobe names them, `aac_he` / `aac_he_v2` as a
            // template asks for them.
            in_array($codec, ['aac', 'libfdk_aac'], true) && str_contains($profile, 'he') => 2,
            default => 1,
        };
    }

    public static function channelLayoutLabel(int $channels): string
    {
        return match ($channels) {
            1 => 'Mono',
            2 => 'Stereo',
            6 => '5.1',
            8 => '7.1',
            default => "{$channels}ch",
        };
    }

    public static function streamSignature(array $params): string
    {
        ksort($params);

        return md5(json_encode($params));
    }

    private function createSubtitleStreams(Video $video, array $streams): void
    {
        foreach ($streams as $stream) {
            if ($stream->get('codec_type') !== 'subtitle') {
                continue;
            }

            if (! $this->isTextSubtitle($stream)) {
                Log::info('Skipping bitmap subtitle stream', [
                    'video' => $video->id,
                    'index' => $stream->get('index'),
                    'codec' => $stream->get('codec_name'),
                ]);

                continue;
            }

            if ($this->isEmptySubtitle($stream)) {
                Log::info('Skipping empty subtitle stream', [
                    'video' => $video->id,
                    'index' => $stream->get('index'),
                ]);

                continue;
            }

            $this->createStream(
                video: $video,
                stream: $stream,
                codecType: 'subtitle',
                inputParams: null,
            );
        }
    }

    private function isTextSubtitle(FFStream $stream): bool
    {
        return in_array($stream->get('codec_name'), self::TEXT_SUBTITLE_CODECS, true);
    }

    /**
     * Best language for a track: the container-probed BCP-47 (mkvmerge, e.g. `es-419`) → the ISO code
     * in ffprobe's stream tags → null. Only real ISO 639 codes survive (validated on the primary
     * subtag so `es-419` passes); `und`/`mis`/`mul`/`zxx` placeholders and nonexistent codes become
     * null, so shaka emits a valid empty `lang=` and the track is kept rather than failing the run.
     *
     * The WHOLE tag has to be shaped like a language tag, not just its first subtag. It is text
     * from the uploaded file, and it is written as it stands into the packager's comma-separated
     * stream descriptor and from there into the master playlist: `en-US,init_segment=/a/path`
     * used to pass as English and append descriptor fields of the uploader's choosing. Same
     * pattern as the edit endpoint ({@see UpdateStreamData}), `\z` included — `$` would let a
     * trailing newline through.
     */
    private function sourceLanguage(FFStream $stream): ?string
    {
        $fromContainer = $this->trackMeta[$stream->get('index')]['language'] ?? null;

        $language = ! empty($fromContainer)
            ? $fromContainer
            : (($stream->get('tags') ?? [])['language'] ?? null);

        $language = trim((string) $language);

        if (! preg_match(PackagerCommandBuilder::LANGUAGE_PATTERN, $language)) {
            return null;
        }

        $primary = strtolower(explode('-', $language)[0]);

        return Languages::exists($primary) || Languages::alpha3CodeExists($primary) ? $language : null;
    }

    /** One of ffprobe's `disposition` flags, as a boolean. */
    private function hasDisposition(FFStream $stream, string $flag): bool
    {
        return (bool) data_get($stream->get('disposition'), $flag, 0);
    }

    /**
     * Source bit rate in bps, most precise source first: Matroska carries none per stream, so fall
     * back to mkvmerge's `BPS` tag (suffixed per language, e.g. `BPS-eng`) and then to the container
     * ({@see containerVideoBitRate}). Zero only when the file states no rate anywhere.
     *
     * The container used to be rejected here as too coarse — it sums every track — which was true of
     * the only reader at the time ({@see DetectsStreamCopy}, whose fast-path is
     * unreachable anyway: video is always window-cut). It stopped being the whole story once
     * {@see ResolvesRateControl} began reading it, because there a coarse
     * number is a ceiling and zero is *no* ceiling: every MKV re-encoded uncapped.
     */
    private function sourceBitRate(FFStream $stream): int
    {
        return self::statedBitRate($stream) ?? $this->containerVideoBitRate;
    }

    /**
     * Where THIS track's own packets stop. The container duration is the longest track, so an audio
     * pass measured against it reads as truncated whenever another track outlives it — and a source
     * read that dies mid-file exits 0, so that check is the only thing standing between a half-length
     * audio track and the manifest. Null when the track can't be timed; the caller falls back.
     */
    private function trackEnd(int $index): ?float
    {
        $tail = max(0.0, $this->containerDuration - self::TAIL_PROBE_SECONDS);

        // A track ending before the tail window reads as empty; only then pay for a full scan.
        return $this->lastPacketTime($index, $tail) ?? ($tail > 0.0 ? $this->lastPacketTime($index, 0.0) : null);
    }

    private function lastPacketTime(int $index, float $from): ?float
    {
        // Packet times are the container's own, which need not start at zero — hours in, for a
        // transport stream captured off air — while `$from` and the end this returns are both
        // counted from the start of the file. Unshifted, such a source's tracks all "ended" hours
        // past its duration and the read interval started before the file did.
        $this->sourceStartTime ??= MediaSource::startTime($this->localPath);

        // Generous: the fallback path walks the whole file when a track ends before the tail window.
        $result = Process::timeout(600)->run([
            'ffprobe', '-v', 'error',
            '-select_streams', (string) $index,
            '-show_entries', 'packet=pts_time',
            '-of', 'csv=p=0',
            // From this second to EOF, so the tail is read without walking the whole file.
            '-read_intervals', sprintf('%.3f%%', $this->sourceStartTime + $from),
            $this->localPath,
        ]);

        if (! $result->successful()) {
            Log::warning('Track tail probe failed', ['index' => $index, 'error' => $result->errorOutput()]);

            return null;
        }

        $end = 0.0;

        foreach (explode("\n", trim($result->output())) as $line) {
            $line = trim($line);

            if (is_numeric($line)) {
                $end = max($end, (float) $line - $this->sourceStartTime);
            }
        }

        return $end > 0.0 ? $end : null;
    }

    /** What a derived keyframe interval aims for, before it is rounded to divide a segment. */
    private const TARGET_KEYFRAME_SECONDS = 2.0;

    /**
     * Frames between keyframes, for a template that pinned none. A GOP only means something in
     * seconds — the same 60 frames is 2.503s of a 23.976fps film and 2.4s of a 25fps broadcast — so
     * a fixed frame count drifts against `packager.segment_duration`, which the packager can only
     * honour by cutting at a keyframe. Derived the other way round: a whole number of GOPs per
     * segment, each as close to {@see TARGET_KEYFRAME_SECONDS} as the source's rate allows.
     * Null when the rate is unknown, leaving the encoder its own default.
     */
    private function deriveGopSize(float $fps): ?int
    {
        // A VFR container reporting 1000/1 is not a real rate, and taken at face value it pins a
        // GOP of thousands of frames — the packager can only cut on a keyframe, so segments would
        // stretch to a minute or more and ABR switching would be gone. Treat it as unknown, the
        // same disbelief the window planner already applies to the same number.
        if ($fps <= 0 || $fps > ChunkPlanner::MAX_FPS) {
            return null;
        }

        $segment = (float) config('packager.segment_duration');
        $gopsPerSegment = max(1, (int) round($segment / self::TARGET_KEYFRAME_SECONDS));

        return max(1, (int) round($fps * $segment / $gopsPerSegment));
    }

    /** Source frame rate, from the `avg_frame_rate` fraction ("24000/1001"). Zero when unknown. */
    private function sourceFrameRate(FFStream $stream): float
    {
        [$num, $den] = array_pad(explode('/', (string) $stream->get('avg_frame_rate')), 2, '1');

        return (float) $den > 0 ? round((float) $num / (float) $den, 3) : 0.0;
    }

    /**
     * A source subtitle track with no cues yields an empty (header-only) VTT that the packager
     * rejects with PARSER_FAILURE, so drop it at ingestion. Detected from the cue count ffprobe
     * exposes (MKV carries it as `NUMBER_OF_FRAMES` tags); when no count is available we keep the
     * track rather than guess it away.
     */
    private function isEmptySubtitle(FFStream $stream): bool
    {
        $nbFrames = $stream->get('nb_frames');

        if (is_numeric($nbFrames)) {
            return (int) $nbFrames === 0;
        }

        foreach (($stream->get('tags') ?? []) as $tag => $value) {
            if (stripos((string) $tag, 'NUMBER_OF_FRAMES') === 0 && is_numeric($value)) {
                return (int) $value === 0;
            }
        }

        return false;
    }

    /**
     * Keep each track's display name unique within its codec type: a repeated name gets a " (n)"
     * suffix so players (and the HLS subtitle group) don't collide on same-named tracks. An audio
     * and a subtitle track may share a name. Case-insensitive.
     */
    private function uniqueName(string $name, string $codecType): string
    {
        $used = &$this->usedNames[$codecType];
        $used ??= [];
        $candidate = $name;
        $n = 1;

        while (isset($used[mb_strtolower($candidate)])) {
            $candidate = $name.' ('.(++$n).')';
        }

        $used[mb_strtolower($candidate)] = true;

        return $candidate;
    }

    /**
     * Track label: the source title, else its language, else the media type.
     *
     * The title comes out of the uploaded container, so it is attacker-controlled text that ends
     * up interpolated into a shaka descriptor and from there into the HLS master playlist, which
     * is edited and written as plain text. A comma delimits descriptor fields; a quote closes the
     * `NAME="…"` attribute; a newline ends the `#EXT-X-MEDIA` line, so everything after it becomes
     * a tag of its own in the playlist every viewer downloads. {@see UpdateStreamData} already
     * refuses all three on the edit path — ingestion has to hold the same line, or the database
     * ends up with names the API itself would reject.
     */
    private function baseName(FFStream $stream, string $codecType): string
    {
        $tags = $stream->get('tags') ?? [];

        $label = $this->trackMeta[$stream->get('index')]['name']
            ?? (! empty($tags['title']) ? $tags['title'] : $this->sourceLanguage($stream));

        $label = trim((string) preg_replace('/["\r\n,]+/u', ' ', (string) $label));

        return $label !== '' ? $label : ucfirst($codecType);
    }

    private function createStream(
        Video $video,
        FFStream $stream,
        string $codecType,
        ?array $inputParams = null,
        string $nameSuffix = '',
        bool $toneMap = false,
    ) {
        $ulid = Str::ulid();
        $extension = $this->getStreamExtension($codecType, $inputParams);
        $path = "$video->ulid/$codecType/$ulid.$extension";

        [$width, $height] = $this->resolveStreamDimensions($stream, $codecType, $inputParams);

        $attributes = [
            'path' => $path,
            'type' => $codecType,
            'meta' => [
                'index' => $stream->get('index'),
                ...($codecType === 'video' ? [
                    // Display dimensions, not coded ones: every reader compares them against a
                    // rendition size, and a rendition is sized against the picture as displayed.
                    'source_height' => $this->displayDimensions($stream)[1],
                    // DetectsStreamCopy needs both dimensions for the copy fast-path.
                    'source_width' => $this->displayDimensions($stream)[0],
                    'source_codec' => $stream->get('codec_name'),
                    // GPU jobs hardware-decode only when codec AND pixel format are supported.
                    'source_pix_fmt' => $stream->get('pix_fmt'),
                    'source_bit_rate' => $this->sourceBitRate($stream),
                    // The uploaded file's size. Its `original` stream row carries it too, but that
                    // row is deleted once the video completes (unless the template keeps the
                    // original), and with it the only way to compare what shipped against what came in.
                    'source_file_size' => @filesize($this->localPath) ?: null,
                    'source_fps' => $this->sourceFrameRate($stream),
                    // The colour description, so the encode can tell an HDR picture from an SDR
                    // one and write it back out ({@see ChunkTranscodeService::colorArguments}).
                    'source_color_transfer' => $stream->get('color_transfer'),
                    'source_color_primaries' => $stream->get('color_primaries'),
                    'source_color_space' => $stream->get('color_space'),
                    'tone_map' => $toneMap,
                ] : []),
                // Accessibility dispositions; packaging turns them into DASH Role/Accessibility and
                // HLS CHARACTERISTICS ({@see PackagerCommandBuilder}), and the source track's own
                // stated rate, which bounds `audio_bitrate` unless the source's codec is the more
                // efficient one ({@see capAudioBitrateToSource}).
                ...($codecType === 'audio' ? [
                    'source_bit_rate' => self::statedBitRate($stream),
                    'hearing_impaired' => $this->hasDisposition($stream, 'hearing_impaired'),
                    'visual_impaired' => $this->hasDisposition($stream, 'visual_impaired'),
                    // What this track's encode is measured against ({@see \App\Jobs\EncodeSidecarTracksJob}).
                    'source_end' => $this->trackEnds[(int) $stream->get('index')] ?? null,
                ] : []),
                ...($codecType === 'subtitle' ? [
                    'forced' => $this->hasDisposition($stream, 'forced'),
                    'hearing_impaired' => $this->hasDisposition($stream, 'hearing_impaired'),
                ] : []),
            ],
            // A rendition carries no label anywhere — not in a manifest, not in the panel (which
            // shows its height) — so storing the source title on it only made "name" look load-bearing.
            'name' => $codecType === 'video' ? null : $this->uniqueName($this->baseName($stream, $codecType).$nameSuffix, $codecType),
            'input_params' => $inputParams,
            'width' => $width,
            'height' => $height,
        ];

        if ($codecType === 'audio') {
            $attributes['language'] = $this->sourceLanguage($stream);
            $attributes['channels'] = (int) ($inputParams['channels'] ?? $stream->get('channels'));
        }

        if ($codecType === 'subtitle') {
            $attributes['language'] = $this->sourceLanguage($stream);
            $attributes['forced'] = $this->hasDisposition($stream, 'forced');
        }

        return $video->streams()->create($attributes);
    }

    private function resolveStreamDimensions(FFStream $stream, string $codecType, ?array $inputParams): array
    {
        if ($codecType !== 'video') {
            return [$stream->get('width'), $stream->get('height')];
        }

        [$sourceWidth, $sourceHeight] = $this->displayDimensions($stream);

        return $this->resolveOutputDimensions($inputParams ?? [], $sourceWidth, $sourceHeight);
    }

    /**
     * The stored file extension for a rendition. Subtitles are always WebVTT; audio/video take
     * the container of their target codec (config/ffmpeg.php `format`) so the stored key matches
     * the muxed bytes.
     */
    private function getStreamExtension(string $codecType, ?array $inputParams): string
    {
        if ($codecType === 'subtitle') {
            return 'vtt';
        }

        $codec = $codecType === 'video'
            ? data_get($inputParams, 'video_codec')
            : data_get($inputParams, 'audio_codec', 'aac');

        return ChunkTranscodeService::formatForCodec($codec);
    }

    /**
     * The dimensions of the picture as it is displayed, which is what the ladder has to be sized
     * against. A phone shoots portrait but codes the frame landscape with a 90° display matrix,
     * and ffmpeg autorotates on decode — so the frame arriving at `-vf scale` is already portrait.
     * Sizing off the coded dimensions pinned every rendition to a landscape box and shipped the
     * whole title visibly squashed.
     *
     * @return array{0: int, 1: int}
     */
    private function displayDimensions(FFStream $stream): array
    {
        $width = (int) $stream->get('width');
        $height = (int) $stream->get('height');

        return $this->isSideways($stream) ? [$height, $width] : [$width, $height];
    }

    /**
     * The aspect ratio to advertise for the source. ffprobe's own `display_aspect_ratio` already
     * folds in a non-square sample aspect, so it is preferred — but it is blind to the display
     * matrix, so a turned frame gets the ratio computed from its display dimensions instead.
     */
    private function sourceAspectRatio(FFStream $stream): string
    {
        [$width, $height] = $this->displayDimensions($stream);

        if ($this->isSideways($stream)) {
            return $this->calculateAspectRatio($width, $height);
        }

        return $stream->get('display_aspect_ratio', $this->calculateAspectRatio($width, $height));
    }

    /** Whether the display matrix turns the coded frame a quarter turn (either direction). */
    private function isSideways(FFStream $stream): bool
    {
        $rotation = null;

        foreach ($stream->get('side_data_list') ?? [] as $sideData) {
            if (isset($sideData['rotation'])) {
                $rotation = $sideData['rotation'];
                break;
            }
        }

        // Pre-5.0 ffmpeg carried it as a container tag instead.
        $rotation ??= $stream->get('tags')['rotate'] ?? 0;

        return abs((int) round(fmod((float) $rotation, 180.0))) === 90;
    }

    private function calculateAspectRatio(int $width, int $height): string
    {
        $a = $width;
        $b = $height;
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return $width / $a.':'.$height / $a;
    }
}
