<?php

use App\Models\Stream;
use App\Support\Cpu;

describe('workerProcesses', function () {
    it('sizes the two production nodes as they were measured', function () {
        // epyc, 224 threads / 123 GB: RAM-bound. 40 encodes at 4 threads measured 79-84 GB.
        expect(Cpu::workerProcesses(224, 123.4))->toBe(40)
            // 9950X, 32 threads / 59 GB: CPU-bound. 6, 8, 10 and 12 encodes moved the same Mpix/s.
            ->and(Cpu::workerProcesses(32, 59.4))->toBe(6);
    });

    it('lets RAM win when the box has cores to spare', function () {
        // The staging failure: 30 cores would allow 6, but 17 GB only holds 4 encodes.
        expect(Cpu::workerProcesses(30, 17.0))->toBe(4);
    });

    it('lets the CPU win when the box has RAM to spare', function () {
        expect(Cpu::workerProcesses(10, 64.0))->toBe(2);
    });

    it('does not take an encode away from a small box', function () {
        // Two encodes at four threads is what an 8-core node has always run.
        expect(Cpu::workerProcesses(8, 16.0))->toBe(2);
    });

    it('still encodes on a box too small for one full budget', function () {
        expect(Cpu::workerProcesses(2, 4.0))->toBe(1)
            ->and(Cpu::workerProcesses(1, 1.0))->toBe(1);
    });

    it('never oversubscribes the RAM or the cores it was given', function () {
        foreach ([[8, 16.0], [16, 32.0], [30, 17.0], [32, 32.0], [32, 59.4], [64, 128.0], [224, 123.4]] as [$cores, $gb]) {
            $processes = Cpu::workerProcesses($cores, $gb);

            expect($processes * 2.75)->toBeLessThanOrEqual($gb * 0.9)
                ->and($processes * Cpu::threadsPerEncode($cores))->toBeLessThanOrEqual($cores);
        }
    });
});

describe('threadsPerEncode', function () {
    it('is the same on every node, whatever its size', function () {
        // SVT-AV1 clamps lp to 6 and holds more memory per thread; four is where it scales.
        expect(Cpu::threadsPerEncode(32))->toBe(4)
            ->and(Cpu::threadsPerEncode(224))->toBe(4);
    });

    it('never asks for more threads than the node has', function () {
        expect(Cpu::threadsPerEncode(2))->toBe(2)
            ->and(Cpu::threadsPerEncode(1))->toBe(1);
    });
});

describe('heavy encodes', function () {
    it('prices an encode by its picture, not by a flat slot', function () {
        // Measured: 9.3 GB for 3840x1606 at 4 threads, 3.2-3.7 GB for 1080p.
        expect(Cpu::encodeMemoryGb(3840, 1606))->toBeBetween(9.0, 9.6)
            ->and(Cpu::encodeMemoryGb(1920, 1080))->toBeBetween(3.2, 3.7)
            ->and(Cpu::encodeMemoryGb(3840, 2160))->toBeBetween(12.0, 12.8);
    });

    it('calls anything above 1080p heavy', function () {
        expect(Cpu::isHeavy(1920, 1080))->toBeFalse()
            ->and(Cpu::isHeavy(1920, 1200))->toBeFalse()
            ->and(Cpu::isHeavy(2560, 1440))->toBeTrue()
            ->and(Cpu::isHeavy(3840, 1606))->toBeTrue();
    });

    it('runs at most two at once, and what is left of the memory decides', function () {
        // The 9950X started eight 2160p encodes (74 GB) with 32 GB and again with 64.
        expect(Cpu::heavyProcesses(32, 59.4))->toBe(2)
            ->and(Cpu::heavyProcesses(32, 31.1))->toBe(1)
            ->and(Cpu::heavyProcesses(224, 123.4))->toBe(2);
    });

    it('always leaves a 2160p rung somewhere to run', function () {
        expect(Cpu::heavyProcesses(4, 8.0))->toBe(1);
    });

    it('queues a software rendition above 1080p on the heavy queue', function () {
        $uhd = new Stream(['type' => 'video', 'width' => 3840, 'height' => 2160, 'input_params' => ['video_codec' => 'libsvtav1']]);
        $hd = new Stream(['type' => 'video', 'width' => 1920, 'height' => 1080, 'input_params' => ['video_codec' => 'libsvtav1']]);
        $gpu = new Stream(['type' => 'video', 'width' => 3840, 'height' => 2160, 'input_params' => ['video_codec' => 'av1_qsv']]);

        expect($uhd->encodeQueue())->toBe('video-processing-heavy')
            ->and($hd->encodeQueue())->toBe('video-processing')
            ->and($gpu->encodeQueue())->toBe('video-processing-intel');
    });
});
