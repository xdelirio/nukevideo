<?php

/**
 * Rate control is resolved in exactly one place ({@see ResolvesRateControl}),
 * so this covers every mode it can pick, per codec: quality mode capped by the encoder's own VBV,
 * quality mode on an encoder blind to one, QSV's QVBR steering, and pinned ABR — each asserted on
 * the command that actually reaches ffmpeg.
 */

use App\Services\ChunkTranscodeService;
use App\Services\Concerns\BuildsArguments;
use App\Services\Concerns\ResolvesRateControl;
use App\Services\QualityBitrateProbe;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

function rateArgs(array $params, array $meta = LIGHT_SOURCE, int $width = 1920, int $height = 1080): string
{
    return (new ChunkTranscodeService(matrixStream($params, meta: $meta, width: $width, height: $height)))
        ->buildVideoArguments(windowed: true);
}

describe('uncapped quality templates', function () {
    it('adds no ceiling of its own to a template that asked for none', function (string $codec) {
        $args = rateArgs(qualityTemplate($codec));

        expect($args)->toContain(QUALITY_KNOBS[$codec]['flag'])
            ->not->toContain('-maxrate')
            ->not->toContain('-bufsize');

        // nvenc's CQ needs its bitrate target zeroed; nothing else may pin an average.
        expect($args)->when(! str_ends_with($codec, '_nvenc'), fn ($args) => $args->not->toContain('-b:v'));
    })->with(array_keys(QUALITY_KNOBS));

    it('keeps av1_qsv in ICQ while the quality mode fits under the source', function (int $measured) {
        $args = rateArgs(qualityTemplate('av1_qsv'), [...LIGHT_SOURCE, 'quality_bitrate' => $measured]);

        expect($args)->toContain('-global_quality 22')
            ->toContain('-extbrc 1 -look_ahead_depth 16')
            ->not->toContain('-maxrate')
            ->not->toContain('-b:v');
    })->with([
        'well under the cap' => 700_000,
        'just under it' => 1_263_598,
        // Sample windows are the busy middle of a runtime, so a small overshoot is tolerated.
        'inside the tolerance' => 1_500_000,
    ]);
});

describe('av1_qsv, blind to a VBV', function () {
    it('falls back to capped VBR once the quality mode really outgrows the source', function () {
        $args = rateArgs(qualityTemplate('av1_qsv'), [...LIGHT_SOURCE, 'quality_bitrate' => 3_176_497]);

        // The average stays at 0.75 × the source's 1264k; the peak gets the same 3x headroom as
        // every other VBV — capped at the bare average, the heavy scenes starved here too.
        expect($args)->toContain('-b:v 948k')
            ->toContain('-maxrate 3791k')
            ->toContain('-bufsize 7582k')
            // A quality knob next to a pinned -b:v fails the encode outright on QSV, and the AV1
            // runtime rejects the BRC extensions under VBR (it writes a 0-byte file).
            ->not->toContain('-global_quality')
            ->not->toContain('-extbrc')
            ->not->toContain('-look_ahead_depth');
    });

    it('scales the ceiling to each rendition of the same ladder', function (int $width, int $height, string $expected) {
        $args = rateArgs(qualityTemplate('av1_qsv'), [...LIGHT_SOURCE, 'quality_bitrate' => 3_176_497], $width, $height);

        expect($args)->toContain($expected);
    })->with([
        '1080p' => [1920, 1080, '-b:v 948k -maxrate 3791k'],
        '720p' => [1280, 720, '-b:v 516k -maxrate 2063k'],
        '480p' => [854, 480, '-b:v 281k -maxrate 1124k'],
    ]);

    it('strips a template VBV instead of capping with it, since -maxrate would select CQP', function () {
        $args = rateArgs(qualityTemplate('av1_qsv', ['maxrate' => '4000k', 'bufsize' => '8000k']));

        expect($args)->toContain('-global_quality 22')
            ->not->toContain('-maxrate')
            ->not->toContain('-bufsize');
    });

    it('keeps a pinned ABR template, dropping only the knob the runtime refuses next to it', function () {
        $args = rateArgs(qualityTemplate('av1_qsv', [
            'constant_bitrate' => '2500k', 'maxrate' => '3000k', 'bufsize' => '6000k',
        ]), [...LIGHT_SOURCE, 'quality_bitrate' => 3_176_497]);

        expect($args)->toContain('-b:v 2500k')
            ->toContain('-maxrate 3000k')
            ->toContain('-bufsize 6000k')
            // ICQ next to a pinned -b:v is "Invalid argument"; the BRC extensions are VBR-only.
            ->not->toContain('-global_quality')
            ->not->toContain('-extbrc');
    });

    it('stays in ICQ when the source gives nothing to cap against', function (array $meta) {
        $args = rateArgs(qualityTemplate('av1_qsv'), [...$meta, 'quality_bitrate' => 3_176_497]);

        expect($args)->toContain('-global_quality 22')->not->toContain('-maxrate');
    })->with([
        'no source bitrate' => [['source_codec' => 'h264', 'source_width' => 1920, 'source_height' => 1080]],
        'no source resolution' => [['source_codec' => 'h264', 'source_bit_rate' => 1_263_599]],
        'a bitrate too low to be real' => [[...LIGHT_SOURCE, 'source_bit_rate' => 40_000]],
    ]);

    it('says so out loud rather than encoding uncapped in silence', function () {
        Process::fake();
        Log::spy();
        config(['ffmpeg.node_accel' => 'intel']);

        $meta = ['source_codec' => 'h264', 'source_width' => 1920, 'source_height' => 1080];
        $stream = matrixStream(qualityTemplate('av1_qsv'), meta: $meta, width: 1920, height: 1080);

        expect((new ChunkTranscodeService($stream))->encodesUncapped())->toBeTrue();

        (new QualityBitrateProbe($stream))->measure('/tmp/src.mkv', 6800.0);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'uncapped'))
            ->once();
    });
});

describe('encodesUncapped', function () {
    it('is true only for a VBV-blind quality mode with no source rate behind it', function (string $codec, array $meta, array $extra, bool $expected) {
        $stream = matrixStream(qualityTemplate($codec, $extra), meta: $meta, width: 1920, height: 1080);

        expect((new ChunkTranscodeService($stream))->encodesUncapped())->toBe($expected);
    })->with([
        // The prod case: av1_qsv in ICQ over a source whose rate never got read.
        'av1_qsv, no source rate' => ['av1_qsv', ['source_codec' => 'h264', 'source_width' => 1920, 'source_height' => 1080], [], true],
        'av1_qsv, source rate known' => ['av1_qsv', LIGHT_SOURCE, [], false],
        'av1_qsv, pinned average' => ['av1_qsv', ['source_codec' => 'h264'], ['constant_bitrate' => '2500k'], false],
        // Everything else carries its own VBV, so an unknown source costs precision, not the ceiling.
        'libsvtav1, no source rate' => ['libsvtav1', ['source_codec' => 'h264'], ['maxrate' => '4000k'], false],
        'h264_qsv, no source rate' => ['h264_qsv', ['source_codec' => 'h264'], [], false],
    ]);
});

describe('encoders that cap themselves', function () {
    // LIGHT_SOURCE averages 1264k, so its peak ceiling is three times that: 3791k.
    it('tightens a template VBV to the source peak ceiling', function (string $codec) {
        $args = rateArgs(qualityTemplate($codec, ['maxrate' => '8000k', 'bufsize' => '16000k']));

        expect($args)->toContain('-maxrate 3791k')->toContain('-bufsize 7582k');
    })->with(['libx264', 'libx265', 'libsvtav1', 'h264_nvenc', 'av1_nvenc']);

    it('never caps a peak at the source average, which starves every scene above it', function () {
        // Video 6275: a 1.8 Mbps H.264 source that spends 3.2-3.6 Mbps through its heavy scenes.
        // Clamped to the bare average (1807k/3615k), the AV1 rendition sat at ~2 Mbps there and
        // blocked visibly; the peak ceiling leaves those scenes their bits.
        $args = rateArgs(
            qualityTemplate('libsvtav1', ['maxrate' => '7000k', 'bufsize' => '14000k']),
            [...LIGHT_SOURCE, 'source_bit_rate' => 1_807_276],
        );

        expect($args)->toContain('-maxrate 5422k')
            ->toContain('-bufsize 10844k')
            ->not->toContain('-maxrate 1807k');
    });

    it('never raises a template VBV to the peak ceiling', function () {
        expect(rateArgs(qualityTemplate('libsvtav1', ['maxrate' => '3000k', 'bufsize' => '6000k'])))
            ->toContain('-maxrate 3000k')->toContain('-bufsize 6000k');
    });

    it('scales that ceiling sublinearly for a downscaled rendition', function () {
        // (1280*720 / 1920*1080)^0.75 ≈ 0.544 → a 688k average, a 2063k peak
        expect(rateArgs(qualityTemplate('libsvtav1', ['maxrate' => '8000k', 'bufsize' => '16000k']), width: 1280, height: 720))
            ->toContain('-maxrate 2063k')->toContain('-bufsize 4127k');
    });

    it('keeps a strict VBV strict while tightening it', function () {
        expect(rateArgs(qualityTemplate('libx264', ['maxrate' => '8000k', 'bufsize' => '8000k'])))
            ->toContain('-maxrate 3791k')->toContain('-bufsize 3791k');
    });

    it('leaves a template already under the source ceiling alone', function () {
        expect(rateArgs(qualityTemplate('libsvtav1', ['maxrate' => '800k', 'bufsize' => '1600k'])))
            ->toContain('-maxrate 800k')->toContain('-bufsize 1600k');
    });

    it('parses an M-suffixed template VBV when tightening it', function () {
        expect(rateArgs(qualityTemplate('libsvtav1', ['maxrate' => '8M', 'bufsize' => '16M'])))
            ->toContain('-maxrate 3791k')->toContain('-bufsize 7582k');
    });

    it('ignores a bogus sub-100k source bitrate instead of emitting a 0k ceiling', function () {
        $args = rateArgs(
            qualityTemplate('libx264', ['maxrate' => '4000k', 'bufsize' => '8000k']),
            [...LIGHT_SOURCE, 'source_bit_rate' => 800],
        );

        expect($args)->toContain('-maxrate 4000k')->not->toContain('-maxrate 0k');
    });

    it('never tightens against a source more efficient than the target codec', function () {
        $args = rateArgs(
            qualityTemplate('libx264', ['maxrate' => '4000k', 'bufsize' => '8000k']),
            [...LIGHT_SOURCE, 'source_codec' => 'av1'],
        );

        expect($args)->toContain('-maxrate 4000k')->toContain('-bufsize 8000k');
    });

    it('steers a capped QSV quality mode into QVBR with an average below the source average', function (string $codec, string $expected) {
        $args = rateArgs(qualityTemplate($codec, ['maxrate' => '8000k', 'bufsize' => '16000k']));

        // The peak goes to -maxrate; the QVBR target is an average, so it stays at 0.75 × 1264k.
        expect($args)->toContain('-maxrate 3791k')
            ->toContain($expected)
            ->toContain(QUALITY_KNOBS[$codec]['flag'])
            // QVBR is a bitrate mode: the AV1-only BRC extensions have no place in it.
            ->toContain('-mbbrc 1');
    })->with([
        ['h264_qsv', '-b:v 948k'],
        ['hevc_qsv', '-b:v 948k'],
    ]);

    it('steers QVBR off the template VBV when it is leaner than the source', function () {
        expect(rateArgs(qualityTemplate('h264_qsv', ['maxrate' => '1000k', 'bufsize' => '2000k'])))
            ->toContain('-maxrate 1000k')->toContain('-b:v 750k');
    });

    it('does not invent a QVBR average for an uncapped QSV template', function () {
        expect(rateArgs(qualityTemplate('h264_qsv')))->toContain('-global_quality 23')->not->toContain('-b:v');
    });
});

describe('ABR templates the encoder would refuse', function () {
    it('drops the CRF and the VBV for SVT-AV1, which takes a max bitrate in CRF mode only', function () {
        $args = rateArgs(qualityTemplate('libsvtav1', [
            'constant_bitrate' => '1500k', 'maxrate' => '2000k', 'bufsize' => '4000k',
        ]));

        // "Target Bitrate only supported when --rc is 1/2" / "Max Bitrate only supported with CRF
        // mode" — either aborts the encode with nothing written.
        expect($args)->toContain('-b:v 1500k')
            ->not->toContain('-crf')
            ->not->toContain('-maxrate')
            ->not->toContain('-bufsize');
    });

    it('keeps the QSV quality knob in ABR for the codecs where that pairing is QVBR', function (string $codec) {
        $args = rateArgs(qualityTemplate($codec, [
            'constant_bitrate' => '1500k', 'maxrate' => '2000k', 'bufsize' => '4000k',
        ]));

        expect($args)->toContain(QUALITY_KNOBS[$codec]['flag'])
            ->toContain('-b:v 1500k')
            ->toContain('-maxrate 2000k');
    })->with(['h264_qsv', 'hevc_qsv']);

    it('keeps the nvenc quality target in ABR, where it reads as VBR quality', function (string $codec) {
        $args = rateArgs(qualityTemplate($codec, [
            'constant_bitrate' => '1500k', 'maxrate' => '2000k', 'bufsize' => '4000k',
        ]));

        expect($args)->toContain(QUALITY_KNOBS[$codec]['flag'])
            ->toContain('-b:v 1500k')
            // -b:v 0 exists to free CQ from nvenc's default target; a pinned average replaces it.
            ->not->toContain('-b:v 0');
    })->with(['h264_nvenc', 'av1_nvenc']);

    it('leaves the x264/x265 pairing alone, where the encoder resolves it without erroring', function (string $codec) {
        $args = rateArgs(qualityTemplate($codec, ['constant_bitrate' => '1500k', 'maxrate' => '2000k']));

        expect($args)->toContain(QUALITY_KNOBS[$codec]['flag'])->toContain('-b:v 1500k');
    })->with(['libx264', 'libx265']);
});

describe('every resolved mode is a well-formed command', function () {
    it('emits no duplicate or foreign flag in any rate-control mode', function (array $params, array $meta) {
        $args = rateArgs($params, $meta);
        $flags = matrixFlags($args);

        expect(array_diff_assoc($flags, array_unique($flags)))->toBe([]);
        expect(array_diff($flags, matrixAllowedFlags($params['video_codec'], 'video')))->toBe([]);
    })->with(function () {
        $cases = [];

        foreach (array_keys(QUALITY_KNOBS) as $codec) {
            $cases["{$codec} quality"] = [qualityTemplate($codec), LIGHT_SOURCE];
            $cases["{$codec} capped"] = [qualityTemplate($codec, ['maxrate' => '4000k', 'bufsize' => '8000k']), LIGHT_SOURCE];
            $cases["{$codec} abr"] = [
                qualityTemplate($codec, ['constant_bitrate' => '2500k', 'maxrate' => '3000k', 'bufsize' => '6000k']),
                LIGHT_SOURCE,
            ];
            $cases["{$codec} measured overshoot"] = [
                qualityTemplate($codec),
                [...LIGHT_SOURCE, 'quality_bitrate' => 3_176_497],
            ];
        }

        return $cases;
    });
});

describe('the probe that feeds the ceiling', function () {
    beforeEach(fn () => config(['ffmpeg.node_accel' => 'intel']));

    it('never encodes a sample on a node without the encoder hardware', function (?string $nodeAccel) {
        Process::fake();
        config(['ffmpeg.node_accel' => $nodeAccel]);

        $stream = matrixStream(qualityTemplate('av1_qsv'), meta: LIGHT_SOURCE, width: 1920, height: 1080);

        (new QualityBitrateProbe($stream))->measure('/tmp/src.mkv', 6800.0);

        Process::assertNothingRan();
        expect($stream->meta)->not->toHaveKey('quality_bitrate');
    })->with(['CPU-only node' => null, 'the other GPU family' => 'nvidia']);

    it('runs the real chunk command, hardware path included', function () {
        Process::fake();

        (new QualityBitrateProbe(matrixStream(qualityTemplate('av1_qsv'), meta: LIGHT_SOURCE, width: 1920, height: 1080)))
            ->measure('/tmp/src.mkv', 6800.0);

        Process::assertRan(fn ($process) => str_contains($process->command, '-hwaccel qsv -hwaccel_output_format qsv')
            && str_contains($process->command, '-c:v av1_qsv')
            && str_contains($process->command, '-global_quality 22')
            && str_contains($process->command, '-fps_mode passthrough'));
    });

    it('never runs where the encoder caps itself', function (string $codec) {
        Process::fake();

        (new QualityBitrateProbe(matrixStream(qualityTemplate($codec), meta: LIGHT_SOURCE, width: 1920, height: 1080)))
            ->measure('/tmp/src.mkv', 6800.0);

        Process::assertNothingRan();
    })->with(['libsvtav1', 'libx264', 'h264_qsv', 'av1_nvenc']);

    it('never runs twice for the same stream, nor on a clip too short to sample', function (array $meta, float $duration) {
        Process::fake();

        (new QualityBitrateProbe(matrixStream(qualityTemplate('av1_qsv'), meta: $meta, width: 1920, height: 1080)))
            ->measure('/tmp/src.mkv', $duration);

        Process::assertNothingRan();
    })->with([
        'already measured' => [[...LIGHT_SOURCE, 'quality_bitrate' => 3_176_497], 6800.0],
        'too short' => [LIGHT_SOURCE, 60.0],
    ]);

    it('leaves the stream unmeasured when every sample fails, which reads as uncapped ICQ', function () {
        Process::fake(['*' => Process::result(exitCode: 1)]);

        $stream = matrixStream(qualityTemplate('av1_qsv'), meta: LIGHT_SOURCE, width: 1920, height: 1080);

        (new QualityBitrateProbe($stream))->measure('/tmp/src.mkv', 6800.0);

        expect($stream->meta)->not->toHaveKey('quality_bitrate')
            ->and((new ChunkTranscodeService($stream))->buildVideoArguments(windowed: true))
            ->toContain('-global_quality 22')->not->toContain('-maxrate');
    });
});

describe('needsQualityBitrateProbe', function () {
    it('asks for a measurement only where the encoder honours no VBV', function (string $codec, bool $expected) {
        $stream = matrixStream(qualityTemplate($codec), meta: LIGHT_SOURCE, width: 1920, height: 1080);

        expect((new ChunkTranscodeService($stream))->needsQualityBitrateProbe())->toBe($expected);
    })->with([
        ['av1_qsv', true],
        ['libsvtav1', false],
        ['libx264', false],
        ['h264_qsv', false],
        ['av1_nvenc', false],
    ]);

    it('does not ask for one when there is no ceiling to compare against, or no quality mode', function (array $params, array $meta) {
        $stream = matrixStream($params, meta: $meta, width: 1920, height: 1080);

        expect((new ChunkTranscodeService($stream))->needsQualityBitrateProbe())->toBeFalse();
    })->with([
        'pinned ABR' => [['video_codec' => 'av1_qsv', 'constant_bitrate' => '2500k'], LIGHT_SOURCE],
        'unknown source bitrate' => [['video_codec' => 'av1_qsv', 'qsv_global_quality' => 22], ['source_codec' => 'h264', 'source_width' => 1920, 'source_height' => 1080]],
    ]);
});

describe('bitrate suffixes', function () {
    it('hands ffmpeg a mega suffix it reads as mega', function () {
        // To ffmpeg a lowercase `m` is milli: `-maxrate 6m` is no cap at all. The catalogue's
        // rule accepts either case, and the rest of the app reads both as mega.
        $harness = new class
        {
            use BuildsArguments;

            public function render(string $template, string $value): string
            {
                $args = [];
                $this->appendArgument($args, ['template' => $template], $value);

                return $args[0];
            }
        };

        expect($harness->render('-maxrate %s', '6m'))->toBe('-maxrate 6M')
            ->and($harness->render('-bufsize %s', '12m'))->toBe('-bufsize 12M')
            ->and($harness->render('-b:v %s', '6M'))->toBe('-b:v 6M')
            ->and($harness->render('-maxrate %s', '6000k'))->toBe('-maxrate 6000k')
            // Not a bitrate: a value that merely ends in `m` is left alone.
            ->and($harness->render('-profile:v %s', 'm'))->toBe('-profile:v m');
    });
});
