<?php

namespace App\Console\Commands;

use App\Enums\NodeAccel;
use App\Enums\VideoPriority;
use App\Enums\VideoStatus;
use App\Jobs\PrepareVideoJob;
use App\Models\Node;
use App\Models\Template;
use App\Models\Video;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DispatchPendingVideosCommand extends Command
{
    protected $signature = 'videos:dispatch';

    protected $description = 'Dispatch pending videos into the chunk-based encoding pipeline, up to what each hardware family can hold';

    // Light orchestration queue every worker drains, so prep runs even on a GPU-only fleet.
    private const QUEUE = 'orchestration';

    /** Statuses that mean a video is occupying a worker slot. */
    private const IN_FLIGHT = [
        VideoStatus::RUNNING->value,
        VideoStatus::DOWNLOADING->value,
        VideoStatus::UPLOADING->value,
    ];

    public function handle(): void
    {
        if (Node::worker()->active()->doesntExist()) {
            return;
        }

        $inFlight = $this->inFlightByCapacity();

        // Priority first, arrival within it. Every level is walked even when a higher one still
        // has videos waiting: those are only ever skipped for a full or missing hardware family,
        // and a lower-priority video on a family with room must not idle behind them.
        foreach (VideoPriority::dispatchOrder() as $priority) {
            // By id, which is arrival order: the auto-increment key follows insertion. Paged rather
            // than a cursor, which cannot eager-load and would query the template once per video.
            $videos = Video::where('status', VideoStatus::PENDING->value)
                ->where('priority', $priority->value)
                ->with(['template', 'templateVersion'])
                ->lazyById(100);

            foreach ($videos as $video) {
                // Nothing further can be claimed this tick, so the rest of the backlog — possibly
                // a whole season — is not worth walking just to skip every row of it.
                if ($this->fleetIsFull($inFlight)) {
                    return;
                }

                $this->tryDispatch($video, $inFlight);
            }
        }
    }

    /**
     * Claim one pending video and hand it to PrepareVideoJob, unless its hardware family is full
     * or missing. Skipping never fails it: it stays PENDING and the next tick offers it again.
     *
     * @param  array<string, int>  $inFlight  bumped for every family the video encodes on
     */
    private function tryDispatch(Video $video, array &$inFlight): void
    {
        $capacities = $this->capacitiesOf($video);

        // Skipped, never a stop: a full GPU family must not hold back the CPU videos queued
        // behind it, which is the whole point of counting per family.
        foreach ($capacities as $capacity) {
            if (($inFlight[$capacity] ?? 0) >= $this->limitFor($capacity)) {
                return;
            }
        }

        // Never claim a video the fleet cannot encode: its chunks would land on a hardware
        // queue nobody drains and it would hang until the reaper. Skipping leaves it
        // PENDING at no cost, and the next tick offers it again the moment the node is
        // back — where failing it would hand it to `videos:prune`, which deletes the video
        // and the user's only copy a week later, over what is usually a reboot. Skipped
        // rather than stopping so one waiting GPU video cannot block the CPU queue
        // behind it.
        if ($video->encodingDefinition()?->missingCapacity()) {
            return;
        }

        $originalPath = $video->streams()->where('type', 'original')->value('path');

        if (! $originalPath) {
            $video->markAsFailed('The uploaded source is missing: this video has no original stream to encode from.');

            return;
        }

        // Atomically claim (PENDING → RUNNING) so a concurrent tick can't double-dispatch.
        $claimed = Video::whereKey($video->id)
            ->where('status', VideoStatus::PENDING->value)
            ->update([
                'status' => VideoStatus::RUNNING->value,
                'last_heartbeat_at' => now(),
            ]);

        if (! $claimed) {
            return;
        }

        // Revert on dispatch failure so the video isn't stranded in RUNNING with no job.
        try {
            PrepareVideoJob::dispatch($video->id, $originalPath)
                ->onQueue(self::QUEUE);

            // Occupies a slot in every family it encodes on, for the rest of this tick.
            foreach ($capacities as $capacity) {
                $inFlight[$capacity] = ($inFlight[$capacity] ?? 0) + 1;
            }
        } catch (\Throwable $e) {
            Log::error('Failed to dispatch segment job; reverting to pending', ['video' => $video->id, 'error' => $e->getMessage()]);

            Video::whereKey($video->id)
                ->where('status', VideoStatus::RUNNING->value)
                ->update(['status' => VideoStatus::PENDING->value]);
        }
    }

    /**
     * Whether every hardware family the fleet has is at its limit. A family with no active nodes
     * never counts: its videos are skipped by `missingCapacity()` whatever the slots say.
     *
     * @param  array<string, int>  $inFlight
     */
    private function fleetIsFull(array $inFlight): bool
    {
        foreach ($this->families() as $capacity) {
            if (($inFlight[$capacity] ?? 0) < $this->limitFor($capacity)) {
                return false;
            }
        }

        return true;
    }

    /** @var ?list<string> Memoised per tick, like the limits. */
    private ?array $families = null;

    /**
     * The hardware families with at least one active worker: CPU for nodes without accel.
     *
     * @return list<string>
     */
    private function families(): array
    {
        return $this->families ??= Node::worker()->active()
            ->pluck('accel')
            ->map(fn (?NodeAccel $accel) => $accel->value ?? Template::CPU)
            ->unique()
            ->values()
            ->all();
    }

    /** @var array<string, int> Memoised per tick; the fleet cannot change mid-run. */
    private array $limits = [];

    /**
     * How many videos this hardware family may have in flight: its active nodes, plus one.
     *
     * Derived rather than configured, because a fixed number drifts the moment the fleet changes —
     * and it drifts silently, leaving nodes idle or the queue starved until someone remembers to
     * edit it and redeploy.
     *
     * One video per node is already generous: a node runs several encode processes and a single
     * video fans out into tens of chunk jobs, so it saturates that node many times over. The extra
     * one covers preparation — a video that is still downloading and probing its source has
     * produced no chunks yet, so without it a one-node family sits idle through every prep.
     *
     * A family with no nodes at all lands on 1, which never dispatches anything: `missingCapacity()`
     * below turns those away first, and says why.
     */
    private function limitFor(string $capacity): int
    {
        return $this->limits[$capacity] ??= 1 + Node::worker()->active()
            ->when(
                $capacity === Template::CPU,
                fn ($q) => $q->whereNull('accel'),
                fn ($q) => $q->where('accel', $capacity),
            )->count();
    }

    /**
     * How many videos already occupy each hardware family.
     *
     * @return array<string, int>
     */
    private function inFlightByCapacity(): array
    {
        $counts = [];

        // get(), not cursor(): the set is bounded by these very limits, so it is small, and the
        // template relation is genuinely eager-loaded instead of queried per row.
        foreach (Video::whereIn('status', self::IN_FLIGHT)->with(['template', 'templateVersion'])->get() as $video) {
            foreach ($this->capacitiesOf($video) as $capacity) {
                $counts[$capacity] = ($counts[$capacity] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * The hardware families a video's chunks will land on.
     *
     * Read from the pinned template VERSION, not the streams: a pending video has none yet — they are created when
     * {@see PrepareVideoJob} probes the source — and the routing is decided by the codec the
     * template asks for either way.
     *
     * @return list<string>
     */
    private function capacitiesOf(Video $video): array
    {
        return $video->encodingDefinition()?->capacities() ?: [Template::CPU];
    }
}
