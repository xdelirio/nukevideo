<?php

namespace App\Console\Commands;

use App\Models\Node;
use App\Support\NodeSampler;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Reads back what {@see NodeSampler} kept: per node, how close its memory came to the edge and
 * whether the kernel killed anything; for the fleet, how long the queues ran. This is the report
 * every sizing change is gated on (anon at or under 90% of RAM, no OOM kills over 48 hours).
 */
class FleetSamplesCommand extends Command
{
    protected $signature = 'fleet:samples {--hours=48 : How far back to read}';

    protected $description = 'Report the per-node memory, OOM and load samples, and the queue lengths, of the last hours';

    /** A minute counts idle when its peak load stays under this share of the node's cores. */
    private const IDLE_LOAD_SHARE = 0.1;

    public function handle(): int
    {
        $minutes = $this->minutes(max(1, (int) $this->option('hours')));

        foreach (Node::worker()->orderBy('name')->get() as $node) {
            $keys = array_map(fn ($minute) => NodeSampler::minuteKey((string) $node->id, $minute), $minutes);
            $samples = array_values(array_filter(Cache::many($keys)));

            $this->line($samples === []
                ? "{$node->name}: no samples"
                : "{$node->name}: ".$this->summarize($samples));
        }

        $this->line($this->queues($minutes));

        return self::SUCCESS;
    }

    /** @param  list<array<string, int|float>>  $samples */
    private function summarize(array $samples): string
    {
        $shares = [];
        $idle = 0;

        foreach ($samples as $sample) {
            if (isset($sample['anon_max'], $sample['mem_total']) && $sample['mem_total'] > 0) {
                $shares[] = $sample['anon_max'] / $sample['mem_total'];
            }

            if (isset($sample['load1_max'], $sample['cores']) && $sample['load1_max'] < self::IDLE_LOAD_SHARE * $sample['cores']) {
                $idle++;
            }
        }

        sort($shares);
        $kills = array_column($samples, 'oom_kill');
        $available = array_column($samples, 'mem_available_min');
        $loads = array_column($samples, 'load1_max');

        return implode(', ', [
            count($samples).' samples',
            $shares === [] ? 'anon unknown' : sprintf('anon max %d%% p95 %d%%', round(end($shares) * 100), round($shares[(int) ceil(0.95 * count($shares)) - 1] * 100)),
            $available === [] ? 'available unknown' : sprintf('available min %.1f GB', min($available) / 1024 ** 3),
            $kills === [] ? 'oom kills unknown' : 'oom kills '.(max($kills) - min($kills)),
            $loads === [] ? 'load unknown' : sprintf('load max %.1f', max($loads)),
            "idle {$idle} min",
        ]);
    }

    /** @param  list<Carbon>  $minutes */
    private function queues(array $minutes): string
    {
        $peaks = [];

        foreach (array_filter(Cache::many(array_map(fn ($minute) => 'fleet-sample:queues:'.$minute->format('YmdHi'), $minutes))) as $sample) {
            foreach ($sample['lengths'] ?? [] as $queue => $length) {
                $peaks[$queue] = max($peaks[$queue] ?? 0, $length);
            }
        }

        if ($peaks === []) {
            return 'queues: no samples';
        }

        return 'queue peaks: '.collect($peaks)->map(fn ($length, $queue) => "{$queue} {$length}")->implode(', ');
    }

    /** @return list<Carbon> every minute of the window, oldest first */
    private function minutes(int $hours): array
    {
        $start = now()->subHours($hours)->startOfMinute();

        return array_map(fn ($i) => $start->copy()->addMinutes($i), range(0, $hours * 60));
    }
}
