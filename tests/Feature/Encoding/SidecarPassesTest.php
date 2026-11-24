<?php

/**
 * A film with many audio tracks used to encode every rung of every track in one ffmpeg pass, and a
 * pass stages nothing until it ends: on a loaded node that pass overran the job twice and the video
 * failed with all of its audio lost. One pass per source track bounds each pass, and a timeout
 * costs only the track it cut.
 */

use App\Jobs\EncodeSidecarTracksJob;
use Illuminate\Database\Eloquent\Collection;

function sidecarStream(int $id, string $type, int $index, string $channels = '2')
{
    $stream = matrixStream(['channels' => $channels, 'audio_codec' => 'libopus'], $type, ['index' => $index]);
    $stream->id = $id;

    return $stream;
}

it('runs one pass per source audio track, with all of its rungs', function () {
    $passes = EncodeSidecarTracksJob::passes(new Collection([
        sidecarStream(1, 'audio', 1, '2'),
        sidecarStream(2, 'audio', 1, '6'),
        sidecarStream(3, 'audio', 2, '2'),
        sidecarStream(4, 'audio', 2, '6'),
        sidecarStream(5, 'audio', 3, '2'),
    ]));

    expect($passes)->toHaveCount(3)
        ->and($passes->map(fn ($pass) => $pass->pluck('id')->all())->values()->all())
        ->toBe([[1, 2], [3, 4], [5]]);
});

it('keeps every subtitle in a pass of its own, apart from the audio', function () {
    $passes = EncodeSidecarTracksJob::passes(new Collection([
        sidecarStream(1, 'audio', 1),
        sidecarStream(2, 'subtitle', 4),
        sidecarStream(3, 'subtitle', 5),
    ]));

    expect($passes)->toHaveCount(2)
        ->and($passes->first(fn ($pass) => $pass->first()->type === 'subtitle')->pluck('id')->all())->toBe([2, 3])
        ->and($passes->every(fn ($pass) => $pass->pluck('type')->unique()->count() === 1))->toBeTrue();
});
