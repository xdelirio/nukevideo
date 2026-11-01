<?php

namespace App\Data\ExternalUser;

use App\Enums\MetricUnit;
use Spatie\LaravelData\Data;

/**
 * One (customer, metric) total from the batch usage read, or one (customer, metric, day) on a daily
 * read. A customer with no usage in the range gets no row: absence is the answer, and padding a
 * thousand-id request with zeros would make most of the response filler.
 */
class ExternalUserUsageRowData extends Data
{
    public function __construct(
        public string $externalUserId,
        public string $metric,
        public MetricUnit $unit,
        public float $value,
        public int $requests,
        /** The day, on a daily read. Null when the row is the total for the whole range. */
        public ?string $date = null,
    ) {}
}
