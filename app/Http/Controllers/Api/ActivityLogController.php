<?php

namespace App\Http\Controllers\Api;

use App\Data\ActivityLog\IndexActivityLogData;
use App\Data\ActivityLogData;
use App\Http\Controllers\Controller;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

class ActivityLogController extends Controller
{
    public function index(Request $request, IndexActivityLogData $filters): JsonResponse
    {
        $project = $request->project();

        // The visibility rule is grouped on its own: the admin's `orWhere` below would otherwise
        // take the filters with it, and `event=video_failed` would still list every node operation.
        $activities = Activity::where(function ($query) use ($project, $request, $filters) {
            $query->whereHasMorph(
                'subject',
                [Video::class],
                fn ($video) => $video->where('project_id', $project->id)
                    ->when($filters->video, fn ($video) => $video->where('ulid', $filters->video)),
            )
                // Node operations belong to no project; the operator sees them in every one. A
                // project key authenticates as the Project, which has no `is_admin` at all. Not when
                // one video is asked for: a node operation is no video's event.
                ->when($request->user()?->is_admin && ! $filters->video, fn ($query) => $query->orWhere('log_name', 'node'));
        })
            ->when($filters->event, fn ($query) => $query->where('event', $filters->event))
            ->latest()
            ->paginate(20);

        return response()->json([
            'data' => array_map(fn ($a) => ActivityLogData::fromModel($a), $activities->items()),
            'currentPage' => $activities->currentPage(),
            'perPage' => $activities->perPage(),
            'total' => $activities->total(),
        ]);
    }
}
