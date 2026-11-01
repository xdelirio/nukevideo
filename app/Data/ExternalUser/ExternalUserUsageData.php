<?php

namespace App\Data\ExternalUser;

use Spatie\LaravelData\Data;

class ExternalUserUsageData extends Data
{
    public function __construct(
        public string $from,
        public string $to,
        /** Every delivery metric added up: what this customer's viewers downloaded. */
        public float $deliveredBytes,
        /** Distinct viewer addresses across that delivery. */
        public int $uniqueIps,
        /** Distinct tracking ids across that delivery; unattributed traffic is not counted. */
        public int $uniqueTrackingIds,
        /** @var ExternalUserMetricData[] */
        public array $metrics,
    ) {}
}
