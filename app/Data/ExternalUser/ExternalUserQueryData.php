<?php

namespace App\Data\ExternalUser;

use App\Data\RequestData;
use App\Http\Controllers\Api\ExternalUserController;
use App\Support\DateRange;

/**
 * The date range a customer's summary reports usage over ({@see ExternalUserController::show()}).
 * Required like every other usage read: `usage_daily` is kept indefinitely, and an open-ended range
 * over it is a scan nobody asked for.
 */
class ExternalUserQueryData extends RequestData
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
