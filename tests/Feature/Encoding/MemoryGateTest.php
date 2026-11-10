<?php

use App\Support\Memory;
use Illuminate\Support\Sleep;

/**
 * The pools are sized once, at boot; the gate is what looks at the node as it is when an encode is
 * about to start. It delays, it never refuses.
 */
it('lets an encode through at once when the node has the room', function () {
    $ticks = 0;

    expect(Memory::waitFor(0.001, 90, function () use (&$ticks) {
        $ticks++;
    }))->toBeTrue()
        ->and($ticks)->toBe(0);

    Sleep::assertNeverSlept();
});

it('waits out its whole budget and then says the room never came', function () {
    $ticks = 0;

    $room = Memory::waitFor(1_000_000.0, 20, function () use (&$ticks) {
        $ticks++;
    });

    expect($room)->toBeFalse()
        ->and($ticks)->toBe(4);

    Sleep::assertSleptTimes(4);
})->skip(fn () => Memory::availableGb() === null, 'needs /proc/meminfo');
