<?php

namespace App\Services;

use App\Jobs\AddTrackJob;
use App\Models\Output;
use App\Models\Stream;
use App\Models\Video;

/**
 * How an audio track added after the video completed is encoded, output by output
 * ({@see AddTrackJob}).
 *
 * The source of truth is the template version the video was ingested with
 * ({@see Video::encodingQuery}), through the same ladder and bitrate cap as ingest
 * ({@see CreateVideoStreamsService::audioRungs}), so a dub sounds like the tracks it sits next to;
 * an edit to the template since changes nothing here. The output's own existing audio still wins
 * when the definition names a different codec family than the output serves — possible only for a
 * video encoded before versions existed, whose backfilled version is the template as it stood
 * then — because an HLS `AUDIO` group cannot mix codecs its variants never declared.
 */
class TrackParamsResolver
{
    /**
     * One entry per (output × rung). `formats` is what the track can actually be grafted into:
     * the output's packaged formats that the codec supports — an Opus track never reaches an HLS
     * master. Outputs that end up with nothing are left out.
     *
     * @return list<array{output: Output, channels: int, params: array<string, mixed>, formats: list<string>}>
     */
    public function forAudio(Video $video, int $sourceChannels, ?string $sourceCodec, ?string $sourceProfile, ?int $sourceRate): array
    {
        $video->loadMissing(['templateVersion', 'template', 'outputs.streams']);
        $entries = [];

        foreach ($video->outputs->sortBy('id') as $output) {
            $rungs = $this->rungsFor($video, $output, $sourceChannels, $sourceCodec, $sourceProfile, $sourceRate);

            foreach ($rungs as $channels => $params) {
                $formats = array_values(array_intersect($output->formats(), self::protocols($params['audio_codec'] ?? 'aac')));

                if ($formats !== []) {
                    $entries[] = ['output' => $output, 'channels' => $channels, 'params' => $params, 'formats' => $formats];
                }
            }
        }

        return $entries;
    }

    /** Whether an audio track could land in at least one output — the upload is refused otherwise. */
    public function canTakeAudio(Video $video): bool
    {
        return $this->forAudio($video, 2, null, null, null) !== [];
    }

    /** @return array<int, array<string, mixed>> */
    private function rungsFor(Video $video, Output $output, int $sourceChannels, ?string $sourceCodec, ?string $sourceProfile, ?int $sourceRate): array
    {
        $config = $video->encodingQuery()['outputs'][$output->template_index ?? -1]['audio'] ?? null;
        $existing = $output->streams->where('type', 'audio');

        $templateFamily = $config ? self::family($config['audio_codec'] ?? 'aac') : null;
        $outputFamily = $output->audioCodec();

        if ($config && ($outputFamily === null || $templateFamily === $outputFamily)) {
            return CreateVideoStreamsService::audioRungs($config, $sourceChannels, $sourceCodec, $sourceProfile, $sourceRate);
        }

        if ($existing->isEmpty()) {
            return [];
        }

        // The output's own ladder, as it was resolved at ingest: one rung per channel layout it
        // already serves, never upmixed past the added source. The source cap still applies.
        $ladder = $existing
            ->map(fn (Stream $stream) => collect($stream->input_params ?? [])->except('channels')->all()
                + ['channels' => (string) ($stream->channels ?? data_get($stream->input_params, 'channels', 2))])
            ->unique(fn (array $params) => $params['channels'])
            ->values();

        $shared = collect($ladder->first())->except(['channels', 'audio_bitrate'])->all();

        return CreateVideoStreamsService::audioRungs(
            [...$shared, 'channels' => $ladder->map(fn (array $params) => array_diff_key($params, $shared))->all()],
            $sourceChannels,
            $sourceCodec,
            $sourceProfile,
            $sourceRate,
        );
    }

    private static function family(string $codec): ?string
    {
        return collect(config('ffmpeg.codecs'))->firstWhere('codec', $codec)['family'] ?? null;
    }

    /** @return list<string> */
    private static function protocols(string $codec): array
    {
        return collect(config('ffmpeg.codecs'))->firstWhere('codec', $codec)['protocols'] ?? [];
    }
}
