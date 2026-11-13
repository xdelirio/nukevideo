<?php

namespace App\Data\Analytics;

use App\Data\RequestData;
use App\Http\Controllers\Api\AnalyticsController;
use App\Support\DateRange;

/**
 * The date range of the per-node delivery report ({@see AnalyticsController::edges()}).
 * Both bounds are required, as ISO dates: they are compared against a `date` column, and
 * an open-ended range over a table kept indefinitely is a full scan nobody asked for.
 */
class EdgeDeliveryQueryData extends RequestData
{
    public function __construct(
        public string $from,
        public string $to,
    ) {}

    public static function rules(): array
    {
        return [
            'from' => DateRange::from(),
            'to' => DateRange::to(),
        ];
    }
}
