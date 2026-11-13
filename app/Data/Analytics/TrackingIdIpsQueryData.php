<?php

namespace App\Data\Analytics;

use App\Data\RequestData;
use App\Http\Controllers\Api\AnalyticsController;
use App\Support\DateRange;

/** The date range and size of one tracking id's address list ({@see AnalyticsController::trackingIdIps()}). */
class TrackingIdIpsQueryData extends RequestData
{
    public function __construct(
        public string $from,
        public string $to,
        public ?int $limit = null,
    ) {}

    public function size(): int
    {
        return $this->limit ?? 100;
    }

    public static function rules(): array
    {
        return [
            'from' => DateRange::from(),
            'to' => DateRange::to(),
            'limit' => 'nullable|integer|min:1|max:1000',
        ];
    }
}
