<?php

namespace App\Support;

use App\Jobs\AddTrackJob;
use FFMpeg\FFProbe;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * What an added track's source holds ({@see AddTrackJob}). Its own class so the job can
 * be tested without a real file: tests bind a fake in the container.
 */
class TrackProbe
{
    /**
     * The first stream of `$type` in `$source` (a path or a signed URL), or null when there is none
     * or the file is not media at all (empty, corrupt, a binary renamed `.srt`).
     *
     * @return array{channels:int,codec:?string,profile:?string,bit_rate:?int,duration:?float}|null
     */
    public function probe(string $source, string $type): ?array
    {
        try {
            $ffprobe = FFProbe::create();
            $stream = collect($ffprobe->streams($source)->all())
                ->first(fn ($stream) => $stream->get('codec_type') === $type);
        } catch (Throwable) {
            return null;
        }

        if (! $stream) {
            return null;
        }

        [$duration, $estimated] = $type === 'audio' ? $this->audioDuration($source) : [null, false];

        return [
            'channels' => max(1, (int) $stream->get('channels')),
            'codec' => $stream->get('codec_name'),
            'profile' => $stream->get('profile'),
            // ffprobe derives an estimated stream's rate from the same wrong duration (38k for a
            // 128k ADTS file), and the encode is capped at the source's rate: no rate beats that.
            'bit_rate' => $estimated ? null : (((int) $stream->get('bit_rate')) ?: null),
            'duration' => $duration,
        ];
    }

    /**
     * How long the audio really is. A raw elementary stream — ADTS `.aac`, `.ac3`, `.eac3` —
     * carries no duration, and ffprobe then estimates one from the bitrate: 93.6s for a 29s ADTS
     * file, which refused a valid dub. When it says it is estimating, the end of the last packet
     * is read instead: exact, and no decoding — only packet headers.
     */
    /** @return array{0: ?float, 1: bool} the duration, and whether ffprobe had to estimate it */
    private function audioDuration(string $source): array
    {
        $format = Process::timeout(120)->run([
            'ffprobe', '-v', 'warning', '-show_entries', 'format=duration', '-of', 'csv=p=0', $source,
        ]);

        if (! str_contains($format->errorOutput(), 'Estimating duration from bitrate')) {
            $duration = trim($format->output());

            return [is_numeric($duration) ? (float) $duration : null, false];
        }

        $packets = Process::timeout(600)->run([
            'ffprobe', '-v', 'error', '-select_streams', 'a:0',
            '-show_entries', 'packet=pts_time,duration_time', '-of', 'csv=p=0', $source,
        ]);

        $last = array_map('floatval', explode(',', (string) collect(preg_split('/\R/', trim($packets->output())))->last()));

        return [$packets->successful() && ($last[0] ?? 0) > 0 ? $last[0] + ($last[1] ?? 0) : null, true];
    }
}
