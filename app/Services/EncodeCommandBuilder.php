<?php

namespace App\Services;

use App\Models\Stream;
use App\Support\MediaSource;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class EncodeCommandBuilder
{
    /**
     * Assemble a multi-output ffmpeg command reading one `$source`, one output per stream.
     * `$gapFill` is {@see ChunkTranscodeService::buildVideoArguments()}'s.
     * Pass `$start`/`$end` for a video chunk: `-ss/-to` before `-i` plus re-encode cuts exactly
     * `[start, end)` frame-accurately (a `-c copy` extract can't — it snaps back to the previous
     * keyframe and the windows overlap). Omit them for audio/subtitle sidecars (whole source).
     *
     * `$fitDuration` fits every audio output to exactly that many seconds — silence padded onto a
     * short source, a long one cut — for a track added to a video whose timeline is already fixed
     * ({@see AddTrackService}).
     *
     * @param  Collection<int,Stream>  $streams
     * @param  array<int,string>  $outputPaths  stream id → local output path
     */
    public static function build(Collection $streams, string $source, array $outputPaths, ?float $start = null, ?float $end = null, ?array $gapFill = null, ?float $fitDuration = null): string
    {
        $types = $streams->pluck('type')->unique();

        // A subtitle output ends the whole ffmpeg session with exit 0 once its sparse text stream
        // runs dry, cutting every sibling output short without a word on stderr. Own pass, always.
        if ($types->count() > 1 && $types->contains('subtitle')) {
            throw new InvalidArgumentException('Subtitle outputs need an ffmpeg pass of their own');
        }

        $windowed = $start !== null && $end !== null;

        // Microseconds, not tenths of a millisecond: window bounds are keyframe timestamps, and at
        // four decimals an MP4 source's 1/24000-style timebase rounds to the wrong side of a frame
        // often enough to matter — neighbouring chunks then both hold it, or neither does, and
        // every such join moves the picture a frame against the single-pass audio.
        $seek = $windowed
            ? sprintf('-ss %.6f -to %.6f ', $start, $end)
            : '';

        // Decode-side flags (hwaccel / thread cap) only apply to single-rendition video jobs;
        // sidecar passes mix audio+subtitle streams and always decode in software.
        $input = $streams->count() === 1 && $streams->first()->type === 'video'
            ? (new ChunkTranscodeService($streams->first()))->inputArguments($windowed)
            : '';

        $reconnect = MediaSource::reconnectArguments($source);
        $reconnect = $reconnect === [] ? '' : implode(' ', $reconnect).' ';

        $parts = [sprintf('ffmpeg -hide_banner -y %s-i "%s"', $reconnect.$input.$seek, $source)];

        foreach ($streams as $stream) {
            $svc = new ChunkTranscodeService($stream);
            $path = $outputPaths[$stream->id];

            $parts[] = match ($stream->type) {
                'video' => sprintf('-fps_mode passthrough %s -f %s "%s"', $svc->buildVideoArguments($windowed, $gapFill), $svc->outputFormat(), $path),
                'audio' => sprintf('%s%s -movflags +faststart -f %s "%s"', $svc->buildAudioArguments(), self::fit($fitDuration), $svc->outputFormat(), $path),
                'subtitle' => sprintf('-map %s -c:s webvtt -f %s "%s"', $svc->mapTarget(), $svc->outputFormat(), $path),
            };
        }

        return implode(' ', $parts);
    }

    /** `-af apad … -t …` for {@see build}'s `$fitDuration`; nothing without one. */
    private static function fit(?float $duration): string
    {
        if ($duration === null) {
            return '';
        }

        $length = sprintf('%.3f', $duration);

        return " -af apad=whole_dur={$length} -t {$length}";
    }
}
