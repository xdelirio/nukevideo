<?php

namespace App\Http\Controllers\Api;

use App\Data\Analytics\AnalyticsCardData;
use App\Data\Analytics\AnalyticsData;
use App\Data\Analytics\BandwidthByVideoData;
use App\Data\Analytics\BandwidthPointData;
use App\Data\Analytics\DashboardQueryData;
use App\Data\Analytics\EdgeDeliveryData;
use App\Data\Analytics\EdgeDeliveryQueryData;
use App\Data\Analytics\EncodingPointData;
use App\Data\Analytics\SharedIpData;
use App\Data\Analytics\SharedIpsQueryData;
use App\Data\Analytics\TopExternalUserData;
use App\Data\Analytics\TopIpData;
use App\Data\Analytics\TopTrackingIdData;
use App\Data\Analytics\TopVideoData;
use App\Data\Analytics\TrackingIdBytesData;
use App\Data\Analytics\TrackingIdBytesQueryData;
use App\Data\Analytics\TrackingIdIpData;
use App\Data\Analytics\TrackingIdIpsQueryData;
use App\Data\Analytics\VideoBytesData;
use App\Data\Analytics\VideoBytesQueryData;
use App\Enums\MetricUnit;
use App\Enums\UsageGranularity;
use App\Enums\VideoStatus;
use App\Http\Controllers\Controller;
use App\Models\Node;
use App\Models\Video;
use App\Services\AnalyticsService;
use App\Support\TrackingId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AnalyticsController extends Controller
{
    public function __construct(
        private AnalyticsService $analyticsService,
    ) {}

    public function queueStatus(): JsonResponse
    {
        $statuses = [
            VideoStatus::PENDING,
            VideoStatus::RUNNING,
            VideoStatus::FAILED,
        ];

        $counts = Video::query()
            ->whereIn('status', array_map(fn ($s) => $s->value, $statuses))
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $queue = [];
        foreach ($statuses as $status) {
            $queue[$status->value] = $counts[$status->value] ?? 0;
        }

        return response()->json(['data' => $queue]);
    }

    /**
     * Per-node delivery for the nodes page. Admin-only, unlike the rest of this controller: it
     * names the operator's infrastructure and its origin egress, which no project key has any
     * business reading.
     */
    public function edges(EdgeDeliveryQueryData $data): JsonResponse
    {
        return response()->json([
            'data' => EdgeDeliveryData::collect($this->analyticsService->edgeDelivery($data->from, $data->to)),
        ]);
    }

    /**
     * Delivered bytes for a batch of tracking ids, split by delivery metric. The endpoint an
     * integrator bills a per-subscriber bandwidth quota from: `index()` can already narrow to one
     * id, but that is one request and one full `AnalyticsData` per subscriber per period, to read
     * one number out of each.
     *
     * `GET` and `POST` both, for one reason: the input is a list. A thousand ids do not fit in a
     * query string, and a caller batching at that size has nowhere to put them but a body. Nothing
     * is written either way — the action reads.
     *
     * Reachable with a project API key, like the rest of this controller bar `edges()`, and for
     * the same reason: reading these numbers back is what an integrating backend holds a key for.
     * It reads no `$request->user()`, which for a project key is a Project and not a User.
     */
    public function trackingIds(TrackingIdBytesQueryData $data): JsonResponse
    {
        return response()->json([
            'data' => TrackingIdBytesData::collect($this->analyticsService->bytesByTrackingIds(
                $data->from,
                $data->to,
                $data->ids(),
                $data->metric,
                $data->granularity === UsageGranularity::DAILY,
            )),
        ]);
    }

    /**
     * Delivered bytes for a batch of the project's videos — per-title reporting, the same shape as
     * `trackingIds()` on the other dimension.
     *
     * Scoped to the caller's project in the query itself, by `project_id`. It used to need a
     * round trip to MariaDB first, to narrow the ULIDs to the ones the project owns, because
     * `usage_daily` had no column to say whose a row was; the column removed both the query and the
     * chance of getting that narrowing wrong.
     *
     * A ULID that belongs to someone else and one that moved no bytes produce the same answer — no
     * row — deliberately: answering differently would turn this into a way to find out which
     * videos exist.
     */
    public function videos(Request $request, VideoBytesQueryData $data): JsonResponse
    {
        return response()->json([
            'data' => VideoBytesData::collect($this->analyticsService->bytesByVideos(
                $data->from,
                $data->to,
                $data->videos,
                $data->metric,
                $data->granularity === UsageGranularity::DAILY,
                $request->project()->id,
            )),
        ]);
    }

    /**
     * The whole delivery dashboard in one payload: the cards, the series and every breakdown.
     *
     * Kept whole for the integrators that read it, and built from the same pieces as the
     * per-panel endpoints below, so the two can never answer differently. The panel itself uses
     * those instead: one payload is as slow as its slowest breakdown, and nothing on the page can
     * show until all of them are done.
     */
    public function index(Request $request, DashboardQueryData $query): JsonResponse
    {
        $projectId = $this->projectId($request);

        return response()->json([
            'data' => new AnalyticsData(
                cards: $this->cardsFor($query, $projectId),
                bandwidthOverTime: $this->bandwidthOverTimeFor($query, $projectId),
                topIps: $this->topIpsFor($query, $projectId),
                topVideos: $this->topVideosFor($query, $projectId),
                topExternalUsers: $this->topExternalUsersFor($query, $projectId),
                topTrackingIds: $this->topTrackingIdsFor($query, $projectId),
                bandwidthByVideo: $this->bandwidthByVideoFor($query, $projectId),
                encodingOverTime: EncodingPointData::collect($this->analyticsService->encodingUsageOverTime($query->from, $query->to)),
            ),
        ]);
    }

    /*
     * One endpoint per dashboard panel, each answering exactly what its panel draws, so the page
     * asks for all of them at once and shows each as it lands. Same query, same project rules
     * and same payload pieces as `index()`.
     */

    public function summary(Request $request, DashboardQueryData $query): JsonResponse
    {
        return response()->json(['data' => $this->cardsFor($query, $this->projectId($request))]);
    }

    public function bandwidth(Request $request, DashboardQueryData $query): JsonResponse
    {
        return response()->json(['data' => $this->bandwidthOverTimeFor($query, $this->projectId($request))]);
    }

    public function topIps(Request $request, DashboardQueryData $query): JsonResponse
    {
        return response()->json(['data' => $this->topIpsFor($query, $this->projectId($request))]);
    }

    public function topVideos(Request $request, DashboardQueryData $query): JsonResponse
    {
        return response()->json(['data' => $this->topVideosFor($query, $this->projectId($request))]);
    }

    public function topTrackingIds(Request $request, DashboardQueryData $query): JsonResponse
    {
        return response()->json(['data' => $this->topTrackingIdsFor($query, $this->projectId($request))]);
    }

    public function topExternalUsers(Request $request, DashboardQueryData $query): JsonResponse
    {
        return response()->json(['data' => $this->topExternalUsersFor($query, $this->projectId($request))]);
    }

    /**
     * Addresses several tracking ids were served to, for catching one person behind many accounts.
     * Requires project context — the `project()` macro aborts without it — because it is a list of
     * viewer addresses and viewer labels, which only the project's own traffic may answer with.
     */
    public function sharedIps(Request $request, SharedIpsQueryData $query): JsonResponse
    {
        return response()->json([
            'data' => SharedIpData::collect(array_map(fn (array $row) => [
                'ip' => $row['ip'],
                'country' => $row['country'],
                'trackingIdCount' => $row['tracking_id_count'],
                'bytes' => $row['bytes'],
                'days' => $row['days'],
                'trackingIds' => $row['tracking_ids'],
            ], $this->analyticsService->sharedIps($query->from, $query->to, $request->project()->id, $query->minimum(), $query->size(), maxTrackingIds: $query->maxTrackingIds))),
        ]);
    }

    /** Every address one tracking id was served to: the other direction of {@see sharedIps()}. */
    public function trackingIdIps(Request $request, TrackingIdIpsQueryData $query, string $trackingId): JsonResponse
    {
        // The path segment is the only thing here not validated by the Data object. An id outside
        // the alphabet could never have been stored, so it is refused as malformed, not looked up.
        if (! TrackingId::isValid($trackingId)) {
            throw ValidationException::withMessages(['trackingId' => 'Not a tracking id: 1 to 64 characters of A-Z a-z 0-9 _ -.']);
        }

        return response()->json([
            'data' => TrackingIdIpData::collect(array_map(fn (array $row) => [
                'ip' => $row['ip'],
                'country' => $row['country'],
                'bytes' => $row['bytes'],
                'requests' => $row['requests'],
                'days' => $row['days'],
                'firstSeen' => $row['first_seen'],
                'lastSeen' => $row['last_seen'],
            ], $this->analyticsService->trackingIdIps($query->from, $query->to, $request->project()->id, $trackingId, $query->size()))),
        ]);
    }

    /**
     * Read straight off the attribute rather than through the `project()` macro, which aborts:
     * these endpoints answer with a project and without one, and the difference is what they may
     * show rather than whether they answer.
     *
     * Whatever project the caller named, and nothing else — no account fallback and no operator
     * exception. `?user_id=` used to let anyone name an account here and read its upload volume
     * and customer labels; there is now no account axis on these endpoints at all, which is a
     * shorter way of saying the same fix.
     */
    private function projectId(Request $request): ?int
    {
        return $request->attributes->get('resolved_project')?->id;
    }

    /**
     * A breakdown that NAMES things — viewer addresses, viewer labels, customer labels, video
     * ULIDs — answered only with project context. A total is instance-wide and names nobody, which
     * is the trade this endpoint has always documented; a LIST OF IDENTIFIERS is a different thing
     * entirely — unnarrowed it enumerates other tenants', and the limit that caps it is the
     * caller's. Without a project it comes back empty, and the read never runs.
     *
     * @param  callable(): array  $read
     */
    private function named(?int $projectId, callable $read): array
    {
        return $projectId === null ? [] : $read();
    }

    /** @return array<int, AnalyticsCardData> */
    private function cardsFor(DashboardQueryData $query, ?int $projectId): array
    {
        $summary = $this->analyticsService->summary($query->from, $query->to, $query->video, $query->trackingIdFilter(), $query->metric, $projectId);
        $encoding = $this->analyticsService->encodingUsage($query->from, $query->to);
        $usage = $this->analyticsService->usageSummary($query->from, $query->to, $projectId);

        // Keys, not English. A client maps these to its own words; the API has no opinion about
        // what a card is called or in which language.
        return AnalyticsCardData::collect([
            ['key' => 'total_bandwidth', 'value' => $summary['total_bytes'], 'unit' => MetricUnit::BYTES],
            ['key' => 'unique_ips', 'value' => $summary['unique_ips'], 'unit' => MetricUnit::COUNT],
            ['key' => 'active_videos', 'value' => $summary['unique_videos'], 'unit' => MetricUnit::COUNT],
            ['key' => 'tracking_ids', 'value' => $summary['unique_tracking_ids'], 'unit' => MetricUnit::COUNT],
            ['key' => 'nodes', 'value' => Node::count(), 'unit' => MetricUnit::COUNT],
            ['key' => 'cpu_encoding', 'value' => $encoding['cpu'], 'unit' => MetricUnit::SECONDS],
            ['key' => 'upload_volume', 'value' => $usage['upload_bytes'], 'unit' => MetricUnit::BYTES],
        ]);
    }

    /** @return array<int, BandwidthPointData> */
    private function bandwidthOverTimeFor(DashboardQueryData $query, ?int $projectId): array
    {
        return BandwidthPointData::collect($this->analyticsService->bandwidthOverTime($query->from, $query->to, $query->video, $query->trackingIdFilter(), $query->metric, $projectId));
    }

    /** @return array<int, TopIpData> */
    private function topIpsFor(DashboardQueryData $query, ?int $projectId): array
    {
        return $this->named($projectId, fn () => TopIpData::collect($this->analyticsService->topIps($query->from, $query->to, limit: $query->topN(), video: $query->video, trackingId: $query->trackingIdFilter(), metric: $query->metric, projectId: $projectId)));
    }

    /** @return array<int, TopVideoData> */
    private function topVideosFor(DashboardQueryData $query, ?int $projectId): array
    {
        return $this->named($projectId, fn () => TopVideoData::collect($this->analyticsService->topVideos($query->from, $query->to, limit: $query->topN(), video: $query->video, trackingId: $query->trackingIdFilter(), metric: $query->metric, projectId: $projectId)));
    }

    /** @return array<int, TopTrackingIdData> */
    private function topTrackingIdsFor(DashboardQueryData $query, ?int $projectId): array
    {
        return $this->named($projectId, fn () => TopTrackingIdData::collect($this->analyticsService->bandwidthByTrackingId($query->from, $query->to, limit: $query->topN(), video: $query->video, trackingId: $query->trackingIdFilter(), metric: $query->metric, projectId: $projectId)));
    }

    /** @return array<int, BandwidthByVideoData> */
    private function bandwidthByVideoFor(DashboardQueryData $query, ?int $projectId): array
    {
        return $this->named($projectId, fn () => BandwidthByVideoData::collect($this->analyticsService->bandwidthByVideo($query->from, $query->to, limit: $query->seriesTopN(), video: $query->video, trackingId: $query->trackingIdFilter(), metric: $query->metric, projectId: $projectId)));
    }

    /** @return array<int, TopExternalUserData> */
    private function topExternalUsersFor(DashboardQueryData $query, ?int $projectId): array
    {
        return $this->named($projectId, fn () => TopExternalUserData::collect($this->analyticsService->topExternalUsers($query->from, $query->to, $projectId, $query->topN())));
    }
}
