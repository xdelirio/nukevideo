<?php

namespace App\Support;

/**
 * Sizes a worker node's encode concurrency against the hardware it actually sees — nodes range from
 * a 4-core VPS to a 64-core box, so nothing here may assume a shape. Two independent budgets, CPU
 * and memory, and the tighter one wins: a box with cores to spare but little RAM must not run more
 * ffmpeg than its RAM holds (staging learned this the hard way — the OOM killer took the S3 store
 * down with it), and a box with RAM to spare can't run more encodes than its cores can feed.
 *
 * Both readings respect cgroup limits, so `DOCKER_MEMORY`/`DOCKER_CPUSET_CPUS` on a node shrink the
 * budget instead of being ignored. Explicit env always wins over the whole calculation.
 */
class Cpu
{
    /**
     * Threads per ffmpeg (SVT-AV1's `lp`). Fixed, not a share of the node: measured on a 224-thread
     * box, 40 encodes at 4 threads moved 531-538 Mpix/s in 79-84 GB where the old fair share
     * (32 encodes at 7, which SVT clamps to 6) moved 481-505 in ~100 GB. More, leaner encodes win,
     * and an encode's memory grows with its threads: 1080p peaks at 3.2-3.7 GB at 4 and 4.5-5.1 at 6.
     */
    private const ENCODE_THREADS = 4;

    /**
     * Cores one encode really keeps busy: its own threads plus decode, scale and the encoder's
     * lookahead. A 32-thread box moved the same Mpix/s with 6, 8, 10 or 12 encodes; sizing by
     * threads alone (32 / 4 = 8) only added memory.
     */
    private const CORES_PER_ENCODE = 5;

    /**
     * What one slot of the common ladder is budgeted at ENCODE_THREADS. Between the two things a
     * slot holds — 1080p peaks at 3.2-3.7 GB, 720p at 1.6-2.0 — because a ladder puts both on the
     * queue and their peaks never line up: a full pool measured 2.1 GB per process. At this
     * figure a 224-thread / 123 GB box sizes to the 40 encodes that were benchmarked there
     * (531-538 Mpix/s in 79-84 GB; 48 leaner ones moved no more). It is a budget, not a
     * guarantee: a pool that happened to hold nothing but full-frame 1080p would want more, which
     * is what the start gate in ProcessChunkJob is for. Anything bigger than 1080p is not sized
     * by this at all — it runs on the heavy pool ({@see heavyWorkerProcesses}).
     */
    private const ENCODE_MEMORY_GB = 2.75;

    /** What a full common pool really holds per process, peaks never coinciding: 2.1 GB measured. */
    private const POOL_AVERAGE_GB = 2.1;

    /** Peak RSS of one encode by picture size, at ENCODE_THREADS: measured 9.3 GB at 3840x1606. */
    private const ENCODE_BASE_GB = 0.5;

    private const ENCODE_GB_PER_MEGAPIXEL = 1.43;

    /** A rendition past this many pixels (1920x1200) is "heavy": several times a 1080p's memory. */
    public const HEAVY_PIXELS = 2_304_000;

    /** Heavy encodes a node runs at once, at most. Two 2160p encodes are ~25 GB on their own. */
    private const MAX_HEAVY_PROCESSES = 2;

    /** Left to the OS, the chunk store, packaging and Horizon itself. */
    private const RESERVED_FRACTION = 0.1;

    private const MIN_RESERVED_GB = 4.0;

    public static function cores(): int
    {
        $n = (int) @shell_exec('nproc 2>/dev/null');
        if ($n > 0) {
            return $n;
        }

        $n = (int) @shell_exec('grep -c ^processor /proc/cpuinfo 2>/dev/null');

        return $n > 0 ? $n : 1;
    }

    /** Memory the node may actually use: its cgroup limit when capped, else the host's total. */
    public static function memoryGb(): float
    {
        $limit = @file_get_contents('/sys/fs/cgroup/memory.max')
            ?: @file_get_contents('/sys/fs/cgroup/memory/memory.limit_in_bytes');

        // cgroup v2 writes "max" when uncapped, v1 an absurd sentinel. Both mean "whatever the host has".
        $bytes = (float) trim((string) $limit);

        if ($bytes > 0 && $bytes < PHP_INT_MAX / 2) {
            return $bytes / 1024 ** 3;
        }

        if (preg_match('/MemTotal:\s+(\d+) kB/', (string) @file_get_contents('/proc/meminfo'), $match)) {
            return ((float) $match[1]) / 1024 ** 2;
        }

        return self::ENCODE_MEMORY_GB + self::MIN_RESERVED_GB; // unreadable: enough for exactly one encode
    }

    public static function videoWorkerProcesses(): int
    {
        return (int) env('VIDEO_WORKER_PROCESSES', self::workerProcesses(self::cores(), self::memoryGb()));
    }

    public static function heavyWorkerProcesses(): int
    {
        return (int) env('HEAVY_WORKER_PROCESSES', self::heavyProcesses(self::cores(), self::memoryGb()));
    }

    public static function videoEncoderThreads(): int
    {
        return (int) env('VIDEO_FFMPEG_THREADS', self::threadsPerEncode(self::cores()));
    }

    /**
     * Concurrent encodes of the common ladder (up to 1080p) this node can carry: whichever of its
     * CPU and its RAM runs out first. Never zero — a box too small for the budget still processes
     * video, one chunk at a time.
     */
    public static function workerProcesses(int $cores, float $memoryGb): int
    {
        $byCpu = intdiv(max(1, $cores), self::CORES_PER_ENCODE);
        $byMemory = (int) floor(self::usableGb($memoryGb) / self::ENCODE_MEMORY_GB);

        return max(1, min($byCpu, $byMemory));
    }

    /**
     * Encodes above 1080p this node runs at once, on their own pool: what is left of its memory
     * once the common pool is full (at what it measurably holds, not its worst case — 36 common
     * encodes plus two 2160p ones peaked at 101 GB of 123), in whole 2160p encodes. A flat budget per slot let a 32-thread
     * box start eight 2160p encodes — 74 GB — whether it had 32 GB or 64, and the kernel killed
     * them 357 times. Never zero, so a 2160p rung always has somewhere to run; on a box too small
     * for one, {@see Memory::waitFor} holds it until the common pool leaves room.
     */
    public static function heavyProcesses(int $cores, float $memoryGb): int
    {
        $left = self::usableGb($memoryGb) - self::workerProcesses($cores, $memoryGb) * self::POOL_AVERAGE_GB;
        $fit = (int) floor($left / self::encodeMemoryGb(3840, 2160));

        return max(1, min(self::MAX_HEAVY_PROCESSES, $fit));
    }

    /**
     * Sample encodes a per-title probe runs at once. The probe lives inside one orchestration slot
     * and up to three preparations run per node, so it may not take the node — but two at a time
     * on a 224-thread box left a pair of 1080p anchors taking 330-365 s under load, where eight
     * took 120. One per sixteen cores, between two and four.
     */
    public static function probeConcurrency(): int
    {
        return (int) env('PROBE_CONCURRENCY', max(2, min(4, intdiv(self::cores(), 16))));
    }

    /** Threads for one encode: fixed, and never more than the node has. */
    public static function threadsPerEncode(int $cores): int
    {
        return max(1, min(self::ENCODE_THREADS, $cores));
    }

    /** Peak memory one encode of this picture size needs, in GB. */
    public static function encodeMemoryGb(int $width, int $height): float
    {
        return self::ENCODE_BASE_GB + self::ENCODE_GB_PER_MEGAPIXEL * ($width * $height / 1_000_000);
    }

    public static function isHeavy(int $width, int $height): bool
    {
        return $width * $height > self::HEAVY_PIXELS;
    }

    /** Memory this node keeps out of the encoders' reach, in GB. */
    public static function reservedGb(?float $memoryGb = null): float
    {
        $memoryGb ??= self::memoryGb();

        return max(self::MIN_RESERVED_GB, $memoryGb * self::RESERVED_FRACTION);
    }

    private static function usableGb(float $memoryGb): float
    {
        return $memoryGb - self::reservedGb($memoryGb);
    }
}
