<?php

namespace App\Services;

use App\Data\ExternalUser\ExternalUserData;
use App\Data\ExternalUser\ExternalUserMetricData;
use App\Data\ExternalUser\ExternalUserUsageData;
use App\Data\ExternalUser\ExternalUserUsageRowData;
use App\Data\ExternalUser\ExternalUserVideosData;
use App\Data\ExternalUser\IndexExternalUsersData;
use App\Data\StorageData;
use App\Enums\MetricDimension;
use App\Enums\MetricMeasure;
use App\Enums\MetricUnit;
use App\Enums\UsageMetric;
use App\Jobs\DeleteExternalUserVideosJob;
use App\Models\Project;
use App\Models\Stream;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * A project's customers: the `externalUserId` its integration uploads with.
 *
 * NukeVideo keeps no customer record — the id is the integration's own, carried on each video and
 * on every usage row it caused — so everything here is read off those two places, always inside
 * one project. The id is unique per project and nowhere else: two projects of the same account may
 * well use the same ids for different people.
 */
class ExternalUserService
{
    public function __construct(
        private AnalyticsService $analytics,
        private StorageService $storage,
    ) {}

    public function summary(Project $project, string $externalUserId, string $from, string $to): ExternalUserData
    {
        return new ExternalUserData(
            externalUserId: $externalUserId,
            videos: $this->videos($project, $externalUserId),
            storage: $this->storage($project, $externalUserId),
            usage: $this->usageSummary($project, $externalUserId, $from, $to),
        );
    }

    /**
     * Every customer the project's videos carry, with how many videos it has and what they hold.
     *
     * Read off `videos`, so a customer whose videos were all deleted drops out of the list while
     * its past usage stays in `usage_daily` — the usage endpoints still answer for it by id.
     */
    public function paginate(Project $project, IndexExternalUsersData $query): LengthAwarePaginator
    {
        $sizes = Stream::query()
            ->select('video_id')
            ->selectRaw('sum(coalesce(package_size, 0) + coalesce(file_size, 0)) as bytes')
            ->groupBy('video_id');

        $customers = DB::table('videos')
            ->where('videos.project_id', $project->id)
            ->whereNotNull('external_user_id')
            ->where('external_user_id', '!=', '')
            ->leftJoinSub($sizes, 'sizes', 'sizes.video_id', '=', 'videos.id')
            ->select('external_user_id')
            ->selectRaw('count(*) as videos')
            ->selectRaw('coalesce(sum(sizes.bytes), 0) as storage_bytes')
            ->selectRaw('max(videos.created_at) as last_upload_at')
            ->groupBy('external_user_id');

        if ($query->search !== null && $query->search !== '') {
            // Escaped: a customer id is the integration's free text, and `%` or `_` in it would
            // otherwise widen the prefix into a pattern. With an explicit escape character, because
            // the default differs — MySQL has `\`, SQLite has none.
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query->search);
            $customers->whereRaw("external_user_id like ? escape '!'", [$escaped.'%']);
        }

        // The id breaks ties so two customers with the same count cannot swap between pages.
        return $customers
            ->orderBy($query->sort, $query->direction)
            ->orderBy('external_user_id')
            ->paginate($query->perPage);
    }

    /**
     * What each named customer consumed, by metric — every metric an account owns unless one is
     * named, split by day when asked. Built on the general query so the metric constraint, the
     * project pin and the row cap are the ones every other read has.
     *
     * @param  list<string>  $externalUserIds
     * @return list<ExternalUserUsageRowData>
     */
    public function usage(Project $project, array $externalUserIds, string $from, string $to, ?string $metric, bool $daily): array
    {
        $dimensions = [MetricDimension::EXTERNAL_USER_ID, MetricDimension::METRIC];

        if ($daily) {
            $dimensions[] = MetricDimension::DATE;
        }

        $rows = $this->analytics->query(
            $from,
            $to,
            $dimensions,
            ['external_user_id' => $externalUserIds],
            $metric === null || $metric === '' ? UsageMetric::account() : [$metric],
            projectId: $project->id,
            measures: [MetricMeasure::REQUESTS],
        );

        return array_map(fn (array $row) => new ExternalUserUsageRowData(
            externalUserId: $row['external_user_id'],
            metric: $row['metric'],
            unit: self::unit($row['metric']),
            value: (float) $row['value'],
            requests: (int) $row['requests'],
            date: $row['date'] ?? null,
        ), $rows);
    }

    /**
     * Queues the deletion of every video of the customer that can be deleted now, and says how many
     * that is and how many were left because they are still being processed.
     *
     * Queued rather than done in the request: deleting a video removes its source from S3 and
     * cancels whatever jobs are still out for it, and a customer may have hundreds. The job looks
     * the videos up again when it runs, so one that started processing in between is skipped
     * rather than deleted from under its encoder ({@see VideoService::destroy()}).
     *
     * @return array{deleting: int, inProgress: int}
     */
    public function queueVideoDeletion(Project $project, string $externalUserId): array
    {
        $counts = $project->videos()
            ->where('external_user_id', $externalUserId)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $deleting = (int) $counts->only(VideoService::DELETABLE_STATUSES)->sum();

        if ($deleting > 0) {
            DeleteExternalUserVideosJob::dispatch($project->id, $externalUserId)->onQueue(DeleteExternalUserVideosJob::QUEUE);
        }

        return ['deleting' => $deleting, 'inProgress' => (int) $counts->sum() - $deleting];
    }

    private function videos(Project $project, string $externalUserId): ExternalUserVideosData
    {
        $byStatus = $project->videos()
            ->where('external_user_id', $externalUserId)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($total) => (int) $total)
            ->all();

        return new ExternalUserVideosData(total: array_sum($byStatus), byStatus: $byStatus);
    }

    private function storage(Project $project, string $externalUserId): StorageData
    {
        return $this->storage->forVideos($project->videos()->where('external_user_id', $externalUserId)->getQuery());
    }

    private function usageSummary(Project $project, string $externalUserId, string $from, string $to): ExternalUserUsageData
    {
        $filter = ['external_user_id' => [$externalUserId]];

        $metrics = array_map(fn (array $row) => new ExternalUserMetricData(
            metric: $row['metric'],
            unit: self::unit($row['metric']),
            value: (float) $row['value'],
            requests: (int) $row['requests'],
        ), $this->analytics->query(
            $from, $to, [MetricDimension::METRIC], $filter, UsageMetric::account(),
            projectId: $project->id, measures: [MetricMeasure::REQUESTS],
        ));

        // A second read for the distinct counts: they have to be counted across the delivery
        // metrics together — a viewer who streamed and downloaded is one viewer, not two.
        $viewers = $this->analytics->query(
            $from, $to, [MetricDimension::EXTERNAL_USER_ID], $filter, UsageMetric::delivery(),
            projectId: $project->id, measures: [MetricMeasure::UNIQUE_IPS, MetricMeasure::UNIQUE_TRACKING_IDS],
        )[0] ?? [];

        $delivery = UsageMetric::delivery();

        return new ExternalUserUsageData(
            from: $from,
            to: $to,
            deliveredBytes: array_sum(array_map(
                fn (ExternalUserMetricData $m) => in_array($m->metric, $delivery, true) ? $m->value : 0,
                $metrics,
            )),
            uniqueIps: (int) ($viewers['unique_ips'] ?? 0),
            uniqueTrackingIds: (int) ($viewers['unique_tracking_ids'] ?? 0),
            metrics: $metrics,
        );
    }

    private static function unit(string $metric): MetricUnit
    {
        return MetricUnit::from(UsageMetric::from($metric)->unit());
    }
}
