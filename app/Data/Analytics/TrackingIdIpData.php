<?php

namespace App\Data\Analytics;

use App\Services\AnalyticsService;
use Spatie\LaravelData\Data;

/** One address a tracking id was served to ({@see AnalyticsService::trackingIdIps()}). */
class TrackingIdIpData extends Data
{
    public function __construct(
        public string $ip,
        public string $country,
        public float $bytes,
        public int $requests,
        public int $days,
        public string $firstSeen,
        public string $lastSeen,
    ) {}
}
