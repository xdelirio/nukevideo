<?php

namespace App\Http\Controllers\Api;

use App\Data\Analytics\MetricsQueryData;
use App\Enums\MetricDimension;
use App\Enums\MetricMeasure;
use App\Enums\MetricShape;
use App\Enums\UsageMetric;
use App\Http\Controllers\Controller;
use App\Services\AnalyticsService;
use App\Services\MetricShaper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The general read over `usage_daily`: whatever breakdown a consumer needs, without a new endpoint each
 * time it needs a different one.
 *
 * This controller is where the authorization lives, and it is the reason a general query endpoint
 * over a shared table is defensible at all. The dimensions are not equally shareable
 * ({@see MetricDimension} spells out why), so each one states what it requires and this action
 * enforces it before any SQL is built.
 *
 * The rule, in one sentence: a dimension that NAMES something is answered only when the query is
 * narrowed to a project, or when the caller names the values it is asking about. There is no
 * operator shortcut — an administrator that names no project is refused exactly like anyone else,
 * because the fleet-wide view lives on the admin-only per-node report and not here.
 *
 * On top of that, the customer-label dimension and the metrics booked per account pin the query to
 * the caller's own account, which is a different axis from the project and narrows further.
 */
class MetricsController extends Controller
{
    public function __construct(
        private AnalyticsService $analytics,
        private MetricShaper $shaper,
    ) {}

    public function query(Request $request, MetricsQueryData $data): JsonResponse
    {
        // Read off the attribute rather than through the `project()` macro, which aborts: this
        // endpoint answers with a project and without one, and which dimensions it will break down
        // by is what changes.
        $projectId = $request->attributes->get('resolved_project')?->id;

        $this->assertDimensionsAllowed($data, $projectId !== null);
        $this->assertMeasuresFitShape($data);

        $rows = $this->analytics->query(
            $data->from,
            $data->to,
            $data->dimensions(),
            [
                'tracking_id' => $data->list('trackingIds'),
                'external_user_id' => $data->list('externalUserIds'),
                // Taken as given: `project_id` in the query is what makes a ULID from another
                // tenant match nothing, so there is no narrowing left to get wrong here.
                'video_ulid' => $data->list('videos'),
            ],
            $data->list('metrics'),
            $this->accountScope($request->user(), $data),
            $projectId,
            $data->measures(),
        );

        return response()->json([
            'data' => $data->shape === MetricShape::WIDE
                ? $this->shaper->wide($rows, $data->dimensions(), $data->from, $data->to)
                : $rows,
        ]);
    }

    /**
     * Refuses a dimension the query is not narrow enough to answer, before anything is read.
     *
     * Two ways to be narrow enough: the query is scoped to one project, in which case what comes
     * back is the caller's own, or the caller names the values it is asking about, which bounds the
     * question to things it already had. Neither, and grouping by an identifier would enumerate
     * whoever else is on the installation.
     *
     * A 422 and not a 403: the request is being rejected on the content of one field, the message
     * has to name which, and the remedy is a different query rather than different credentials.
     */
    private function assertDimensionsAllowed(MetricsQueryData $data, bool $scoped): void
    {
        if ($scoped) {
            return;
        }

        foreach ($data->dimensions() as $dimension) {
            if (! $dimension->requiresScope()) {
                continue;
            }

            $named = $dimension->namedBy();

            if ($named !== null && $data->list($named) !== []) {
                continue;
            }

            throw ValidationException::withMessages([
                // Keyed and named by the field as the caller spells it, which is the property's
                // own name now that requests are camelCase throughout.
                $named === null ? 'dimensions' : $named => $named === null
                    ? "Breaking down by [{$dimension->value}] needs project context: send X-Project-Ulid,"
                        .' or call with a project API key. It describes the edges that served your traffic,'
                        .' so there is no list of your own you could name instead.'
                    : "Breaking down by [{$dimension->value}] needs either project context (send"
                        .' X-Project-Ulid, or use a project API key) or an explicit list of your own '
                        .$named.'.',
            ]);
        }
    }

    /**
     * The wide shape pivots `value` into one field per metric; a second measure has no field of its
     * own to go in, and folding it into the pivot would make `streaming_bytes` mean bytes in one
     * response and requests in the next. Refused rather than silently dropped.
     */
    private function assertMeasuresFitShape(MetricsQueryData $data): void
    {
        $extra = array_filter($data->measures(), fn (MetricMeasure $m) => $m !== MetricMeasure::VALUE);

        if ($data->shape === MetricShape::WIDE && $extra !== []) {
            throw ValidationException::withMessages([
                'measures' => 'The wide shape carries the sum only. Ask for other measures with the long shape.',
            ]);
        }
    }

    /**
     * The account to pin the query to, or null for the instance.
     *
     * Pinned as soon as the query touches something keyed by account — the customer labels, or the
     * upload and encoding metrics — because those numbers are somebody's in a way delivered bytes
     * are not: `external_user_id` IS the integrator's own customer identifier, and reporting it
     * across accounts hands one tenant another's customers. Null otherwise, which leaves delivered
     * bytes as wide as the project scope alongside makes them.
     */
    private function accountScope(Model $caller, MetricsQueryData $data): ?int
    {
        $touchesAccountData = $data->has(MetricDimension::EXTERNAL_USER_ID)
            || $data->list('externalUserIds') !== []
            || array_diff($data->list('metrics'), UsageMetric::delivery()) !== [];

        return $touchesAccountData ? $caller->accountId() : null;
    }
}
