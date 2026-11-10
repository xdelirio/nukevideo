<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * What a worker node's memory did, minute by minute, kept for a week in the shared cache.
 *
 * Every sizing decision after this one (how many encode slots, how many 4K slots, how many probes
 * at once) is gated on measured headroom, and the only measurements so far came from drained
 * machines. Sampling rides on the heartbeat every busy worker job already beats, so a node is
 * sampled exactly while it works, with no new process and nothing to deploy beside it.
 *
 * Two memory figures, because they answer different questions:
 * - the container cgroup's `anon` is what this node's encodes and workers hold — the number the
 *   slot sizing has to fit;
 * - `/proc/meminfo` `MemAvailable` is node-wide, so it also sees RustFS and anything else on the
 *   host: the number that says whether the kernel is about to pick an OOM victim.
 *
 * Nothing here may ever fail a job: a sample is worth less than the chunk that took it.
 */
class NodeSampler
{
    /** At most one sample per node per this many seconds, whatever number of jobs beat. */
    private const GATE_SECONDS = 30;

    private const KEEP_DAYS = 7;

    public static function sample(?string $root = null): void
    {
        try {
            $node = config('nuke.node_id');

            if ($node === null || $node === '') {
                return;
            }

            if (! Cache::add("fleet-sample-gate:{$node}", 1, self::GATE_SECONDS)) {
                return;
            }

            $root = rtrim($root ?? '', '/');
            $reading = array_filter([
                'anon' => self::field("{$root}/sys/fs/cgroup/memory.stat", 'anon'),
                'oom_kill' => self::field("{$root}/sys/fs/cgroup/memory.events", 'oom_kill'),
                'load1' => self::load1("{$root}/proc/loadavg"),
                'mem_available' => self::meminfo("{$root}/proc/meminfo", 'MemAvailable'),
                'mem_total' => self::meminfo("{$root}/proc/meminfo", 'MemTotal'),
            ], fn ($value) => $value !== null);

            $key = self::minuteKey((string) $node);
            $minute = Cache::get($key, []);

            self::keep($minute, 'anon_max', $reading['anon'] ?? null, 'max');
            self::keep($minute, 'mem_available_min', $reading['mem_available'] ?? null, 'min');
            self::keep($minute, 'load1_max', $reading['load1'] ?? null, 'max');
            // Counters: the latest reading is also the largest.
            self::keep($minute, 'oom_kill', $reading['oom_kill'] ?? null, 'max');
            self::keep($minute, 'mem_total', $reading['mem_total'] ?? null, 'latest');
            $minute['cores'] = Cpu::cores();

            Cache::put($key, $minute, now()->addDays(self::KEEP_DAYS));
        } catch (Throwable) {
            // A lost sample costs nothing; a job failed over one would.
        }
    }

    /**
     * Fleet-wide queue lengths and Horizon process counts, from the dispatcher's tick: together with
     * the node samples they say whether an idle node was starved or simply had nothing queued.
     *
     * @param  array<string, int>  $lengths
     * @param  array<string, int>  $processes
     */
    public static function recordQueues(array $lengths, array $processes): void
    {
        try {
            $key = 'fleet-sample:queues:'.now()->format('YmdHi');
            $minute = Cache::get($key, []);

            foreach (['lengths' => $lengths, 'processes' => $processes] as $kind => $values) {
                foreach ($values as $queue => $value) {
                    $minute[$kind][$queue] = max($minute[$kind][$queue] ?? 0, $value);
                }
            }

            Cache::put($key, $minute, now()->addDays(self::KEEP_DAYS));
        } catch (Throwable) {
            // Same as a node sample: worth less than the tick that took it.
        }
    }

    public static function minuteKey(string $node, ?\DateTimeInterface $at = null): string
    {
        return "fleet-sample:{$node}:".($at ?? now())->format('YmdHi');
    }

    /** @param  'max'|'min'|'latest'  $how */
    private static function keep(array &$minute, string $field, int|float|null $value, string $how): void
    {
        if ($value === null) {
            return;
        }

        $minute[$field] = match ($how) {
            'max' => max($minute[$field] ?? $value, $value),
            'min' => min($minute[$field] ?? $value, $value),
            'latest' => $value,
        };
    }

    /** A `name value` line of a cgroup stat file, such as `anon 2147483648`. */
    private static function field(string $path, string $name): ?int
    {
        $text = @file_get_contents($path);

        if ($text === false || ! preg_match('/^'.preg_quote($name, '/').' (\d+)$/m', $text, $match)) {
            return null;
        }

        return (int) $match[1];
    }

    private static function load1(string $path): ?float
    {
        $text = @file_get_contents($path);

        return $text === false ? null : (float) strtok($text, ' ');
    }

    /** A `/proc/meminfo` line, in bytes. */
    private static function meminfo(string $path, string $name): ?int
    {
        $text = @file_get_contents($path);

        if ($text === false || ! preg_match('/^'.$name.':\s+(\d+) kB$/m', $text, $match)) {
            return null;
        }

        return (int) $match[1] * 1024;
    }
}
