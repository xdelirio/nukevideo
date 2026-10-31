<?php

/**
 * Probing an added track's source with the real ffprobe: the duration is what decides whether a
 * dub is the right file, so it must be the real one — not ffprobe's bitrate estimate.
 */

use App\Support\TrackProbe;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/trackprobe-'.bin2hex(random_bytes(4));
    mkdir($this->dir);
    $this->make = fn (string $name, string $args) => tap("{$this->dir}/{$name}", fn ($path) => Process::run(
        "ffmpeg -hide_banner -loglevel error -y -f lavfi -i \"sine=frequency=440:duration=29\" {$args} \"{$path}\""
    )->throw());
});

afterEach(fn () => Process::run(['rm', '-rf', $this->dir]));

it('measures a raw ADTS stream by its packets, not by a bitrate estimate', function () {
    $probe = app(TrackProbe::class)->probe(($this->make)('raw.aac', '-ac 2 -c:a aac -b:a 128k -f adts'), 'audio');

    expect($probe['duration'])->toEqualWithDelta(29.0, 0.1)
        ->and($probe['channels'])->toBe(2)
        // Derived from the wrong duration, so worthless as a cap on the encode.
        ->and($probe['bit_rate'])->toBeNull();
});

it('takes the container duration when the container states one', function () {
    $probe = app(TrackProbe::class)->probe(($this->make)('dub.wav', '-ac 2'), 'audio');

    expect($probe['duration'])->toEqualWithDelta(29.0, 0.01)
        ->and($probe['bit_rate'])->toBe(1411200);
});

it('reports no track for a file that is not media', function () {
    file_put_contents($empty = "{$this->dir}/empty.srt", '');
    file_put_contents($binary = "{$this->dir}/binary.srt", random_bytes(4096));

    expect(app(TrackProbe::class)->probe($empty, 'subtitle'))->toBeNull()
        ->and(app(TrackProbe::class)->probe($binary, 'subtitle'))->toBeNull();
});
