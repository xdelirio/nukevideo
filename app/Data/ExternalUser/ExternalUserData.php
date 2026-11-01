<?php

namespace App\Data\ExternalUser;

use App\Data\StorageData;
use App\Http\Controllers\Api\ExternalUserController;
use Spatie\LaravelData\Data;

/**
 * Everything the project knows about one of its customers, in one read
 * ({@see ExternalUserController::show()}): the catalogue and what it occupies now, and what was
 * consumed over the range asked for.
 *
 * A customer is the `externalUserId` an integration uploads with. It names nobody NukeVideo knows,
 * so there is no record to create: a customer exists for as long as something carries the id, and
 * an id nothing carries answers with zeros rather than a 404.
 */
class ExternalUserData extends Data
{
    public function __construct(
        public string $externalUserId,
        public ExternalUserVideosData $videos,
        public StorageData $storage,
        public ExternalUserUsageData $usage,
    ) {}
}
