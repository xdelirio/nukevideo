<?php

namespace App\Data\ExternalUser;

use App\Data\StorageData;
use Spatie\LaravelData\Data;

/** One customer in the project's listing: its catalogue, sized, and its last upload. */
class ExternalUserListItemData extends Data
{
    public function __construct(
        public string $externalUserId,
        public int $videos,
        /** Everything its videos hold on storage, as {@see StorageData::$totalBytes}. */
        public int $storageBytes,
        public string $lastUploadAt,
    ) {}
}
