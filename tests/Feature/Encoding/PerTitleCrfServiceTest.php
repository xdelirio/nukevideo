<?php

use App\Models\Project;
use App\Models\Stream;
use App\Models\Video;
use App\Services\PerTitleCrfService;
use App\Services\SampleEncode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

describe('chooseCrf', function () {
    it('interpolates the crf that hits the target vmaf', function () {
        // 30 → 97.0, 38 → 93.5: slope -0.4375, target 94 lands at ~36.9
        expect(PerTitleCrfService::chooseCrf([30 => 97.0, 38 => 93.5], 94, 63))->toBe(37);
    });

    it('extrapolates below the base crf when the target is within reach', function () {
        // 23 → 93.0, 31 → 88.0: slope -0.625, target 94 lands 1.6 steps under the base.
        expect(PerTitleCrfService::chooseCrf([23 => 93.0, 31 => 88.0], 94, 51))->toBe(21);
    });

    it('keeps the base crf when the target is out of reach below it', function () {
        // A grainy 1080p episode: 94 extrapolates to CRF ~16, and the four steps the probe used to
        // take toward it cost 46% more bits for ~1 VMAF point, still short of the target.
        expect(PerTitleCrfService::chooseCrf([26 => 90.86, 34 => 88.37], 94, 63))->toBe(26)
            ->and(PerTitleCrfService::chooseCrf([30 => 80.0, 38 => 70.0], 94, 63))->toBe(30);
    });

    it('never extrapolates upward past the top anchor', function () {
        // Interpolation would land far above the anchors; only what was measured counts.
        expect(PerTitleCrfService::chooseCrf([30 => 99.9, 38 => 99.5], 80, 63))->toBe(38)
            // A barely sloped curve extrapolated 26 → 38 before; the top anchor is the limit.
            ->and(PerTitleCrfService::chooseCrf([26 => 94.24, 34 => 93.7], 93, 63))->toBe(34);
    });

    it('falls back to the top anchor on a saturated flat curve', function () {
        expect(PerTitleCrfService::chooseCrf([30 => 99.5, 38 => 99.4], 94, 63))->toBe(38);
    });

    it('keeps the base crf on a flat curve sitting right at the target', function () {
        // Video 6275: a starved VBV scored both anchors alike and the probe jumped to base+8.
        expect(PerTitleCrfService::chooseCrf([26 => 94.24, 34 => 94.19], 94, 63))->toBe(26)
            ->and(PerTitleCrfService::chooseCrf([26 => 94.24, 34 => 94.19], 92, 63))->toBe(26);
    });

    it('keeps the base crf on a flat curve that misses the target', function () {
        expect(PerTitleCrfService::chooseCrf([30 => 80.0, 38 => 80.1], 94, 63))->toBe(30);
    });

    it('never exceeds the codec crf ceiling', function () {
        expect(PerTitleCrfService::chooseCrf([48 => 99.0, 51 => 96.0], 90, 51))->toBe(51);
    });
});

describe('sample windows', function () {
    it('spreads the same number of windows over any runtime', function () {
        expect(SampleEncode::windows(130.0))->toHaveCount(SampleEncode::WINDOWS)
            ->and(SampleEncode::windows(1500.0))->toHaveCount(SampleEncode::WINDOWS)
            ->and(SampleEncode::windows(6800.0))->toHaveCount(SampleEncode::WINDOWS);
    });

    it('never overlaps two windows, even on the shortest runtime it samples', function () {
        $starts = SampleEncode::windows((float) SampleEncode::MIN_DURATION);

        foreach (array_slice($starts, 1) as $i => $start) {
            expect($start - $starts[$i])->toBeGreaterThanOrEqual((float) SampleEncode::SECONDS);
        }
    });

    it('keeps every window inside the runtime', function () {
        foreach ([130.0, 1500.0, 6800.0] as $duration) {
            foreach (SampleEncode::windows($duration) as $start) {
                expect($start)->toBeGreaterThanOrEqual(0.0)
                    ->and($start)->toBeLessThanOrEqual($duration - SampleEncode::SECONDS);
            }
        }
    });
});

describe('apply guards', function () {
    function perTitleStream(array $inputParams, array $meta = []): Stream
    {
        return (new Stream)->forceFill([
            'id' => 1,
            'type' => 'video',
            'width' => 1920,
            'height' => 1080,
            'input_params' => $inputParams,
            'meta' => $meta,
        ]);
    }

    it('does nothing without target_vmaf', function () {
        Process::fake();

        (new PerTitleCrfService(perTitleStream([
            'video_codec' => 'libsvtav1', 'svtav1_crf' => 30,
        ])))->apply('/tmp/src.mkv', 6800.0);

        Process::assertNothingRan();
    });

    it('does nothing in constant bitrate mode', function () {
        Process::fake();

        (new PerTitleCrfService(perTitleStream([
            'video_codec' => 'libsvtav1', 'svtav1_crf' => 30, 'target_vmaf' => 94, 'constant_bitrate' => '2500k',
        ])))->apply('/tmp/src.mkv', 6800.0);

        Process::assertNothingRan();
    });

    it('does nothing when a previous attempt already resolved the stream', function () {
        Process::fake();

        (new PerTitleCrfService(perTitleStream(
            ['video_codec' => 'libsvtav1', 'svtav1_crf' => 30, 'target_vmaf' => 94],
            ['per_title' => ['chosen_crf' => 36]],
        )))->apply('/tmp/src.mkv', 6800.0);

        Process::assertNothingRan();
    });

    it('does nothing for very short videos', function () {
        Process::fake();

        (new PerTitleCrfService(perTitleStream([
            'video_codec' => 'libsvtav1', 'svtav1_crf' => 30, 'target_vmaf' => 94,
        ])))->apply('/tmp/src.mkv', 60.0);

        Process::assertNothingRan();
    });

    it('probes copy-eligible sources too, since video chunks always re-encode', function () {
        Process::fake();

        (new PerTitleCrfService(perTitleStream(
            ['video_codec' => 'libsvtav1', 'svtav1_crf' => 30, 'target_vmaf' => 94, 'maxrate' => '3000k'],
            ['source_codec' => 'av1', 'source_bit_rate' => 2_000_000, 'source_width' => 1920, 'source_height' => 1080],
        )))->apply('/tmp/src.mkv', 6800.0);

        Process::assertRan(fn ($process) => str_contains($process->command, 'libvmaf')
            || str_contains($process->command, '-fps_mode passthrough'));
    });

    it('does nothing for a codec outside target_vmaf, even when a legacy template carries the field', function () {
        Process::fake();

        (new PerTitleCrfService(perTitleStream(
            ['video_codec' => 'av1_qsv', 'qsv_global_quality' => 22, 'target_vmaf' => 94],
            ['source_codec' => 'h264', 'source_pix_fmt' => 'yuv420p', 'source_width' => 1920, 'source_height' => 800],
        )))->apply('/tmp/src.mkv', 6800.0);

        Process::assertNothingRan();
    });

    it('does nothing when the base crf sits at the codec ceiling', function () {
        Process::fake();

        (new PerTitleCrfService(perTitleStream([
            'video_codec' => 'libsvtav1', 'svtav1_crf' => 63, 'target_vmaf' => 94,
        ])))->apply('/tmp/src.mkv', 6800.0);

        Process::assertNothingRan();
    });

    it('measures the anchors without any VBV, so both curves answer to the CRF alone', function () {
        Process::fake();

        // Video 6275's shape: a 1.8 Mbps source under a 7000k/14000k SVT-AV1 template. A VBV that
        // pins the low anchor flattens the curves; video 9078's own 6000k sent it to CRF 46.
        (new PerTitleCrfService(perTitleStream(
            ['video_codec' => 'libsvtav1', 'svtav1_crf' => 26, 'target_vmaf' => 94, 'maxrate' => '7000k', 'bufsize' => '14000k'],
            ['source_codec' => 'h264', 'source_bit_rate' => 1_807_276, 'source_width' => 1920, 'source_height' => 1080],
        )))->apply('/tmp/src.mkv', 6800.0);

        Process::assertRan(fn ($process) => str_contains($process->command, '-fps_mode passthrough')
            && str_contains($process->command, '-crf 26'));
        Process::assertDidntRun(fn ($process) => str_contains($process->command, '-maxrate')
            || str_contains($process->command, '-bufsize'));
    });

    it('does not treat a lossless crf of zero as missing', function () {
        Process::fake();

        (new PerTitleCrfService(perTitleStream([
            'video_codec' => 'libx264', 'crf' => 0, 'target_vmaf' => 94,
        ])))->apply('/tmp/src.mkv', 6800.0);

        Process::assertRan(fn ($process) => str_contains($process->command, '-fps_mode passthrough'));
    });
});

describe('capCrfToBitrate', function () {
    // Video 9059's top rendition: 1.49 Mbps H.264, VMAF chose CRF 30, the output ran 2.03 Mbps.
    $samples = [22 => 3_200_000, 30 => 2_000_000];

    it('leaves a crf alone when it already fits under the ceiling', function () use ($samples) {
        expect(PerTitleCrfService::capCrfToBitrate($samples, 30, 2_100_000, 63))->toBe(30);
    });

    it('raises the crf until the predicted bitrate fits', function () use ($samples) {
        // ln(1.788/3.2) / (ln(2.0/3.2) / 8) ≈ 9.9 over the low anchor: 31.9, rounded up to stay under.
        $crf = PerTitleCrfService::capCrfToBitrate($samples, 30, 1_788_000, 63);

        expect($crf)->toBe(32)
            ->and(PerTitleCrfService::estimateBitrate($samples, $crf))->toBeLessThanOrEqual(1_788_000.0)
            ->and(PerTitleCrfService::estimateBitrate($samples, $crf - 1))->toBeGreaterThan(1_788_000.0);
    });

    it('overrides a vmaf choice below the base when that would outweigh the source', function () use ($samples) {
        expect(PerTitleCrfService::capCrfToBitrate($samples, 18, 1_788_000, 63))->toBe(32);
    });

    it('raises past MAX_INCREASE when the source needs it, since the curve is measured', function () use ($samples) {
        // ln(1.0/3.2) / (ln(2.0/3.2) / 8) ≈ 19.8 over the low anchor.
        expect(PerTitleCrfService::capCrfToBitrate($samples, 30, 1_000_000, 63))->toBe(42);
    });

    it('never exceeds the codec crf ceiling', function () {
        expect(PerTitleCrfService::capCrfToBitrate([45 => 3_000_000, 51 => 2_500_000], 51, 500_000, 51))->toBe(51);
    });

    it('never extrapolates a curve too flat to be CRF talking', function () {
        // 30 → 2.0, 38 → 1.9 Mbps against a 1.0 Mbps ceiling would ask for CRF 138.
        expect(PerTitleCrfService::capCrfToBitrate([30 => 2_000_000, 38 => 1_900_000], 30, 1_000_000, 63))->toBe(30)
            ->and(PerTitleCrfService::estimateBitrate([30 => 2_000_000, 38 => 1_900_000], 30))->toBeNull();
    });

    it('keeps the vmaf choice when the curve is broken or missing', function (array $bitrates) {
        expect(PerTitleCrfService::capCrfToBitrate($bitrates, 30, 1_000_000, 63))->toBe(30);
    })->with([
        'rising with crf' => [[22 => 2_000_000, 30 => 2_500_000]],
        'flat' => [[22 => 2_000_000, 30 => 2_000_000]],
        'no common window' => [[]],
        'one anchor' => [[22 => 2_000_000]],
        'an empty sample' => [[22 => 0, 30 => 2_000_000]],
    ]);
});

describe('apply against the source bitrate', function () {
    beforeEach(fn () => Storage::fake('chunks'));

    /**
     * Encodes write a sample sized to `$bitrates[crf]` over SampleEncode::SECONDS; VMAF passes
     * answer `$scores[crf]`, or `$scores[crf][window]`. Both are keyed off the sample path, which carries the anchor CRF.
     */
    function fakeAnchors(array $scores, array $bitrates, int|array|null $sourceRate = null): void
    {
        Process::fake(function ($process) use ($scores, $bitrates, $sourceRate) {
            if (is_array($process->command)) {
                return fakeSourcePackets($process->command, $sourceRate);
            }

            preg_match('/pertitle_\d+_(\d+)_(\d+)\.mp4/', $process->command, $matches);
            $crf = (int) $matches[1];

            if (str_contains($process->command, 'libvmaf')) {
                // Per window when the case needs it; a null window fails its VMAF pass.
                $score = is_array($scores[$crf]) ? $scores[$crf][(int) $matches[2]] : $scores[$crf];

                return $score === null ? Process::result(exitCode: 1) : Process::result("VMAF score: {$score}");
            }

            preg_match("/'([^']+pertitle_[^']+)'\s*$/", $process->command, $path);
            file_put_contents($path[1], str_repeat('x', (int) ($bitrates[$crf] * SampleEncode::SECONDS / 8)));

            return Process::result('');
        });
    }

    /**
     * ffprobe over the source: its start_time, then one packet a second across each window at
     * `$sourceRate` bps (or `$sourceRate[window]`; a null one reads as unreadable).
     */
    function fakeSourcePackets(array $command, int|array|null $sourceRate)
    {
        if (in_array('format=start_time', $command, true)) {
            return Process::result("0.000000\n");
        }

        // The read starts a second before its window (see SampleEncode::windowBytes).
        $interval = $command[array_search('-read_intervals', $command, true) + 1];
        $start = (float) explode('%', $interval)[0] + 1;
        $window = array_search($start, array_map('floatval', SampleEncode::windows(1480.0)));
        $rate = is_array($sourceRate) ? ($sourceRate[$window] ?? null) : $sourceRate;

        if ($rate === null) {
            return Process::result(exitCode: 1);
        }

        $lines = array_map(fn (int $s) => sprintf('%.3f,%d', $start + $s, $rate / 8), range(0, SampleEncode::SECONDS - 1));

        return Process::result(implode("\n", $lines)."\n");
    }

    function persistedPerTitleStream(array $inputParams, array $meta, int $width, int $height): Stream
    {
        $video = projectVideo(Project::factory()->create());

        return $video->streams()->create([
            'path' => "{$video->ulid}/video/".Str::ulid().'.mp4',
            'type' => 'video',
            'width' => $width,
            'height' => $height,
            'input_params' => $inputParams,
            'meta' => $meta,
        ]);
    }

    // Template 7 on video 9059: SVT-AV1 10-bit, target VMAF 96, a 1.49 Mbps 1280x960 H.264 source.
    $template = ['video_codec' => 'libsvtav1', 'svtav1_crf' => 22, 'target_vmaf' => 96, 'maxrate' => '8960k', 'bufsize' => '17920k'];
    $source = ['source_codec' => 'h264', 'source_bit_rate' => 1_490_000, 'source_width' => 1280, 'source_height' => 960];

    it('raises the vmaf crf so the rendition stays under its source', function () use ($template, $source) {
        fakeAnchors([22 => 97.31, 30 => 96.16], [22 => 3_200_000, 30 => 2_000_000]);
        $stream = persistedPerTitleStream($template, $source, 1280, 960);

        (new PerTitleCrfService($stream))->apply(sys_get_temp_dir().'/src.mkv', 1480.0);

        $stream->refresh();
        // VMAF aims at 96.5, CRF 28; the cap at 0.85x the 1.49 Mbps source then asks for
        // ln(1.2665/3.2) / (ln(2.0/3.2) / 8) ≈ 15.8 over 22.
        expect($stream->meta['per_title']['vmaf_crf'])->toBe(28)
            ->and($stream->input_params['svtav1_crf'])->toBe(38)
            ->and($stream->meta['per_title']['anchor_bitrates'])->toBe([22 => 3_200_000, 30 => 2_000_000])
            ->and($stream->meta['per_title']['bitrate_target'])->toBe(1_266_500)
            ->and($stream->meta['per_title']['estimated_bitrate'])->toBeLessThanOrEqual(1_266_500)
            ->and(glob(sys_get_temp_dir().'/pertitle_*'))->toBe([]);
    });

    it('compares the samples with what the source spent over the same windows', function () use ($template, $source) {
        // The windows ran 2.0 Mbps against a 1.49 Mbps file: the samples were busy footage, and
        // measured against the file average they would have pushed the CRF to 38, not 33.
        fakeAnchors([22 => 97.31, 30 => 96.16], [22 => 3_200_000, 30 => 2_000_000], 2_000_000);
        $stream = persistedPerTitleStream($template, $source, 1280, 960);

        (new PerTitleCrfService($stream))->apply(sys_get_temp_dir().'/src.mkv', 1480.0);

        $meta = $stream->refresh()->meta['per_title'];
        expect($meta['window_source_bitrate'])->toBe(2_000_000)
            ->and($meta['bitrate_target'])->toBe(1_700_000)
            ->and($stream->input_params['svtav1_crf'])->toBe(33);
    });

    it('falls back to the file-wide average when a window of the source cannot be read', function () use ($template, $source) {
        fakeAnchors([22 => 97.31, 30 => 96.16], [22 => 3_200_000, 30 => 2_000_000], [0 => 2_000_000, 1 => null, 2 => 2_000_000]);
        $stream = persistedPerTitleStream($template, $source, 1280, 960);

        (new PerTitleCrfService($stream))->apply(sys_get_temp_dir().'/src.mkv', 1480.0);

        $meta = $stream->refresh()->meta['per_title'];
        expect($meta['window_source_bitrate'])->toBeNull()
            ->and($meta['bitrate_target'])->toBe(1_266_500)
            ->and($stream->input_params['svtav1_crf'])->toBe(38);
    });

    it('keeps the vmaf crf when the source rate is unknown', function () use ($template, $source) {
        fakeAnchors([22 => 97.31, 30 => 96.16], [22 => 3_200_000, 30 => 2_000_000]);
        $stream = persistedPerTitleStream($template, ['source_bit_rate' => 0] + $source, 1280, 960);

        (new PerTitleCrfService($stream))->apply(sys_get_temp_dir().'/src.mkv', 1480.0);

        expect($stream->refresh()->input_params['svtav1_crf'])->toBe(28)
            ->and($stream->meta['per_title']['bitrate_target'])->toBeNull();
    });

    it('scales the ceiling down with a downscaled rendition', function () use ($template, $source) {
        // 960x720 of a 1280x960 source: (0.5625)^0.75 of 1.49 Mbps, aimed at 0.85 of that.
        fakeAnchors([22 => 97.0, 30 => 95.5], [22 => 2_000_000, 30 => 1_300_000]);
        $stream = persistedPerTitleStream(['svtav1_crf' => 22, 'target_vmaf' => 95] + $template, $source, 960, 720);

        (new PerTitleCrfService($stream))->apply(sys_get_temp_dir().'/src.mkv', 1480.0);

        $meta = $stream->refresh()->meta['per_title'];
        expect($meta['bitrate_target'])->toBe((int) round(round(1_490_000 * 0.5625 ** 0.75) * 0.85))
            ->and($meta['estimated_bitrate'])->toBeLessThanOrEqual($meta['bitrate_target'])
            ->and($stream->input_params['svtav1_crf'])->toBeGreaterThan($meta['vmaf_crf']);
    });

    it('warns when even the codec ceiling cannot bring the rendition under its source', function () use ($template, $source) {
        Log::spy();
        fakeAnchors([22 => 97.31, 30 => 96.16], [22 => 9_000_000, 30 => 7_000_000]);
        $stream = persistedPerTitleStream($template, $source, 1280, 960);

        (new PerTitleCrfService($stream))->apply(sys_get_temp_dir().'/src.mkv', 1480.0);

        // Past MAX_INCREASE all the way to the codec ceiling, and still over: say so.
        expect($stream->refresh()->input_params['svtav1_crf'])->toBe(63);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'cannot reach its bitrate target'));
    });

    // Template 6 in production: SVT-AV1 10-bit 1080p, base CRF 26, target VMAF 94.
    $hd = ['video_codec' => 'libsvtav1', 'svtav1_crf' => 26, 'target_vmaf' => 94, 'maxrate' => '7000k', 'bufsize' => '14000k'];
    $hdSource = ['source_codec' => 'h264', 'source_bit_rate' => 9_454_185, 'source_width' => 1920, 'source_height' => 1080];

    it('keeps the base crf on grain whose target is out of reach, where the old probe spent 46% more', function () use ($hd, $hdSource) {
        // Vampire Diaries S01E20: VMAF 94 is out of reach at any CRF, and chasing it used to cost
        // CRF 22 at 6.0 Mbps for 93.2; CRF 26 measured 4.3 Mbps and 92.4.
        fakeAnchors([26 => 90.86, 34 => 88.37], [26 => 4_125_662, 34 => 1_448_305], 8_912_081);
        $stream = persistedPerTitleStream($hd, $hdSource, 1920, 1080);

        (new PerTitleCrfService($stream))->apply(sys_get_temp_dir().'/src.mkv', 1480.0);

        expect($stream->refresh()->input_params['svtav1_crf'])->toBe(26)
            ->and($stream->meta['per_title']['anchors'])->toHaveKeys([26, 34])->toHaveCount(2);
    });

    it('measures further anchors while the top one still clears the target', function () use ($hd, $hdSource) {
        // A flat cartoon: 96+ at base + 8 capped the old probe at 34, 2.4 Mbps where 1.1 met 94.
        fakeAnchors(
            [26 => 97.5, 34 => 96.8, 42 => 95.2, 50 => 93.0],
            [26 => 3_300_000, 34 => 2_400_000, 42 => 1_700_000, 50 => 1_150_000],
            8_000_000,
        );
        $stream = persistedPerTitleStream($hd, $hdSource, 1920, 1080);

        (new PerTitleCrfService($stream))->apply(sys_get_temp_dir().'/src.mkv', 1480.0);

        // Decided on 42 → 95.2 and 50 → 93.0, aimed at 94.5: 42 + 0.7 / 0.275 ≈ 44.5.
        expect($stream->refresh()->meta['per_title']['anchors'])->toHaveKeys([26, 34, 42, 50])
            ->and($stream->input_params['svtav1_crf'])->toBe(45);
    });

    it('stops stepping up once the top anchor sits near the target', function () use ($hd, $hdSource) {
        fakeAnchors([26 => 96.0, 34 => 94.8], [26 => 3_000_000, 34 => 2_000_000], 8_000_000);
        $stream = persistedPerTitleStream($hd, $hdSource, 1920, 1080);

        (new PerTitleCrfService($stream))->apply(sys_get_temp_dir().'/src.mkv', 1480.0);

        // 94.8 is under 94 + STEP_UP_MARGIN: no third anchor, and the top one caps the CRF.
        expect($stream->refresh()->meta['per_title']['anchors'])->toHaveCount(2)
            ->and($stream->input_params['svtav1_crf'])->toBe(34);
    });

    it('aims a little over the target, since the sample reads high', function () use ($hd, $hdSource) {
        // Interpolated onto 94 itself this would be 30; onto 94.5 it is 28.
        fakeAnchors([26 => 95.0, 34 => 93.0], [26 => 3_000_000, 34 => 2_000_000], 8_000_000);
        $stream = persistedPerTitleStream($hd, $hdSource, 1920, 1080);

        (new PerTitleCrfService($stream))->apply(sys_get_temp_dir().'/src.mkv', 1480.0);

        expect($stream->refresh()->input_params['svtav1_crf'])->toBe(28);
    });

    it('records that stepping up was cut short for time, and that the CRF sits on the top anchor', function () use ($hd, $hdSource) {
        // 21% of production 1080p: a loaded node spent its fixed 420 s on the first two anchors,
        // the third never ran, and nothing in the stream said so.
        fakeAnchors([26 => 97.5, 34 => 96.8], [26 => 3_300_000, 34 => 2_400_000], 8_000_000);
        $stream = persistedPerTitleStream($hd, $hdSource, 1920, 1080);

        (new PerTitleCrfService($stream))->apply(sys_get_temp_dir().'/src.mkv', 1480.0, stepUpBudget: 0.0);

        expect($stream->refresh()->meta['per_title']['step_up_cut'])->toBe('time')
            ->and($stream->meta['per_title']['anchors'])->toHaveCount(2)
            ->and($stream->input_params['svtav1_crf'])->toBe(34);
    });

    it('keeps stepping up while the job still has time for it', function () use ($hd, $hdSource) {
        fakeAnchors(
            [26 => 97.5, 34 => 96.8, 42 => 95.2, 50 => 93.0],
            [26 => 3_300_000, 34 => 2_400_000, 42 => 1_700_000, 50 => 1_150_000],
            8_000_000,
        );
        $stream = persistedPerTitleStream($hd, $hdSource, 1920, 1080);

        (new PerTitleCrfService($stream))->apply(sys_get_temp_dir().'/src.mkv', 1480.0, stepUpBudget: 600.0);

        expect($stream->refresh()->meta['per_title']['step_up_cut'])->toBeNull()
            ->and($stream->meta['per_title']['anchors'])->toHaveCount(4);
    });

    it('decides on the anchors it has when an extra one fails', function () use ($hd, $hdSource) {
        fakeAnchors(
            [26 => 97.5, 34 => 96.8, 42 => array_fill(0, SampleEncode::WINDOWS, null)],
            [26 => 3_300_000, 34 => 2_400_000, 42 => 1_700_000],
            8_000_000,
        );
        $stream = persistedPerTitleStream($hd, $hdSource, 1920, 1080);

        (new PerTitleCrfService($stream))->apply(sys_get_temp_dir().'/src.mkv', 1480.0);

        expect($stream->refresh()->meta['per_title']['anchors'])->toHaveKeys([26, 34])->toHaveCount(2)
            ->and($stream->input_params['svtav1_crf'])->toBe(34);
    });

    it('pools vmaf only over windows both anchors scored', function () use ($template, $source) {
        // The top anchor lost its hardest window (60). Pooled over whatever each anchor kept, 30
        // would read 97.5 against 22's ~80.9 — a curve rising with CRF — and hold the base CRF.
        fakeAnchors(
            [22 => [60.0, ...array_fill(0, 7, 98.0)], 30 => [null, ...array_fill(0, 7, 97.5)]],
            [22 => 1_000_000, 30 => 700_000],
        );
        $stream = persistedPerTitleStream($template, $source, 1280, 960);

        (new PerTitleCrfService($stream))->apply(sys_get_temp_dir().'/src.mkv', 1480.0);

        expect($stream->refresh()->meta['per_title']['anchors'])->toEqual([22 => 98.0, 30 => 97.5])
            ->and($stream->input_params['svtav1_crf'])->toBe(30);
    });

    it('keeps the template crf when no window scored for both anchors', function () use ($template, $source) {
        fakeAnchors(
            [22 => [98.0, ...array_fill(0, 7, null)], 30 => [null, ...array_fill(0, 7, 97.5)]],
            [22 => 1_000_000, 30 => 700_000],
        );
        $stream = persistedPerTitleStream($template, $source, 1280, 960);

        (new PerTitleCrfService($stream))->apply(sys_get_temp_dir().'/src.mkv', 1480.0);

        expect($stream->refresh()->input_params['svtav1_crf'])->toBe(22)
            ->and($stream->meta)->not->toHaveKey('per_title');
    });

    it('tries a failed probe again on a retry, while no chunk is encoded yet', function () use ($template, $source) {
        Process::fake(['*' => Process::result(exitCode: 1)]);
        $stream = persistedPerTitleStream($template, $source, 1280, 960);

        (new PerTitleCrfService($stream))->apply(sys_get_temp_dir().'/src.mkv', 1480.0);

        fakeAnchors([22 => 97.31, 30 => 96.16], [22 => 3_200_000, 30 => 2_000_000]);
        (new PerTitleCrfService($stream->refresh()))->apply(sys_get_temp_dir().'/src.mkv', 1480.0);

        expect($stream->refresh()->input_params['svtav1_crf'])->toBe(38);
    });

    it('never re-resolves a rendition whose chunks are already encoded, so a retry cannot mix two CRFs', function () use ($template, $source) {
        // The first run's probe failed and its chunks encoded at the template CRF. They are cached
        // by stream and index, not parameters: a new CRF here would encode the rest differently.
        Process::fake(['*' => Process::result(exitCode: 1)]);
        $stream = persistedPerTitleStream($template, $source, 1280, 960);
        (new PerTitleCrfService($stream))->apply(sys_get_temp_dir().'/src.mkv', 1480.0);

        Storage::disk('chunks')->put(Video::find($stream->video_id)->chunkKey($stream, 0), 'chunk');

        fakeAnchors([22 => 97.31, 30 => 96.16], [22 => 3_200_000, 30 => 2_000_000]);
        (new PerTitleCrfService($stream->refresh()))->apply(sys_get_temp_dir().'/src.mkv', 1480.0);

        expect($stream->refresh()->input_params['svtav1_crf'])->toBe(22)
            ->and($stream->meta)->not->toHaveKey('per_title');
    });
});
