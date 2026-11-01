<?php

namespace App\Services;

use App\Data\Analytics\MetricsQueryData;
use App\Data\Analytics\TrackingIdBytesQueryData;
use App\Data\Stream\DownloadStreamData;
use App\Enums\MetricDimension;
use App\Enums\UsageMetric;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\MetricsController;
use App\Jobs\IngestBandwidthJob;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every read over `usage_daily` and its rollups. Written against the query builder and nothing
 * database-specific: the tables live in MySQL or MariaDB and the suite runs them on SQLite, so a
 * function only one of them has is a query only one of them can test.
 *
 * The dashboard reads ({@see summary()}, the series and the rankings) go to the per-dimension
 * rollups whenever they can ({@see delivered()}); `usage_daily` answers the rest: the filtered
 * views, the per-row distinct counts of the rankings, billing and the general query.
 */
class AnalyticsService
{
    /**
     * The top-N size every flat breakdown falls back to. Public and named because those sizes are
     * request parameters now, so the controller and the queries would otherwise each carry their
     * own copy of the same 10 and drift.
     */
    public const TOP_N_DEFAULT = 10;

    /**
     * The default for {@see bandwidthByVideo()}, smaller for a reason: that one is a time series,
     * so its row count is the limit TIMES the length of the range, where the flat breakdowns
     * return the limit itself.
     */
    public const SERIES_TOP_N_DEFAULT = 5;

    private static function usage(string $from, string $to): Builder
    {
        return DB::table(UsageService::TABLE)->whereBetween('date', [$from, $to]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function rows(Builder $query): array
    {
        return $query->get()->map(fn (object $row) => (array) $row)->all();
    }

    /**
     * The predicate shared by every bandwidth query. The metric constraint is not optional: without
     * it these queries would read upload volume and encoding seconds as if they were delivered
     * bytes.
     *
     * The filters are optional and every one is bound — `video_ulid` and `tracking_id` are written
     * from CDN access logs, so their values are caller-controlled text that must never reach the
     * statement itself.
     */
    private function bandwidth(string $from, string $to, ?string $video, ?string $trackingId, ?string $metric = null, ?int $projectId = null): Builder
    {
        $query = self::scoped(self::usage($from, $to), $metric, $projectId);

        if ($video !== null && $video !== '') {
            $query->where('video_ulid', $video);
        }

        // An empty string is a legitimate value here — it is the column default and means "traffic
        // that carried no tracking id" — so the filter is applied on `!== null`, not on emptiness.
        // Asking for it is how a caller isolates its unattributed traffic.
        if ($trackingId !== null) {
            $query->where('tracking_id', $trackingId);
        }

        return $query;
    }

    /**
     * The delivery set, the tenant and the metric: the part of the predicate `usage_daily` and the
     * rollups share, since every one of them carries those three columns.
     */
    private static function scoped(Builder $query, ?string $metric, ?int $projectId): Builder
    {
        $query->whereIn('metric', UsageMetric::delivery());

        // The only clause that can narrow this table to one tenant. Everything else here is a
        // filter a caller chooses; this one is imposed on it.
        if ($projectId !== null) {
            $query->where('project_id', $projectId);
        }

        // Narrowing to a single delivery metric — streaming alone, downloads alone. Still inside
        // the constant set above, so an unknown metric simply matches nothing rather than reaching
        // across into upload or encoding rows.
        if ($metric !== null && $metric !== '') {
            $query->where('metric', $metric);
        }

        return $query;
    }

    /**
     * The cheapest source for a delivery read that sums `value` over a single dimension: that
     * dimension's rollup ({@see UsageService::ROLLUPS}) when it can answer, `usage_daily` when it
     * cannot.
     *
     * A rollup can answer whenever no video or tracking id filter was asked for. Those filters
     * name a column the rollup does not keep — the address rollup has no video in it — and a
     * filtered view is small anyway: `usage_daily` reaches it through the index that leads with
     * the filtered column. Unfiltered, the rollup holds a twelfth of the rows for the same answer.
     *
     * The query reads the same way on either table: `date`, `project_id`, `metric`, `value` and
     * `$column` exist on both, so a caller writes it once and never knows which one it got.
     *
     * @param  string  $column  `video_ulid`, `ip` or `tracking_id` — class-controlled, never input
     */
    private function delivered(string $column, string $from, string $to, ?string $video, ?string $trackingId, ?string $metric, ?int $projectId): Builder
    {
        if (($video !== null && $video !== '') || $trackingId !== null) {
            return $this->bandwidth($from, $to, $video, $trackingId, $metric, $projectId);
        }

        $table = array_search($column, UsageService::ROLLUPS, true);

        return self::scoped(DB::table($table)->whereBetween('date', [$from, $to]), $metric, $projectId);
    }

    public function summary(string $from, string $to, ?string $video = null, ?string $trackingId = null, ?string $metric = null, ?int $projectId = null): array
    {
        // One read per distinct count, each over the rollup that keeps its column. The total rides
        // along with the addresses: every delivered byte is in exactly one address row, so the sum
        // there is the sum of everything.
        $ips = $this->delivered('ip', $from, $to, $video, $trackingId, $metric, $projectId)
            ->selectRaw('sum(value) as total_bytes')
            ->selectRaw('count(distinct ip) as unique_ips')
            ->first();

        $videos = $this->delivered('video_ulid', $from, $to, $video, $trackingId, $metric, $projectId)
            ->selectRaw('count(distinct video_ulid) as unique_videos')
            ->first();

        $trackingIds = $this->delivered('tracking_id', $from, $to, $video, $trackingId, $metric, $projectId)
            ->selectRaw('count(distinct tracking_id) as unique_tracking_ids')
            ->first();

        return [
            'total_bytes' => (int) ($ips->total_bytes ?? 0),
            'unique_videos' => (int) ($videos->unique_videos ?? 0),
            'unique_ips' => (int) ($ips->unique_ips ?? 0),
            'unique_tracking_ids' => (int) ($trackingIds->unique_tracking_ids ?? 0),
        ];
    }

    public function bandwidthOverTime(string $from, string $to, ?string $video = null, ?string $trackingId = null, ?string $metric = null, ?int $projectId = null): array
    {
        // Off the address rollup alone: its rows add up to every byte of the day, and its distinct
        // addresses are the day's sessions.
        return self::rows(
            $this->delivered('ip', $from, $to, $video, $trackingId, $metric, $projectId)
                ->select('date')
                ->selectRaw('sum(value) as bytes')
                ->selectRaw('count(distinct ip) as sessions')
                ->groupBy('date')
                ->orderBy('date')
        );
    }

    public function topIps(string $from, string $to, int $limit = self::TOP_N_DEFAULT, ?string $video = null, ?string $trackingId = null, ?string $metric = null, ?int $projectId = null): array
    {
        $top = self::rows(
            $this->delivered('ip', $from, $to, $video, $trackingId, $metric, $projectId)
                ->select('ip')
                ->selectRaw('sum(value) as bytes')
                ->groupBy('ip')
                ->orderByDesc('bytes')
                ->limit($limit)
        );

        return $this->countDistinctFor($top, 'ip', 'ip', $this->bandwidth($from, $to, $video, $trackingId, $metric, $projectId), ['sessions' => 'video_ulid']);
    }

    /**
     * The second half of every ranking that carries a distinct count: the count, for the rows that
     * made the top N and no others.
     *
     * These counts cross two dimensions — distinct videos per address, distinct addresses per
     * video — so no single-dimension rollup holds them, and `usage_daily` has to. Counted in the
     * same pass as the ranking they cost seconds: `count(distinct …)` remembers every value of
     * every group across the range — 90k addresses for 13k videos on a production month — to then
     * throw all but ten groups away. Counted after it, they read the winners' own rows through the
     * index that leads with the ranked column, in milliseconds.
     *
     * `$query` is `usage_daily` under the same filters the ranking had, so the count covers the
     * traffic the sum did and the two numbers on a line describe the same bytes.
     *
     * @param  array<int, array<string, mixed>>  $top  the ranking, in order
     * @param  string  $key  the field of `$top` that holds the ranked value
     * @param  string  $column  the `usage_daily` column it came from
     * @param  array<string, string>  $counts  output field => column counted distinct. Both are
     *                                         class-controlled names, never caller input.
     * @return array<int, array<string, mixed>>
     */
    private function countDistinctFor(array $top, string $key, string $column, Builder $query, array $counts): array
    {
        if ($top === []) {
            return [];
        }

        $query->whereIn($column, array_column($top, $key))
            ->select("{$column} as ranked")
            ->groupBy($column);

        foreach ($counts as $field => $counted) {
            $query->selectRaw("count(distinct {$counted}) as {$field}");
        }

        $found = $query->get()->keyBy('ranked');

        return array_map(function (array $row) use ($found, $key, $counts) {
            foreach (array_keys($counts) as $field) {
                $row[$field] = (int) ($found[$row[$key]]->{$field} ?? 0);
            }

            return $row;
        }, $top);
    }

    /**
     * Bandwidth broken down by the caller-supplied tracking id a download link carried
     * ({@see DownloadStreamData}). This is the read side of that feature: without it the id is
     * stored and never surfaces anywhere.
     *
     * Traffic that carried no id is reported under an empty tracking id rather than dropped — it
     * is real bandwidth, and hiding it would make the breakdown fail to add up to the total.
     */
    public function bandwidthByTrackingId(string $from, string $to, int $limit = self::TOP_N_DEFAULT, ?string $video = null, ?string $trackingId = null, ?string $metric = null, ?int $projectId = null): array
    {
        // Ranked first and counted after ({@see countDistinctFor()}). The empty id — unattributed
        // traffic — is usually the largest group by far and still makes the top N, so its count is
        // the one that costs anything here; it is still a fraction of counting every group.
        $top = self::rows(
            $this->delivered('tracking_id', $from, $to, $video, $trackingId, $metric, $projectId)
                ->select('tracking_id')
                ->selectRaw('sum(value) as bytes')
                ->groupBy('tracking_id')
                ->orderByDesc('bytes')
                ->limit($limit)
        );

        return $this->countDistinctFor($top, 'tracking_id', 'tracking_id', $this->bandwidth($from, $to, $video, $trackingId, $metric, $projectId), ['videos' => 'video_ulid', 'unique_ips' => 'ip']);
    }

    /**
     * The general read over `usage_daily`: sum `value` grouped by the dimensions the caller named.
     *
     * Every named endpoint on this service answers one question that was worth naming. This answers
     * the ones that were not — and the ones nobody has asked yet, which for an API that other
     * projects embed is most of them. What keeps it from being an open query surface is that the
     * caller picks {@see MetricDimension} cases and value lists, never column names or SQL: the
     * dimensions become a class-controlled column each, and every value the caller supplies is
     * bound.
     *
     * Authorization is NOT here. Which dimensions a caller may name, and whether the query is
     * pinned to its account or its project, is decided before the call ({@see MetricsController})
     * and arrives as `$accountId` and already-narrowed lists. A service that also policed this
     * would be the second place to get it wrong.
     *
     * The metric constraint is the one thing that is never optional. `value` is a shared double
     * whose unit lives in the metric name, so an unconstrained sum adds encoding seconds to bytes;
     * with no `$metrics` the default is the delivery set, exactly like every other read here.
     *
     * @param  list<MetricDimension>  $dimensions
     * @param  array<string, list<string>>  $filters  keyed by `usage_daily` column
     * @param  list<string>  $metrics  empty for the delivery set
     * @return array<int, array<string, mixed>>
     */
    public function query(string $from, string $to, array $dimensions, array $filters = [], array $metrics = [], ?int $accountId = null, ?int $projectId = null): array
    {
        $query = self::usage($from, $to)
            ->whereIn('metric', $metrics === [] ? UsageMetric::delivery() : array_values($metrics));

        // Pinned to one account when the caller is not entitled to the instance. The column is the
        // owning user resolved from the video at ingest, so it is the same boundary `/api/usage`
        // enforces and it cannot be talked out of by anything in the URL.
        if ($accountId !== null) {
            $query->where('user_id', $accountId);
        }

        // Narrower than the account, and the reason the identifier dimensions can be offered to a
        // tenant at all: an account holds many projects, and `project_id` is what tells them apart.
        if ($projectId !== null) {
            $query->where('project_id', $projectId);
        }

        // The column names are keys this class recognises, not caller text; an unknown one is
        // ignored rather than passed on.
        foreach (['video_ulid', 'tracking_id', 'external_user_id'] as $column) {
            $values = array_values(array_unique($filters[$column] ?? []));

            if ($values !== []) {
                $query->whereIn($column, $values);
            }
        }

        foreach ($dimensions as $dimension) {
            $query->addSelect($dimension->selection())
                ->groupBy($dimension->alias())
                ->orderBy($dimension->alias());
        }

        // One row past the cap, so an oversized result can be refused rather than silently
        // truncated ({@see MetricsQueryData::MAX_ROWS}).
        $rows = self::rows($query->selectRaw('sum(value) as value')->limit(MetricsQueryData::MAX_ROWS + 1));

        // Refused here rather than in each controller: every read that goes through this method is
        // capped by the same number, including the batch endpoints, whose daily granularity can
        // otherwise multiply a thousand values by the length of a year.
        if (count($rows) > MetricsQueryData::MAX_ROWS) {
            throw ValidationException::withMessages([
                'dimensions' => 'This query returns more than '.MetricsQueryData::MAX_ROWS
                    .' rows. Narrow the date range, drop a dimension, or filter the lists.',
            ]);
        }

        return $rows;
    }

    /**
     * Delivered bytes for a batch of tracking ids, split by delivery metric — the ids an integrator
     * minted its playback and download links with ({@see DownloadStreamData}), which is how it
     * meters a per-subscriber bandwidth quota.
     *
     * This is the billing read, and it exists in the shape it does because the dashboard queries
     * answer the wrong question for an invoice. {@see bandwidthByTrackingId()} and
     * {@see topVideos()} answer "who used the most", which is a top-N; billing needs the totals for
     * the values the CALLER names, all of them, whether or not they are in anyone's top N. Doing
     * that through {@see summary()} means one HTTP call and one full `AnalyticsData` per value per
     * period, to read a single number out of each.
     *
     * Split by metric rather than pre-summed, because streaming and the downloads that reupload to
     * a viewer's own file host are not the same line on an invoice; a caller that wants one number
     * adds the rows up, and one that pre-summed could never take them apart again. Values with no
     * traffic in the range produce no row: absence is the answer, and padding a thousand-value
     * request with zeros would make most of the response filler.
     *
     * `$daily` adds the date to the grouping, for a period a subscriber joined or left halfway
     * through. Opt-in, because it multiplies the response by the length of the range.
     *
     * Bounded by construction at `count($values) × count(UsageMetric::delivery())`, times the range
     * when `$daily`, and the request caps the list ({@see TrackingIdBytesQueryData}).
     *
     * Instance-wide unless a project is passed, and the endpoint above it deliberately does not
     * pass one: traffic whose video has been deleted keeps its tracking id but loses its project,
     * and dropping it would under-report what a subscriber actually consumed. Passing
     * `''` among them asks for the traffic whose id did not survive the round trip through the CDN
     * log, which is how a caller reconciles its own ids against the total.
     *
     * @param  list<string>  $trackingIds
     * @return array<int, array{tracking_id: string, metric: string, bytes: float, date?: string}>
     */
    public function bytesByTrackingIds(string $from, string $to, array $trackingIds, ?string $metric = null, bool $daily = false, ?int $projectId = null): array
    {
        return $this->batch(MetricDimension::TRACKING_ID, 'tracking_id', $from, $to, $trackingIds, $metric, $daily, $projectId);
    }

    /**
     * Delivered bytes for a batch of videos, by ULID — the per-title reporting read.
     *
     * Pass `$projectId` and the list needs no vetting: a ULID from another tenant matches no row.
     * That is what `project_id` bought — `video_ulid` here is a string parsed from a public request
     * path and says nothing about who owns it ({@see AnalyticsController::videos()}).
     *
     * @param  list<string>  $videoUlids
     * @return array<int, array{video: string, metric: string, bytes: float, date?: string}>
     */
    public function bytesByVideos(string $from, string $to, array $videoUlids, ?string $metric = null, bool $daily = false, ?int $projectId = null): array
    {
        // The dimension aliases itself to `video`, the name the other video breakdowns already
        // answer with ({@see topVideos()}), so one payload does not spell it two ways.
        return $this->batch(MetricDimension::VIDEO, 'video_ulid', $from, $to, $videoUlids, $metric, $daily, $projectId);
    }

    /**
     * The shared body of the two batch reads: one dimension, its own values, split by metric.
     *
     * Built on {@see query()} rather than beside it: two query builders over one table is how the
     * metric constraint ends up on only one of them. What is left here is what is genuinely
     * theirs: the empty short-circuit, and `value` renamed to `bytes`, which is what their
     * published response calls it and what a delivery-only read can honestly call it.
     *
     * @param  string  $column  the `usage_daily` column the values are matched against. A
     *                          class-controlled name, never caller input — the caller chooses the
     *                          endpoint, not the column.
     * @param  list<string>  $values
     * @return array<int, array<string, mixed>>
     */
    private function batch(MetricDimension $dimension, string $column, string $from, string $to, array $values, ?string $metric, bool $daily, ?int $projectId = null): array
    {
        if ($values === []) {
            return [];
        }

        $dimensions = $daily
            ? [$dimension, MetricDimension::METRIC, MetricDimension::DATE]
            : [$dimension, MetricDimension::METRIC];

        $rows = $this->query(
            $from,
            $to,
            $dimensions,
            [$column => $values],
            $metric === null || $metric === '' ? [] : [$metric],
            projectId: $projectId,
        );

        return array_map(function (array $row) {
            $row['bytes'] = $row['value'];
            unset($row['value']);

            return $row;
        }, $rows);
    }

    public function topVideos(string $from, string $to, int $limit = self::TOP_N_DEFAULT, ?string $video = null, ?string $trackingId = null, ?string $metric = null, ?int $projectId = null): array
    {
        $top = self::rows(
            $this->delivered('video_ulid', $from, $to, $video, $trackingId, $metric, $projectId)
                ->where('video_ulid', '!=', '')
                ->select('video_ulid as video')
                ->selectRaw("'' as external_resource_id")
                ->selectRaw('sum(value) as bytes')
                ->groupBy('video_ulid')
                ->orderByDesc('bytes')
                ->limit($limit)
        );

        // `sessions` and `unique_ips` are the same count under the names the response has always
        // carried. Counted once, and only for the videos that made the list
        // ({@see countDistinctFor()}).
        $rows = $this->countDistinctFor($top, 'video', 'video_ulid', $this->bandwidth($from, $to, $video, $trackingId, $metric, $projectId), ['sessions' => 'ip']);

        return array_map(fn (array $row) => $row + ['unique_ips' => $row['sessions']], $rows);
    }

    public function bandwidthByVideo(string $from, string $to, int $limit = self::SERIES_TOP_N_DEFAULT, ?string $video = null, ?string $trackingId = null, ?string $metric = null, ?int $projectId = null): array
    {
        // Joined rather than `IN (subquery)`: MariaDB refuses a LIMIT inside one. The column takes
        // an alias of its own so the outer filters, which name `video_ulid` bare, stay unambiguous.
        $top = $this->delivered('video_ulid', $from, $to, $video, $trackingId, $metric, $projectId)
            ->where('video_ulid', '!=', '')
            ->select('video_ulid as top_video')
            ->groupBy('video_ulid')
            ->orderByRaw('sum(value) desc')
            ->limit($limit);

        $series = $this->delivered('video_ulid', $from, $to, $video, $trackingId, $metric, $projectId);

        return self::rows(
            $series
                ->joinSub($top, 'top', 'top.top_video', '=', $series->from.'.video_ulid')
                ->select('date', 'video_ulid as video')
                ->selectRaw('sum(value) as bytes')
                ->groupBy('date', 'video_ulid')
                ->orderBy('date')
                ->orderBy('video_ulid')
        );
    }

    /**
     * Per proxy node: bytes served, bytes fetched from S3 and the cache hit ratio. The three
     * numbers that say whether a node needs more disk (ratio falling with a full pool) or the
     * fleet needs another node (ratio fine, egress at the node's limit).
     *
     * The ratio counts only the lookups the cache was asked about: `BYPASS` (manifests, on an
     * edge from before they were cached), `OFF` (downloads) and '' (an edge from before the
     * field) are left out rather than counted as misses, or a node serving many downloads would
     * look like it had no cache at all. `REVALIDATED` counts as a hit: the bytes came off the
     * pool, and S3 only answered a 304 — which is every manifest each 30 seconds. Origin
     * bytes live under account 0 and their own metric ({@see IngestBandwidthJob}), so
     * the grouping is on the node alone. Node 0 — rows that predate the dimension — is dropped.
     *
     * @return array<int, array{node_id: int, delivered_bytes: float, origin_bytes: float, hit_ratio: float|null}>
     */
    public function edgeDelivery(string $from, string $to): array
    {
        $delivery = UsageMetric::delivery();
        $in = implode(', ', array_fill(0, count($delivery), '?'));

        $rows = self::usage($from, $to)
            ->where('node_id', '>', 0)
            ->select('node_id')
            ->selectRaw("sum(case when metric in ({$in}) then value else 0 end) as delivered_bytes", $delivery)
            ->selectRaw('sum(case when metric = ? then value else 0 end) as origin_bytes', [IngestBandwidthJob::ORIGIN_METRIC])
            ->selectRaw("sum(case when metric in ({$in}) and cache in ('HIT', 'REVALIDATED') then value else 0 end) as hit_bytes", $delivery)
            ->selectRaw("sum(case when metric in ({$in}) and cache not in ('', 'BYPASS', 'OFF') then value else 0 end) as cached_bytes", $delivery)
            ->groupBy('node_id')
            ->orderBy('node_id')
            ->get();

        $edges = [];
        foreach ($rows as $row) {
            $cached = (float) $row->cached_bytes;
            $edges[] = [
                'node_id' => (int) $row->node_id,
                'delivered_bytes' => (float) $row->delivered_bytes,
                'origin_bytes' => (float) $row->origin_bytes,
                'hit_ratio' => $cached > 0 ? round((float) $row->hit_bytes / $cached, 4) : null,
            ];
        }

        return $edges;
    }

    public function encodingUsage(string $from, string $to): array
    {
        $total = self::usage($from, $to)->where('metric', UsageMetric::ENCODING_CPU->value)->sum('value');

        return ['cpu' => round((float) $total, 2)];
    }

    public function usageSummary(string $from, string $to, ?int $projectId = null): array
    {
        $usage = ['upload_bytes' => 0, 'encoding_cpu' => 0];

        // Named rather than filtered afterwards: the two metrics are a handful of rows against the
        // range's millions of delivery rows, and only the metric-led index can skip those.
        $query = self::usage($from, $to)
            ->whereIn('metric', array_keys($usage))
            ->select('metric')->selectRaw('sum(value) as total')->groupBy('metric');

        if ($projectId !== null) {
            $query->where('project_id', $projectId);
        }

        foreach ($query->get() as $row) {
            if (isset($usage[$row->metric])) {
                $usage[$row->metric] = round((float) $row->total, 2);
            }
        }

        return $usage;
    }

    /**
     * The integrator's own customer labels, by upload volume. A list of identifiers, so it is only
     * offered narrowed: unscoped it returns every tenant's customers mixed together.
     */
    public function topExternalUsers(string $from, string $to, ?int $projectId = null, int $limit = self::TOP_N_DEFAULT): array
    {
        $query = self::usage($from, $to)
            ->where('metric', UsageMetric::UPLOAD_BYTES->value)
            ->where('external_user_id', '!=', '')
            ->select('external_user_id')
            ->selectRaw('sum(value) as bytes')
            ->groupBy('external_user_id')
            ->orderByDesc('bytes')
            ->limit($limit);

        if ($projectId !== null) {
            $query->where('project_id', $projectId);
        }

        return self::rows($query);
    }

    public function encodingUsageOverTime(string $from, string $to): array
    {
        return self::rows(
            self::usage($from, $to)
                ->where('metric', UsageMetric::ENCODING_CPU->value)
                ->select('date')
                ->selectRaw("'cpu' as device")
                ->selectRaw('sum(value) as seconds')
                ->groupBy('date')
                ->orderBy('date')
        );
    }

    /**
     * One account's own usage, a row per metric, customer label and day — what `/api/usage`
     * answers with. `$accountId` is the boundary and is never optional: the column is the owning
     * user, written at ingest from the video, so nothing in the request can widen it.
     *
     * @return array<int, array{metric: string, external_user_id: string, value: float, date: string}>
     */
    public function accountUsage(int $accountId, string $from, string $to, ?string $metric = null, ?string $externalUserId = null): array
    {
        $query = self::usage($from, $to)
            ->where('user_id', $accountId)
            ->select('metric', 'external_user_id')
            ->selectRaw('sum(value) as value')
            ->addSelect('date')
            ->groupBy('metric', 'external_user_id', 'date')
            ->orderBy('date');

        if ($metric !== null && $metric !== '') {
            $query->where('metric', $metric);
        }

        if ($externalUserId !== null && $externalUserId !== '') {
            $query->where('external_user_id', $externalUserId);
        }

        return self::rows($query);
    }
}
