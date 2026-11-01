<?php

namespace App\Http\Controllers\Api;

use App\Data\ExternalUser\DeleteExternalUserVideosData;
use App\Data\ExternalUser\ExternalUserListItemData;
use App\Data\ExternalUser\ExternalUserQueryData;
use App\Data\ExternalUser\ExternalUserUsageQueryData;
use App\Data\ExternalUser\IndexExternalUsersData;
use App\Enums\UsageGranularity;
use App\Http\Controllers\Controller;
use App\Services\ExternalUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * A project's customers — the `externalUserId` an integration uploads with — as something it can
 * read and act on directly, instead of rebuilding them from the video listing and the metrics.
 *
 * Every action requires project context and reads inside that project only
 * ({@see ExternalUserService}): the id is the integration's own free text, unique per project and
 * nowhere else, so read across projects it would merge different people that happen to share one.
 */
class ExternalUserController extends Controller
{
    public function __construct(
        private ExternalUserService $externalUsers,
    ) {}

    public function index(Request $request, IndexExternalUsersData $query): JsonResponse
    {
        $page = $this->externalUsers->paginate($request->project(), $query);

        return response()->json([
            'data' => array_map(fn (object $row) => new ExternalUserListItemData(
                externalUserId: $row->external_user_id,
                videos: (int) $row->videos,
                storageBytes: (int) $row->storage_bytes,
                lastUploadAt: Carbon::parse($row->last_upload_at)->toIso8601String(),
            ), $page->items()),
            'currentPage' => $page->currentPage(),
            'perPage' => $page->perPage(),
            'total' => $page->total(),
        ]);
    }

    public function show(Request $request, ExternalUserQueryData $query, string $externalUserId): JsonResponse
    {
        return response()->json([
            'data' => $this->externalUsers->summary($request->project(), $externalUserId, $query->from, $query->to),
        ]);
    }

    /**
     * `GET` and `POST` both, like the other batch reads: the input is a list of up to a thousand
     * ids, which does not fit in a query string. Nothing is written either way.
     */
    public function usage(Request $request, ExternalUserUsageQueryData $query): JsonResponse
    {
        return response()->json([
            'data' => $this->externalUsers->usage(
                $request->project(),
                array_values(array_unique($query->externalUserIds)),
                $query->from,
                $query->to,
                $query->metric,
                $query->granularity === UsageGranularity::DAILY,
            ),
        ]);
    }

    /**
     * Deletes every video of the customer — what an integration calls when one of its own users
     * closes their account. Answers 202: the deletion is queued, and videos still being processed
     * are left alone and counted, so the caller knows to ask again once they finish.
     */
    public function destroyVideos(Request $request, DeleteExternalUserVideosData $data, string $externalUserId): JsonResponse
    {
        if (! hash_equals($externalUserId, $data->confirm)) {
            throw ValidationException::withMessages([
                'confirm' => 'Repeat the external user id from the path in `confirm` to delete its videos.',
            ]);
        }

        return response()->json([
            'data' => $this->externalUsers->queueVideoDeletion($request->project(), $externalUserId),
        ], 202);
    }
}
