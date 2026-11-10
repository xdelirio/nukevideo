<?php

namespace App\Services;

use App\Jobs\ProcessChunkJob;
use App\Models\Stream;
use App\Support\Cpu;
use App\Support\OomScore;
use Closure;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Per-title CRF: test-encode short windows of the source at two CRF anchors, measure VMAF
 * against the source and interpolate the CRF that hits the template's `target_vmaf`. The
 * chosen CRF replaces the template CRF on the stream before chunks fan out; any probe
 * failure keeps the template CRF — per-title never blocks a video.
 */
class PerTitleCrfService
{
    use Concerns\RecordsEncodeRate;

    /** Wall time of each batch the last {@see runPool} ran, in batch order. */
    private array $poolBatchSeconds = [];

    private const ANCHOR_STEP = 8;

    /**
     * How far under the low anchor VMAF may pull the CRF. Only a target within this reach is
     * chased at all: each step down costs ~12% more bits, and below the anchor the line is a
     * guess that flattens as VMAF nears its ceiling. Four steps extrapolated there cost a grainy
     * 1080p episode (26 → 90.9, 34 → 88.4, target 94) 46% more bits for ~1 VMAF point, and it
     * still missed the target: 6.0 Mbps where CRF 26 measured 4.1.
     */
    private const MAX_DECREASE = 2;

    private const MAX_INCREASE = 12;

    /**
     * Where per-title aims, in VMAF points over the template's target. The sample reads high: eight
     * 8s windows scored 0.37 over 24 windows spread across the same eleven episodes, so a CRF
     * interpolated right onto the target lands just under it in the real encode.
     */
    private const PROBE_BIAS = 0.5;

    /**
     * How far over the target the top anchor must score to measure one more, ANCHOR_STEP higher.
     * The CRF never leaves the measured range, so a top anchor that still clears the target caps
     * it there: clean and animated sources scored 96+ at base + 8 in the benchmark and spent 30-60%
     * more bits than their target needed — and about half of production sat on its top anchor.
     */
    private const STEP_UP_MARGIN = 1.0;

    /** Anchors measured past the first two, at most: base + 24 reaches a flat cartoon's target. */
    private const MAX_EXTRA_ANCHORS = 2;

    /**
     * Wall seconds a rendition's probe may have spent and still measure another anchor, when the
     * caller does not say how long it really has. Each one costs about what the last took, and
     * PrepareVideoJob runs every rendition's probe and the preflight inside its fixed timeout; an
     * extra anchor is a saving, never worth that job.
     *
     * A fixed number made the CRF answer to how busy the node was: on a loaded node two 1080p
     * anchors alone took 290-365 s, so the third never ran, the CRF stayed on the top anchor, and
     * 21% of 1080p renditions shipped ~2 VMAF over their target at 1.4-1.5x the bytes. The job
     * knows what is left of its own timeout and passes that instead ({@see apply}).
     */
    private const EXTRA_ANCHOR_BUDGET = 420;

    /**
     * Where the bitrate cap aims, as a share of the source's rate: a re-encode must never outweigh
     * its source, and the aim has to absorb the estimate's error, since the cap lands right on it.
     * Over the first 34 capped renditions in production the real rate ran 0.71-1.13x the estimate
     * (median 0.97); the low end was the VBV-pinned curve anchors are now measured without. At
     * 0.85 an estimate may run 17% short and the rendition still stays under its source.
     */
    private const SOURCE_TARGET = 0.85;

    /** d ln(bps) / d crf of a curve worth extrapolating: half the rate within ~35 CRF steps. */
    private const MIN_BITRATE_SLOPE = -0.02;

    /**
     * How far above the target a flat curve must sit to read as saturation. VMAF only stops
     * answering to CRF near its ceiling; a curve flat right at the target means the measurement
     * is not tracking CRF at all — a VBV starving both anchors did exactly that (26 → 94.24,
     * 34 → 94.19) and sent the rendition eight CRF steps up.
     */
    private const SATURATION_MARGIN = 3.0;

    private float $stepUpBudget = self::EXTRA_ANCHOR_BUDGET;

    /** Why stepping up stopped short of an anchor it wanted: `time`, `failed`, or null. */
    private ?string $stepUpCut = null;

    public function __construct(
        private Stream $stream,
    ) {}

    /**
     * @param  ?float  $stepUpBudget  wall seconds this rendition's probe may take and still measure
     *                                another anchor; null for the fixed default
     */
    public function apply(string $sourcePath, float $duration, ?Closure $tick = null, ?float $stepUpBudget = null): void
    {
        $this->stepUpBudget = $stepUpBudget ?? self::EXTRA_ANCHOR_BUDGET;

        [$crfKey, $maxCrf] = $this->crfParameter();

        if (! $this->shouldProbe($crfKey, $duration)) {
            return;
        }

        try {
            $this->resolve($crfKey, $maxCrf, $sourcePath, $duration, $tick ?? fn () => null);
        } catch (Throwable $e) {
            Log::warning('Per-title probe failed; keeping template CRF', [
                'stream' => $this->stream->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolve(string $crfKey, int $maxCrf, string $sourcePath, float $duration, Closure $tick): void
    {
        $params = $this->stream->input_params;
        $base = (int) $params[$crfKey];
        $target = (int) $params['target_vmaf'];
        $windows = SampleEncode::windows($duration);

        $anchorCrfs = array_values(array_unique([$base, min($base + self::ANCHOR_STEP, $maxCrf)]));

        // Base already at the codec ceiling: nowhere to interpolate, skip the probe entirely.
        if (count($anchorCrfs) < 2) {
            Log::info('Per-title skipped: base CRF at codec ceiling', ['stream' => $this->stream->id]);

            return;
        }

        $startedAt = microtime(true);
        [$anchors, $bitrates, $windowSourceRate] = $this->measureAnchors($anchorCrfs, $crfKey, $windows, $sourcePath, $tick);
        [$anchors, $bitrates] = $this->stepUp($anchors, $bitrates, $target, $maxCrf, $crfKey, $windows, $sourcePath, $tick, microtime(true) - $startedAt);

        // The decision is made on the top two anchors: stepping up only goes on while the top one
        // clears the target, so the target sits between those two, or under the base.
        $pair = self::topPair($anchors);
        $pairRates = array_intersect_key($bitrates, $pair);
        $vmafCrf = self::chooseCrf($pair, $target + self::PROBE_BIAS, $maxCrf);

        // VMAF alone will happily buy a noisy, already-compressed source's grain back at more bits
        // than the source itself spent (video 9059: 2.03 Mbps out of a 1.49 Mbps H.264). The
        // VBV only trims peaks now, so this is the one place the average answers to the source.
        // The samples are compared with what the source spent over the SAME windows, not its
        // file-wide average: the windows can be busier than the file, and the ratio is what
        // carries over to the whole rendition. Up to the codec ceiling, not MAX_INCREASE: that
        // bound keeps VMAF's guess near the template, while this is a measured limit. A grainy
        // cel-animation episode (1080p, 1.94 Mbps H.264) that VMAF alone sent to 1.59x its source
        // encoded at 0.75x with this, and still scored VMAF 94.2 against a target of 94.
        $cap = (new ChunkTranscodeService($this->stream))->sourceBitrateCap($windowSourceRate);
        $aim = $cap === null ? null : (int) round($cap * self::SOURCE_TARGET);
        $chosen = $aim === null ? $vmafCrf : self::capCrfToBitrate($pairRates, $vmafCrf, $aim, $maxCrf);

        $estimated = self::estimateBitrate($pairRates, $chosen);

        $params[$crfKey] = $chosen;
        $meta = $this->stream->meta ?? [];
        $meta['per_title'] = [
            'target_vmaf' => $target,
            'base_crf' => $base,
            'vmaf_crf' => $vmafCrf,
            'chosen_crf' => $chosen,
            'anchors' => array_map(fn (float $score) => round($score, 2), $anchors),
            'anchor_bitrates' => $bitrates,
            'window_source_bitrate' => $windowSourceRate,
            'bitrate_target' => $aim,
            'estimated_bitrate' => $estimated === null ? null : (int) round($estimated),
            'windows' => count($windows),
            // Set when the top anchor still cleared the target and the next one was never measured:
            // the CRF sits on that anchor, above what the target needed. Nothing recorded this
            // before, so the renditions it cost could only be found by replaying the decision.
            'step_up_cut' => $this->stepUpCut,
        ];

        $this->stream->update(['input_params' => $params, 'meta' => $meta]);

        Log::info('Per-title CRF resolved', ['stream' => $this->stream->id] + $meta['per_title']);

        // A curve too flat or broken to extrapolate leaves the VMAF choice uncapped. Mostly static
        // content, whose rate is container overhead that no CRF would save; say so either way.
        if ($aim !== null && $estimated === null) {
            Log::warning('Per-title bitrate curve unusable; CRF not capped against the source', [
                'stream' => $this->stream->id,
                'anchor_bitrates' => $pairRates,
                'bitrate_target' => $aim,
            ]);
        }

        // The codec's top CRF still estimates above the aim. That is not yet an overshoot — the
        // aim sits under the source — but the margin that absorbs the estimate's error is gone,
        // and PackageVideoJob will say whether the rendition really outweighed its source.
        if ($aim !== null && $estimated !== null && $estimated > $aim) {
            Log::warning('Per-title CRF cannot reach its bitrate target under the source', [
                'stream' => $this->stream->id,
                'chosen_crf' => $chosen,
                'estimated_bitrate' => (int) round($estimated),
                'bitrate_target' => $aim,
            ]);
        }
    }

    /**
     * Measure one more anchor, ANCHOR_STEP above the top one, while the top one still clears the
     * target by STEP_UP_MARGIN — up to MAX_EXTRA_ANCHORS, and only while the time spent plus what
     * the last anchor took stays inside EXTRA_ANCHOR_BUDGET. An extra anchor that fails leaves the
     * decision to what was measured; it never fails the probe.
     *
     * @param  array<int, float>  $anchors
     * @param  array<int, int>  $bitrates
     * @param  list<float>  $windows
     * @return array{0: array<int, float>, 1: array<int, int>}
     */
    private function stepUp(array $anchors, array $bitrates, int $target, int $maxCrf, string $crfKey, array $windows, string $sourcePath, Closure $tick, float $spent): array
    {
        $perAnchor = $spent / count($anchors);

        for ($extra = 0; $extra < self::MAX_EXTRA_ANCHORS; $extra++) {
            $top = max(array_keys($anchors));
            $next = min($top + self::ANCHOR_STEP, $maxCrf);

            if ($next <= $top || $anchors[$top] < $target + self::STEP_UP_MARGIN) {
                break;
            }

            if ($spent + $perAnchor > $this->stepUpBudget) {
                $this->stepUpCut = 'time';

                Log::warning('Per-title stops stepping up: out of probe time', [
                    'stream' => $this->stream->id,
                    'spent' => (int) $spent,
                    'budget' => (int) $this->stepUpBudget,
                    'top_anchor' => $top,
                ]);
                break;
            }

            $startedAt = microtime(true);

            try {
                [$more, $moreRates] = $this->measureAnchors([$next], $crfKey, $windows, $sourcePath, $tick, withSourceRate: false);
            } catch (Throwable $e) {
                $this->stepUpCut = 'failed';

                Log::warning('Per-title extra anchor failed; deciding on the anchors measured', [
                    'stream' => $this->stream->id,
                    'anchor' => $next,
                    'error' => $e->getMessage(),
                ]);
                break;
            }

            $anchors += $more;
            $bitrates += $moreRates;
            $perAnchor = microtime(true) - $startedAt;
            $spent += $perAnchor;
        }

        return [$anchors, $bitrates];
    }

    /**
     * The two highest anchors, in CRF order.
     *
     * @param  array<int, float>  $anchors
     * @return array<int, float>
     */
    public static function topPair(array $anchors): array
    {
        ksort($anchors);

        return array_slice($anchors, -2, 2, true);
    }

    /**
     * Interpolate the CRF hitting `$target` from two measured anchors (crf => vmaf). VMAF is
     * near-linear in CRF over a one-step span. A flat curve gives no slope to interpolate with:
     * take the top anchor only when both sit clearly above the target (a saturated probe or a
     * trivial source); anywhere else the measurement can't be trusted, so keep the template CRF.
     *
     * @param  array<int, float>  $anchors
     */
    public static function chooseCrf(array $anchors, float $target, int $maxCrf): int
    {
        ksort($anchors);
        [$lowCrf, $highCrf] = array_keys($anchors);
        [$lowScore, $highScore] = array_values($anchors);

        $slope = ($highScore - $lowScore) / max(1, $highCrf - $lowCrf);

        $chosen = $slope > -0.05
            ? ($highScore >= $target + self::SATURATION_MARGIN ? $highCrf : $lowCrf)
            : $lowCrf + ($target - $lowScore) / $slope;

        // Downward, only a target within MAX_DECREASE is worth chasing: one further away would
        // spend steeply more bits on an extrapolated gain and miss anyway, so keep the base.
        if ($chosen < $lowCrf - self::MAX_DECREASE) {
            $chosen = $lowCrf;
        }

        // Upward, never past the top anchor: beyond it the line is a guess, and a guess that
        // overshoots costs visible quality (a -0.07 slope extrapolated 26 → 38).
        $chosen = min($highCrf, $lowCrf + self::MAX_INCREASE, $chosen);

        return (int) max(1, min($maxCrf, round($chosen)));
    }

    /**
     * Raise `$crf` until the anchors' bitrate curve puts it at or under `$ceiling` — never lower it:
     * this only ever takes bits away from what VMAF asked for. Bitrate is close to exponential in
     * CRF, so the curve is interpolated in log space, and unlike VMAF it extrapolates well past the
     * top anchor (it is what CRF is defined by), up to the codec ceiling. A curve that doesn't
     * fall with CRF is a broken measurement, and keeps the VMAF choice.
     *
     * @param  array<int, int>  $bitrates  crf => measured bps
     */
    public static function capCrfToBitrate(array $bitrates, int $crf, int $ceiling, int $maxCrf): int
    {
        $curve = self::bitrateCurve($bitrates);

        if ($curve === null || $ceiling <= 0 || self::estimateBitrate($bitrates, $crf) <= $ceiling) {
            return $crf;
        }

        [$lowCrf, $lowRate, $slope] = $curve;
        $needed = (int) ceil($lowCrf + (log($ceiling) - log($lowRate)) / $slope - 1e-9);

        return max($crf, min($maxCrf, $needed));
    }

    /**
     * The bitrate the anchors predict at `$crf`, or null when they can't predict one.
     *
     * @param  array<int, int>  $bitrates  crf => measured bps
     */
    public static function estimateBitrate(array $bitrates, int $crf): ?float
    {
        $curve = self::bitrateCurve($bitrates);

        if ($curve === null) {
            return null;
        }

        [$lowCrf, $lowRate, $slope] = $curve;

        return $lowRate * exp($slope * ($crf - $lowCrf));
    }

    /**
     * @param  array<int, int>  $bitrates
     * @return ?array{0: int, 1: int, 2: float} low anchor crf, its bps, d ln(bps) / d crf
     */
    private static function bitrateCurve(array $bitrates): ?array
    {
        if (count($bitrates) !== 2 || min($bitrates) <= 0) {
            return null;
        }

        ksort($bitrates);
        [$lowCrf, $highCrf] = array_keys($bitrates);
        [$lowRate, $highRate] = array_values($bitrates);

        if ($highCrf <= $lowCrf || $highRate >= $lowRate) {
            return null;
        }

        $slope = (log($highRate) - log($lowRate)) / ($highCrf - $lowCrf);

        // Too flat to be CRF talking. Every encoder here halves its rate within 6-15 CRF steps
        // (SVT-AV1 measured at 12-15), so a pair that barely moves is a pinned or noisy reading —
        // and extrapolated, 30 → 2.0 Mbps / 38 → 1.9 Mbps against a 1.0 Mbps ceiling asks for
        // CRF 138, which the codec ceiling would turn into an unwatchable 63.
        return $slope <= self::MIN_BITRATE_SLOPE ? [$lowCrf, $lowRate, $slope] : null;
    }

    /**
     * Encode every (anchor × window) sample in ONE pool, then VMAF-score them all in a second —
     * each probe encode is short and thread-capped, and pooling both anchors together halves the
     * wall time this adds to PrepareVideoJob's fixed budget. Windows pool into a harmonic mean
     * so one bad window drags its anchor down more than a plain average would.
     *
     * The samples' sizes come along for free: they're the bitrate each anchor costs on this source,
     * pooled only over windows every anchor encoded so the two points compare the same footage —
     * and next to them, what the source itself spent over those windows.
     *
     * @param  list<int>  $anchorCrfs
     * @param  list<float>  $windows
     * @return array{0: array<int, float>, 1: array<int, int>, 2: ?int} [crf => pooled vmaf score, crf => bps, source bps over the same windows]
     */
    private function measureAnchors(array $anchorCrfs, string $crfKey, array $windows, string $sourcePath, Closure $tick, bool $withSourceRate = true): array
    {
        $jobs = [];
        foreach ($anchorCrfs as $crf) {
            foreach ($windows as $i => $start) {
                $jobs[] = [
                    'crf' => $crf,
                    'window' => $i,
                    'start' => $start,
                    'sample' => dirname($sourcePath)."/pertitle_{$this->stream->id}_{$crf}_{$i}.mp4",
                ];
            }
        }

        try {
            $encodes = $this->runPool(array_map(
                fn (array $job) => $this->encodeSampleCommand($job['crf'], $crfKey, $job['start'], $sourcePath, $job['sample']),
                $jobs,
            ), $tick);

            // Read before the VMAF pass overwrites the timings: a batch runs concurrently, so its
            // wall time is one encode's, not the sum — which is the number a chunk will live by.
            $this->recordSampledRate();

            // Only score what actually encoded; a lost sample costs its window, not the probe.
            $encoded = array_keys(array_filter($encodes, fn (?string $output) => $output !== null));

            // Read now: the finally below deletes the samples.
            [$bitrates, $commonWindows] = $this->pooledBitrates($jobs, $encoded, $anchorCrfs);
            $windowSourceRate = $withSourceRate ? $this->windowSourceRate($sourcePath, $windows, $commonWindows) : null;

            $outputs = $this->runPool(array_map(
                fn (int $index) => $this->vmafCommand($jobs[$index]['start'], $sourcePath, $jobs[$index]['sample']),
                $encoded,
            ), $tick);
        } finally {
            array_map(fn (array $job) => @unlink($job['sample']), $jobs);
        }

        $byWindow = [];
        foreach ($outputs as $position => $output) {
            $score = $output === null ? null : self::parseVmafScore($output);

            if ($score !== null) {
                $job = $jobs[$encoded[$position]];
                $byWindow[$job['window']][$job['crf']] = $score;
            }
        }

        // Pooled over the windows every anchor scored, for the same reason as the bitrates: an
        // anchor that lost the hardest window would score higher than the footage warrants and
        // bend the slope. Interpolating needs both ends, so with no window in common there's no curve.
        $common = array_filter($byWindow, fn (array $perAnchor) => count($perAnchor) === count($anchorCrfs));

        if (! $common) {
            throw new RuntimeException('No sample window scored for every anchor');
        }

        $scores = [];
        foreach ($anchorCrfs as $crf) {
            $windowScores = array_column($common, $crf);
            $scores[$crf] = count($windowScores) / array_sum(array_map(fn (float $s) => 1 / max($s, 1.0), $windowScores));
        }

        return [$scores, $bitrates, $windowSourceRate];
    }

    /**
     * The source's own bps over the windows the bitrates were pooled on. Null unless every one of
     * them could be read, so a partial answer never passes for the whole; the ceiling then falls
     * back to the file-wide average.
     *
     * @param  list<float>  $windows
     * @param  list<int>  $commonWindows
     */
    private function windowSourceRate(string $sourcePath, array $windows, array $commonWindows): ?int
    {
        if (! $commonWindows) {
            return null;
        }

        $bytes = SampleEncode::sourceBytes(
            $sourcePath,
            $this->stream->meta['index'] ?? 'v:0',
            array_intersect_key($windows, array_flip($commonWindows)),
        );

        if (in_array(null, $bytes, true) || count($bytes) !== count($commonWindows)) {
            return null;
        }

        return (int) round(array_sum($bytes) * 8 / (count($bytes) * SampleEncode::SECONDS));
    }

    /**
     * Mean bps per anchor over the windows every anchor wrote. A window one anchor lost would
     * otherwise put different footage on either end of the curve; with no window in common the
     * curve is empty, and {@see capCrfToBitrate} leaves the VMAF choice alone.
     *
     * @param  list<array{crf: int, window: int, start: float, sample: string}>  $jobs
     * @param  list<int>  $encoded  indexes into $jobs whose encode succeeded
     * @param  list<int>  $anchorCrfs
     * @return array{0: array<int, int>, 1: list<int>} [crf => bps, the windows pooled]
     */
    private function pooledBitrates(array $jobs, array $encoded, array $anchorCrfs): array
    {
        $bytes = [];

        foreach ($encoded as $index) {
            $size = (int) (@filesize($jobs[$index]['sample']) ?: 0);

            if ($size > 0) {
                $bytes[$jobs[$index]['window']][$jobs[$index]['crf']] = $size;
            }
        }

        $common = array_filter($bytes, fn (array $perAnchor) => count($perAnchor) === count($anchorCrfs));

        if (! $common) {
            return [[], []];
        }

        $bitrates = [];

        foreach ($anchorCrfs as $crf) {
            $total = array_sum(array_map(fn (array $perAnchor) => $perAnchor[$crf], $common));
            $bitrates[$crf] = (int) round($total * 8 / (count($common) * SampleEncode::SECONDS));
        }

        return [$bitrates, array_keys($common)];
    }

    private function encodeSampleCommand(int $crf, string $crfKey, float $start, string $sourcePath, string $samplePath): string
    {
        // Replicated stream so the anchor CRF renders through the exact same argument builder
        // (scale, GOP, *-params) the real chunk encode will use — without any VBV, so both the VMAF
        // and the size answer to the CRF alone. A VBV pinning the low anchor flattens both curves:
        // tightened to the source it made both anchors score alike and the flat curve read as
        // saturation (video 6275); at PEAK_HEADROOM a grainy episode's anchors scored 96.7/96.5 and
        // the bitrate slope extrapolated to CRF 45; and even the template's own 6000k pinned CRF 22
        // at 5.9 Mbps, sending renditions to CRF 43-46 at 0.84x their source. The real encode's VBV
        // only ever trims what these samples spend, so the cap errs on the lean side. One more
        // thing differs: the GPU scale filter. vpp_qsv needs a hw device this command never sets
        // up, so the replica is forced onto the software decode+scale path — by its own flag, not
        // by blinding its pix_fmt meta, which also decides the encode's bit depth.
        $probe = $this->stream->replicate();
        $probe->input_params = [$crfKey => $crf] + array_diff_key($probe->input_params ?? [], ['maxrate' => true, 'bufsize' => true]);
        $probe->meta = ['force_software_decode' => true] + ($probe->meta ?? []);
        $service = new ChunkTranscodeService($probe);

        return sprintf(
            'ffmpeg -hide_banner -y -ss %.3f -t %d -i %s -fps_mode passthrough %s -f %s %s',
            $start,
            SampleEncode::SECONDS,
            escapeshellarg($sourcePath),
            $service->buildVideoArguments(windowed: true),
            $service->outputFormat(),
            escapeshellarg($samplePath),
        );
    }

    private function vmafCommand(float $start, string $sourcePath, string $samplePath): string
    {
        // Reference downscaled to the rendition's resolution with ffmpeg's default scaler — the
        // same one the sample encode used; a sharper kernel here would bias every score low.
        // Pair frames by index (settb=AVTB,setpts=N), not by timestamp: the source and the .mp4
        // sample carry different timebases (1/fps vs 1/1000), so libvmaf's default PTS framesync
        // mispairs them and every score reads ~35 points low. A tone-mapped rendition is scored
        // against the source tone-mapped the same way: against the PQ picture itself, VMAF would
        // read the change of dynamic range as distortion and send the CRF to the floor.
        $service = new ChunkTranscodeService($this->stream);
        $toneMap = $service->toneMapFilter();

        $filter = sprintf(
            '[%s]scale=%d:%d%s,settb=AVTB,setpts=N[ref];[1:v:0]settb=AVTB,setpts=N[dist];[dist][ref]libvmaf=n_threads=2',
            $service->mapTarget(),
            (int) $this->stream->width,
            (int) $this->stream->height,
            $toneMap ? ','.$toneMap : '',
        );

        return sprintf(
            'ffmpeg -hide_banner -ss %.3f -t %d -i %s -i %s -lavfi "%s" -f null -',
            $start,
            SampleEncode::SECONDS,
            escapeshellarg($sourcePath),
            escapeshellarg($samplePath),
            $filter,
        );
    }

    private static function parseVmafScore(string $output): ?float
    {
        return preg_match('/VMAF score: ([\d.]+)/', $output, $matches) ? (float) $matches[1] : null;
    }

    /**
     * Run the probe commands a few at a time. The pool used to launch every (anchor × window) at
     * once — 6-8 ffmpeg on top of the node's chunk encoders, which is what OOM-killed staging — and
     * it threw on the first failure, discarding every other measurement with it.
     *
     * @param  list<string>  $commands
     * @return list<?string> combined stdout+stderr per command, in input order; null when it failed
     */
    private function runPool(array $commands, Closure $tick): array
    {
        $outputs = [];
        $this->poolBatchSeconds = [];

        foreach (array_chunk($commands, Cpu::probeConcurrency()) as $batch) {
            $batchStartedAt = microtime(true);

            try {
                $results = Process::pool(function (Pool $pool) use ($batch) {
                    foreach ($batch as $command) {
                        // The kernel's pick over a worker or the chunk store when memory runs out. A
                        // killed sample costs its window, like any failed one.
                        $pool->timeout(SampleEncode::TIMEOUT)->command(OomScore::wrap($command, OomScore::probe()));
                    }
                })->start(fn () => $tick())->wait();

                $succeeded = 0;

                foreach ($results->collect() as $result) {
                    $outputs[] = $result->successful() ? $result->output().$result->errorOutput() : null;
                    $succeeded += $result->successful() ? 1 : 0;
                }

                // Only a batch where everything ran to completion is worth timing. A timeout would
                // clock SampleEncode::TIMEOUT instead of the encode — 15x the real cost per source
                // second — and since the highest reading wins, one stuck sample would poison the
                // rate for the whole rendition. A batch that failed fast lies the other way.
                if ($succeeded === count($batch)) {
                    $this->poolBatchSeconds[] = microtime(true) - $batchStartedAt;
                }
            } catch (ProcessTimedOutException) {
                // The pool surfaces a timeout as a throw, so the batch's other results go with it.
                // Small batches keep that cheap: a stuck sample costs its window, nothing else.
                Log::warning('Per-title sample timed out; dropping its window', [
                    'stream' => $this->stream->id,
                ]);

                $outputs = array_pad($outputs, count($outputs) + count($batch), null);
            }
        }

        return $outputs;
    }

    /**
     * Mean batch wall time as a per-encode cost. The batches run {@see Cpu::probeConcurrency} encodes at
     * once, so a batch takes as long as its slowest member rather than the sum — which makes this
     * the cost of one encode with company, the same shape a chunk job runs in.
     */
    private function recordSampledRate(): void
    {
        $batches = array_filter($this->poolBatchSeconds, fn (float $seconds) => $seconds > 0.0);

        if (! $batches) {
            return;
        }

        $this->recordEncodeRate($this->stream, array_sum($batches) / count($batches), SampleEncode::SECONDS);
    }

    private function shouldProbe(?string $crfKey, float $duration): bool
    {
        $params = $this->stream->input_params ?? [];

        if (! $crfKey || empty($params['target_vmaf']) || ! isset($params[$crfKey])) {
            return false;
        }

        // Codec gate, not just a panel gate: templates saved before a codec was dropped from
        // `target_vmaf` still carry the field, and validation never strips it.
        if (! in_array($params['video_codec'] ?? null, config('ffmpeg.parameters.target_vmaf.available_for', []), true)) {
            return false;
        }

        // ABR mode picks bitrate explicitly; per-title only steers CRF. No copy-detection guard:
        // video chunks are always window-cut, so the copy fast-path never applies to them.
        if (! empty($params['constant_bitrate'])) {
            return false;
        }

        if ($duration < SampleEncode::MIN_DURATION || ! $this->stream->width || ! $this->stream->height) {
            return false;
        }

        // Above 1080p the probe is the encode's size several times over: two 2160p samples at once
        // are ~25 GB on top of a full node, and sixteen of them outlast PrepareVideoJob's timeout
        // (measured 624 s for the first two anchors on a loaded 224-thread box). Until the probe
        // can run on the heavy pool, such a rung encodes at the template's CRF under its maxrate.
        if ($this->stream->isHeavyEncode()) {
            Log::info('Per-title skipped: rendition above 1080p encodes at the template CRF', ['stream' => $this->stream->id]);

            return false;
        }

        // Redelivery: a previous attempt already resolved this stream.
        if (isset($this->stream->meta['per_title'])) {
            return false;
        }

        return ! $this->hasEncodedChunks();
    }

    /**
     * Whether an earlier run already encoded chunks of this rendition. They are cached by stream and
     * index alone ({@see ProcessChunkJob}), not by parameters, so resolving a CRF now —
     * after a probe that failed on the first run and let the chunks encode at the template's —
     * would leave the retry reusing those and encoding the rest at the new one: one rendition, two
     * qualities, and an average nothing estimated. Before any chunk exists a failed probe is simply
     * tried again. `--reprobe` mints new streams, so it always probes. An unreadable store reads as
     * no chunks: the old behaviour, never a lost probe.
     */
    private function hasEncodedChunks(): bool
    {
        $video = $this->stream->video;

        if (! $video || ! $this->stream->ulid) {
            return false;
        }

        try {
            return Storage::disk('chunks')->files("{$video->chunksDir()}/{$this->stream->ulid}") !== [];
        } catch (Throwable) {
            return false;
        }
    }

    /** Quality knobs the probe can steer: CRF (CPU codecs) and QSV ICQ, both CRF-like scales. */
    private const QUALITY_TEMPLATES = ['-crf %s', '-global_quality %s'];

    /**
     * The quality field and its ceiling for this stream's codec, from config/ffmpeg.php — the
     * same source of truth the panel and command builder use, so a new codec added there is
     * picked up here without a parallel hardcoded map.
     *
     * @return array{0: ?string, 1: int} [param key, max crf]
     */
    private function crfParameter(): array
    {
        $codec = data_get($this->stream->input_params, 'video_codec');

        foreach (config('ffmpeg.parameters') as $key => $config) {
            if (in_array($config['template'] ?? null, self::QUALITY_TEMPLATES, true) && in_array($codec, $config['available_for'] ?? [], true)) {
                return [$key, (int) ($config['max'] ?? 51)];
            }
        }

        return [null, 51];
    }
}
