<?php

namespace App\Data\Analytics;

use App\Services\AnalyticsService;
use Spatie\LaravelData\Data;

/** One address several tracking ids were served to ({@see AnalyticsService::sharedIps()}). */
class SharedIpData extends Data
{
    public function __construct(
        public string $ip,
        /** ISO 3166-1 alpha-2, or empty when the address resolved to nothing. */
        public string $country,
        /** Exact number of distinct tracking ids served to this address in the range. */
        public int $trackingIdCount,
        public float $bytes,
        /** Days in the range the address fetched anything: a farm returns daily, a NAT gateway not. */
        public int $days,
        /**
         * The ids, heaviest first, cut at {@see AnalyticsService::SHARED_IP_IDS_PER_IP}.
         *
         * @var list<string>
         */
        public array $trackingIds,
    ) {}
}
