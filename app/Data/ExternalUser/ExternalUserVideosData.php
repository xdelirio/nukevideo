<?php

namespace App\Data\ExternalUser;

use Spatie\LaravelData\Data;

class ExternalUserVideosData extends Data
{
    public function __construct(
        public int $total,
        /**
         * Video count per status, only the statuses the customer has.
         *
         * @var array<string, int>
         */
        public array $byStatus,
    ) {}
}
