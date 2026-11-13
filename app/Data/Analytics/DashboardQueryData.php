<?php

namespace App\Data\Analytics;

use App\Data\RequestData;
use App\Enums\UsageMetric;
use App\Http\Controllers\Api\AnalyticsController;
use App\Services\AnalyticsService;
use App\Support\DateRange;
use App\Support\TrackingId;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Optional;

/**
 * The query every delivery dashboard read takes ({@see AnalyticsController::index()} and the
 * per-panel endpoints beside it): a date range, the optional filters, and the list sizes.
 *
 * One object for all of them, so the full payload and its panels can never disagree about what a
 * filter means or how far a limit goes.
 */
class DashboardQueryData extends RequestData
{
    public function __construct(
        public string $from,
        public string $to,
        public ?string $video,
        /**
         * Absent, present and empty are three different requests, which is why this is the one
         * property that can be Optional. Absent is no filter; `?trackingId=` is "only the traffic
         * that was never attributed", because the empty string is exactly what those rows carry.
         * {@see trackingIdFilter()} turns the three into what the queries take.
         */
        #[MapInputName(CamelCaseMapper::class)]
        public string|null|Optional $trackingId,
        public ?string $metric = null,
        /** Read through {@see topN()}. Nullable because `?limit=` arrives as null. */
        public ?int $limit = null,
        /** Read through {@see seriesTopN()}, for the same reason. */
        #[MapInputName(CamelCaseMapper::class)]
        public ?int $videoSeriesLimit = null,
    ) {}

    /** The size of each flat top-N list, the service's default when none was given. */
    public function topN(): int
    {
        return $this->limit ?? AnalyticsService::TOP_N_DEFAULT;
    }

    /** How many videos the per-video series draws. */
    public function seriesTopN(): int
    {
        return $this->videoSeriesLimit ?? AnalyticsService::SERIES_TOP_N_DEFAULT;
    }

    /**
     * The tracking id filter as the queries take it: null for none, '' for the unattributed
     * traffic. The empty string arrives as null — the framework converts empty input — so
     * "present" is what tells it apart from "absent".
     */
    public function trackingIdFilter(): ?string
    {
        return $this->trackingId instanceof Optional ? null : (string) $this->trackingId;
    }

    public static function rules(): array
    {
        return [
            'from' => DateRange::from(),
            'to' => DateRange::to(),
            // Narrow the reads to one video and/or one tracking id. Both are matched against
            // columns written from CDN access logs, so they are bound parameters in the service,
            // never interpolated; the shapes below are what those columns can hold.
            // An array, not a pipe string: the regex makes the string unsplittable for the Data
            // validator, which then reads the whole of it as one rule name.
            'video' => ['nullable', 'string', 'size:26', 'regex:/^[0-9A-HJKMNP-TV-Z]{26}$/'],
            'trackingId' => TrackingId::rules(),
            // Which kind of delivery to report. Anything outside this list is refused rather than
            // passed through: `usage_daily` also holds upload volume and encoding seconds in the
            // same `value` column, and letting one of those names reach the bandwidth queries would
            // report seconds as if they were bytes.
            'metric' => ['nullable', 'string', Rule::in(UsageMetric::delivery())],
            // How many rows each flat top-N list returns — `topIps`, `topVideos`, `topTrackingIds`
            // and `topExternalUsers`. Capped at the batch endpoints' list size; past a few hundred
            // rows those are the right endpoints anyway.
            'limit' => 'nullable|integer|min:1|max:'.TrackingIdBytesQueryData::MAX_BATCH,
            // `bandwidthByVideo` gets its own, and a smaller one: it is a time series, so its row
            // count is this times the length of the range.
            'videoSeriesLimit' => 'nullable|integer|min:1|max:100',
        ];
    }
}
