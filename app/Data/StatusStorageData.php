<?php

namespace App\Data;

use App\Enums\VideoStatus;
use Spatie\LaravelData\Data;

/** One status's share of a project's storage. */
class StatusStorageData extends Data
{
    public function __construct(
        /** A case of {@see VideoStatus}. */
        public string $status,
        public int $videos,
        public StorageData $storage,
    ) {}
}
