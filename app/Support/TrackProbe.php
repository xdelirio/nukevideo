<?php

namespace App\Support;

use App\Jobs\AddTrackJob;
use FFMpeg\FFProbe;

/**
 * What an added track's source holds ({@see AddTrackJob}). Its own class so the job can
 * be tested without a real file: tests bind a fake in the container.
 */
class TrackProbe
{
    /**
     * The first stream of `$type` in `$source` (a path or a signed URL), or null when there is none.
     *
     * @return array{channels:int,codec:?string,profile:?string,bit_rate:?int,duration:?float}|null
     */
    public function probe(string $source, string $type): ?array
    {
        $ffprobe = FFProbe::create();
        $stream = collect($ffprobe->streams($source)->all())
            ->first(fn ($stream) => $stream->get('codec_type') === $type);

        if (! $stream) {
            return null;
        }

        // The container's, else the stream's own: raw elementary streams state only one of them.
        $duration = $ffprobe->format($source)->get('duration') ?? $stream->get('duration');

        return [
            'channels' => max(1, (int) $stream->get('channels')),
            'codec' => $stream->get('codec_name'),
            'profile' => $stream->get('profile'),
            'bit_rate' => ((int) $stream->get('bit_rate')) ?: null,
            'duration' => is_numeric($duration) ? (float) $duration : null,
        ];
    }
}
