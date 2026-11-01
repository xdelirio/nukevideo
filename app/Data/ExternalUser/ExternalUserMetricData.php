<?php

namespace App\Data\ExternalUser;

use App\Enums\MetricUnit;
use App\Enums\UsageMetric;
use Spatie\LaravelData\Data;

/** One metric of a customer's usage over the range: how much, in what unit, over how many requests. */
class ExternalUserMetricData extends Data
{
    public function __construct(
        /** A case of {@see UsageMetric}. */
        public string $metric,
        /** What `value` is counted in: the unit follows the metric, never the other way round. */
        public MetricUnit $unit,
        public float $value,
        /** Requests summed into `value`: segment fetches for delivery, uploads and chunks otherwise. */
        public int $requests,
    ) {}
}
