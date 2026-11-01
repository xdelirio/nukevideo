<?php

namespace App\Data\Analytics;

use App\Data\RequestData;
use App\Http\Controllers\Api\AnalyticsController;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/** The query of the shared-address report ({@see AnalyticsController::sharedIps()}). */
class SharedIpsQueryData extends RequestData
{
    public function __construct(
        public string $from,
        public string $to,
        /** How many distinct tracking ids an address needs to be reported. Two is "shared at all". */
        public ?int $minTrackingIds = null,
        /**
         * Leave out addresses shared by more ids than this. A carrier NAT, a campus or a VPN
         * serves hundreds or thousands of ids from one address; ordered by how many share an
         * address, those fill the page before the two-to-ten-account clusters an account farm
         * looks like. Omitted, no ceiling.
         */
        public ?int $maxTrackingIds = null,
        public ?int $limit = null,
    ) {}

    public function minimum(): int
    {
        return $this->minTrackingIds ?? 3;
    }

    public function size(): int
    {
        return $this->limit ?? 100;
    }

    public static function rules(ValidationContext $context): array
    {
        $minimum = is_numeric($context->payload['minTrackingIds'] ?? null) ? (int) $context->payload['minTrackingIds'] : 3;

        return [
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
            'minTrackingIds' => 'nullable|integer|min:2|max:100000',
            // At least the minimum: a ceiling below the floor can only ever answer empty.
            'maxTrackingIds' => 'nullable|integer|min:'.max(2, $minimum).'|max:100000',
            'limit' => 'nullable|integer|min:1|max:1000',
        ];
    }
}
