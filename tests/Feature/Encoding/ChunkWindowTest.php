<?php

use App\Services\ChunkPlanner;
use App\Services\ChunkTranscodeService;

// Reference: a 1080p source encoded to a 1080p rendition at 30fps holds the full 120s window.
const HD = 1920 * 1080;
const UHD = 3840 * 2160;
const UHD8K = 7680 * 4320;

/** The planner holds no state; every test builds its own rather than sharing one. */
function planner(): ChunkPlanner
{
    return new ChunkPlanner;
}

describe('windowSeconds', function () {
    it('holds the reference window at 1080p30', function () {
        expect(planner()->windowSeconds(HD, HD, 30.0))->toBe(120);
    });

    it('lengthens the window at lower frame rates', function () {
        // 120 * (30/24) = 150
        expect(planner()->windowSeconds(HD, HD, 24.0))->toBe(150);
    });

    it('shrinks the window for 4K so a single chunk still fits the timeout', function () {
        expect(planner()->windowSeconds(UHD, UHD, 30.0))->toBe(30);
    });

    it('shrinks the window for a heavy source even when the top rendition is only 1080p', function () {
        // Every chunk job decodes a window of the source, so a 4K/8K master shortens the window on
        // its own — a 1080p-capped template used to get the full 120s here and blow the chunk timeout.
        expect(planner()->windowSeconds(HD, UHD, 30.0))->toBe(75)
            ->and(planner()->windowSeconds(HD, UHD8K, 30.0))->toBe(30);
    });

    it('floors 8K at the minimum window regardless of frame rate', function () {
        expect(planner()->windowSeconds(UHD8K, UHD8K, 30.0))->toBe(8)
            ->and(planner()->windowSeconds(UHD8K, UHD8K, 60.0))->toBe(8);
    });

    it('caps the window for low-resolution renditions so chunks still parallelize', function () {
        expect(planner()->windowSeconds(854 * 480, 854 * 480, 30.0))->toBe(300);
    });

    it('assumes the source matches its heaviest rendition when its dimensions were never recorded', function () {
        expect(planner()->windowSeconds(HD, 0, 30.0))->toBe(120)
            ->and(planner()->windowSeconds(UHD, 0, 30.0))->toBe(30);
    });

    it('falls back to the reference window when nothing is known', function () {
        expect(planner()->windowSeconds(0, 0, 30.0))->toBe(120);
    });

    it('ignores an unreadable or VFR-inflated frame rate', function () {
        expect(planner()->windowSeconds(HD, HD, 0.0))->toBe(120)
            ->and(planner()->windowSeconds(HD, HD, 1000.0))->toBe(120);
    });

    it('stays within the clamp and shrinks monotonically as pixels climb', function () {
        $prev = PHP_INT_MAX;

        foreach ([854 * 480, 1280 * 720, HD, 2560 * 1440, UHD, UHD8K] as $pixels) {
            $window = planner()->windowSeconds($pixels, $pixels, 30.0);

            expect($window)->toBeGreaterThanOrEqual(8)
                ->and($window)->toBeLessThanOrEqual(300)
                ->and($window)->toBeLessThanOrEqual($prev);

            $prev = $window;
        }
    });
});

describe('encodeCost', function () {
    it('reads the reference encoder as the reference', function () {
        expect(ChunkTranscodeService::encodeCost('libx264', ['preset' => 'medium']))->toBe(1.0);
    });

    it('follows the preset ladder, which moves the cost further than the codec does', function () {
        expect(ChunkTranscodeService::encodeCost('libx264', ['preset' => 'veryslow']))->toBe(6.0)
            ->and(ChunkTranscodeService::encodeCost('libx264', ['preset' => 'ultrafast']))->toBe(0.15);
    });

    it('reads a preset off the parameter its own codec names it with', function () {
        // svtav1's rung lives in `svtav1_preset`, not `preset` — a template carrying both (a
        // leftover from an x264 variant) must not have the x264 name win.
        expect(ChunkTranscodeService::encodeCost('libsvtav1', ['svtav1_preset' => 6]))->toBe(10.0)
            ->and(ChunkTranscodeService::encodeCost('libsvtav1', ['svtav1_preset' => 6, 'preset' => 'ultrafast']))->toBe(10.0);
    });

    it('accepts a numeric rung however the template JSON stored it', function () {
        expect(ChunkTranscodeService::encodeCost('libsvtav1', ['svtav1_preset' => '8']))
            ->toEqualWithDelta(4.5, 0.0001);
    });

    it('falls back to the codec base when the preset is missing or malformed', function () {
        expect(ChunkTranscodeService::encodeCost('libsvtav1', []))->toBe(10.0)
            ->and(ChunkTranscodeService::encodeCost('libx264', ['preset' => 'nonexistent']))->toBe(1.0)
            ->and(ChunkTranscodeService::encodeCost('libx264', ['preset' => ['medium']]))->toBe(1.0);
    });

    it('falls back to the reference for a codec it has never heard of', function () {
        expect(ChunkTranscodeService::encodeCost(null))->toBe(1.0)
            ->and(ChunkTranscodeService::encodeCost('libaom-av1', ['preset' => 'medium']))->toBe(1.0);
    });

    it('leaves every hardware encoder at the reference', function () {
        foreach (['h264_qsv', 'hevc_qsv', 'av1_qsv', 'h264_nvenc', 'hevc_nvenc', 'av1_nvenc'] as $codec) {
            expect(ChunkTranscodeService::encodeCost($codec, ['qsv_preset' => 'veryslow', 'nvenc_preset' => 'p7']))
                ->toBe(1.0);
        }
    });
});

describe('windowSeconds with an encoder cost', function () {
    it('shrinks the window for the codec that made this necessary', function () {
        // The production failure: a 1080p SVT-AV1 preset-6 rendition of a 23.976fps master got the
        // same 150s window as its x264 twin and every chunk past the halfway mark blew the 480s
        // per-chunk timeout. The x264 twin completed, so its sizing must not move.
        expect(planner()->windowSeconds(HD, HD, 23.976, ChunkTranscodeService::encodeCost('libx264', ['preset' => 'medium'])))->toBe(150)
            ->and(planner()->windowSeconds(HD, HD, 23.976, ChunkTranscodeService::encodeCost('libsvtav1', ['svtav1_preset' => 6])))->toBe(15);
    });

    it('scales the window along the ladder, not just across codecs', function () {
        expect(planner()->windowSeconds(HD, HD, 30.0, ChunkTranscodeService::encodeCost('libsvtav1', ['svtav1_preset' => 8])))->toBe(27)
            ->and(planner()->windowSeconds(HD, HD, 30.0, ChunkTranscodeService::encodeCost('libx265', ['preset' => 'medium'])))->toBe(30);
    });

    it('never stretches the window for an encoder cheaper than the reference', function () {
        // A shorter window is always safe; a longer one spends the timeout headroom that the whole
        // calculation exists to protect, on a length nothing has ever run at.
        expect(planner()->windowSeconds(HD, HD, 30.0, 0.1))->toBe(120)
            ->and(planner()->windowSeconds(HD, HD, 30.0, ChunkTranscodeService::encodeCost('libsvtav1', ['svtav1_preset' => 13])))->toBe(120)
            ->and(planner()->windowSeconds(HD, HD, 30.0, ChunkTranscodeService::encodeCost('h264_nvenc', ['nvenc_preset' => 'p1'])))->toBe(120);
    });

    it('still floors a cost the window cannot absorb, and says so by clamping', function () {
        // Nothing here can make preset 0 fit the per-chunk timeout — the floor is the honest edge
        // of what window sizing can do, not a claim that the encode will finish.
        expect(planner()->windowSeconds(HD, HD, 30.0, ChunkTranscodeService::encodeCost('libsvtav1', ['svtav1_preset' => 0])))->toBe(8)
            ->and(planner()->windowSeconds(HD, HD, 30.0, ChunkTranscodeService::encodeCost('libx265', ['preset' => 'veryslow'])))->toBe(8);
    });

    it('compounds with resolution rather than replacing it', function () {
        // 4K already shortens the window on pixels alone; AV1 on top of it must not undo that.
        $cost = ChunkTranscodeService::encodeCost('libsvtav1', ['svtav1_preset' => 6]);

        expect(planner()->windowSeconds(UHD, UHD, 30.0, $cost))
            ->toBeLessThan(planner()->windowSeconds(HD, HD, 30.0, $cost));
    });
});

/** ffprobe's `packet=pts_time,flags` CSV: one "<pts_time>,<flags>" line per packet. */
function packets(array $lines): string
{
    return implode("\n", array_map(
        fn (array $packet) => sprintf('%.6f,%s', $packet[0], $packet[1] ? 'K__' : '___'),
        $lines
    ))."\n";
}

describe('windowsFromPackets', function () {
    it('counts keyframes from the start of the file, not from the container\'s clock', function () {
        // A transport stream captured off air starts hours into its own clock: 5001.4 s here.
        // Compared as they are against a duration counted from zero, every keyframe sat past the
        // end, and a 300 s video was planned as a single 300 s chunk that then timed out.
        $offset = 5001.4;
        $packets = packets([[$offset, true], [$offset + 100.0, true], [$offset + 200.0, true], [$offset + 240.5, false]]);

        expect(planner()->windowsFromPackets($packets, 300.0, 100))->toBe([[0.0, 300.0]])
            ->and(planner()->windowsFromPackets($packets, 300.0, 100, $offset))
            ->toBe([[0.0, 100.0], [100.0, 200.0], [200.0, 241.0]]);
    });

    it('treats a packet just before the container\'s start as the first frame', function () {
        // Reordering at the head can put a packet a frame ahead of the start time.
        $windows = planner()->windowsFromPackets(packets([[9.96, true], [110.0, true], [150.0, false]]), 300.0, 100, 10.0);

        expect($windows[0][0])->toBe(0.0)->and($windows[0][1])->toBe(100.0);
    });

    it('ends the last window where the video track does, not where the container does', function () {
        // The bug that failed a 1h52m MKV forever: eac3 ran 55s past the picture, so the container
        // duration asked the last chunk for video that did not exist and the truncation guard
        // rejected a complete encode on every retry.
        $windows = planner()->windowsFromPackets(
            packets([[0.0, true], [100.0, true], [200.0, true], [240.5, false]]),
            300.0,
            100,
        );

        expect($windows)->toBe([[0.0, 100.0], [100.0, 200.0], [200.0, 241.0]]);
    });

    it('keeps the container duration when the picture runs to the end', function () {
        $windows = planner()->windowsFromPackets(
            packets([[0.0, true], [100.0, true], [200.0, true], [299.8, false]]),
            300.0,
            100,
        );

        expect($windows)->toBe([[0.0, 100.0], [100.0, 200.0], [200.0, 300.0]]);
    });

    it('reads the tail off the highest pts, not the last line', function () {
        // B-frames arrive out of order.
        $windows = planner()->windowsFromPackets(
            packets([[0.0, true], [100.0, true], [140.2, false], [139.9, false]]),
            300.0,
            100,
        );

        expect($windows)->toBe([[0.0, 100.0], [100.0, 140.7]]);
    });

    it('falls back to the container duration when the probe reports no timestamps', function () {
        expect(planner()->windowsFromPackets("N/A,K__\nN/A,___\n", 300.0, 100))
            ->toBe([[0.0, 300.0]])
            ->and(planner()->windowsFromPackets('', 300.0, 100))
            ->toBe([[0.0, 300.0]]);
    });

    it('covers the picture with contiguous, non-empty windows', function () {
        $packets = [];
        for ($t = 0.0; $t < 240.0; $t += 4.0) {
            $packets[] = [$t, fmod($t, 20.0) === 0.0];
        }

        $windows = planner()->windowsFromPackets(packets($packets), 300.0, 100);
        $cursor = 0.0;

        foreach ($windows as [$start, $end]) {
            expect($start)->toBe($cursor)->and($end)->toBeGreaterThan($start);
            $cursor = $end;
        }

        expect($cursor)->toBe(236.5);
    });
});
