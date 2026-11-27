<?php

namespace App\Http\Controllers\Api;

use App\Data\ActivityLogData;
use App\Data\Node\DeployNodeData;
use App\Data\Node\DeployNodesData;
use App\Data\Node\StopNodeData;
use App\Enums\NodeAction;
use App\Enums\NodeType;
use App\Http\Controllers\Controller;
use App\Models\Node;
use App\Services\NodeOperationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\LaravelData\Optional;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NodeOperationController extends Controller
{
    /** How long one event stream stays open before the browser is left to reconnect. */
    private const STREAM_SECONDS = 30;

    public function __construct(private NodeOperationService $operations) {}

    public function index(Request $request): JsonResponse
    {
        $page = Activity::inLog('node')
            ->when($request->query('node'), fn ($q, $id) => $q->where('subject_type', Node::class)->where('subject_id', $id))
            ->latest('id')
            ->paginate(20);

        return response()->json([
            'data' => array_map(fn ($a) => ActivityLogData::fromModel($a), $page->items()),
            'currentPage' => $page->currentPage(),
            'perPage' => $page->perPage(),
            'total' => $page->total(),
        ]);
    }

    public function lines(Request $request, Activity $operation): JsonResponse
    {
        abort_unless($operation->log_name === 'node', 404);
        $after = max(0, $request->integer('after'));
        $lines = $this->operations->lines($operation->id, $after);

        return response()->json([
            'lines' => $lines,
            'next' => $after + count($lines),
            'status' => $operation->properties['status'],
        ]);
    }

    /**
     * The same output as `lines()`, pushed as Server-Sent Events: one `lines` event per second
     * while there is something new, and a `done` event with the final status, after which the
     * stream closes. Each event's `id` is the cursor, so the browser's automatic reconnect resumes
     * where it left off (`Last-Event-ID`) and never repeats or skips a line.
     *
     * A stream holds a PHP-FPM worker for as long as it is open, and a deploy runs for minutes, so
     * each one closes after STREAM_SECONDS and lets the browser reconnect: an admin watching a long
     * deploy costs one worker a second at a time, never one for the whole deploy. Something is
     * written every tick (a comment when there is nothing new), because PHP only notices a
     * disconnected viewer when a write fails.
     */
    public function stream(Request $request, Activity $operation): StreamedResponse
    {
        abort_unless($operation->log_name === 'node', 404);
        $after = max(0, (int) ($request->header('Last-Event-ID') ?? $request->integer('after')));

        return response()->stream(function () use ($operation, $after) {
            // PHP buffers output (output_buffering = 4096 in the image) on top of nginx, and
            // flush() alone empties only the latter: without ob_flush() every event waited for
            // 4 KB or for the stream to close.
            $send = function (string $chunk): void {
                echo $chunk;
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };

            $deadline = microtime(true) + self::STREAM_SECONDS;
            $send("retry: 1000\n\n");

            do {
                // Status before lines: a job appends its last line and then marks itself
                // finished, so lines read after a final status are all of them.
                $status = $operation->refresh()->properties['status'];
                $lines = $this->operations->lines($operation->id, $after);

                if ($lines) {
                    $after += count($lines);
                    $send("id: {$after}\nevent: lines\ndata: ".json_encode(['lines' => $lines, 'status' => $status])."\n\n");
                } else {
                    $send(": tick\n\n");
                }

                if (! in_array($status, ['queued', 'running'], true)) {
                    $send("event: done\ndata: ".json_encode(['status' => $status])."\n\n");

                    return;
                }

                if (connection_aborted()) {
                    return;
                }
                sleep(1);
            } while (microtime(true) < $deadline);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            // nginx (the image's, in front of PHP-FPM) buffers a response unless told not to,
            // which would hold the events until the stream closes.
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function deploy(DeployNodeData $data, Node $node): JsonResponse
    {
        // The disk rules and the local-panel default are unchanged from the old SSE endpoint:
        // null past this point means "every spare disk", which only a worker may reach. The
        // shared list has no such reading: absent is empty, and empty shares nothing.
        $disks = $data->disks instanceof Optional ? null : $data->disks;
        if ($disks === null && app()->isLocal()) {
            $disks = [];
        }
        abort_if($disks === null && $node->type === NodeType::PROXY, 422, 'A proxy deploy needs the list of disks to format (an empty list formats none).');

        return $this->queue($this->operations->begin($node, NodeAction::Deploy, $data->force, $disks, sharedDisks: $data->sharedDisks));
    }

    public function start(Node $node): JsonResponse
    {
        return $this->queue($this->operations->begin($node, NodeAction::Start));
    }

    public function stop(StopNodeData $data, Node $node): JsonResponse
    {
        return $this->queue($this->operations->begin($node, NodeAction::Stop, $data->force));
    }

    public function deployMany(DeployNodesData $data): JsonResponse
    {
        $batch = (string) Str::ulid();
        $started = [];
        $skipped = [];

        foreach (Node::whereKey($data->nodes)->get() as $node) {
            // A stopped node stays stopped: a deploy activates what it deploys, and one taken out
            // for maintenance must not come back because the fleet was updated — nor, if its host
            // is down, fail and cancel every proxy chained after it.
            //
            // `[]` for proxies: a fleet deploy updates what is there and never formats a disk
            // nobody was shown. Adding disks to a pool stays a per-node deploy.
            $op = $node->is_active
                ? $this->operations->begin($node, NodeAction::Deploy, $data->force, [], $batch)
                : null;
            $op ? $started[] = $op : $skipped[] = $node->id;
        }

        $this->operations->dispatch(...$started);

        return response()->json([
            'data' => array_map(fn ($op) => ActivityLogData::fromModel($op), $started),
            'skipped' => $skipped,
        ], 202);
    }

    private function queue(?Activity $op): JsonResponse
    {
        abort_if($op === null, 409, 'This node already has an operation running.');
        $this->operations->dispatch($op);

        return response()->json(['data' => ActivityLogData::fromModel($op)], 202);
    }
}
