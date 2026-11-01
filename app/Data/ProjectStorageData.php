<?php

namespace App\Data;

use App\Http\Controllers\Api\StorageController;
use Spatie\LaravelData\Data;

/**
 * What the project's catalogue holds on storage now ({@see StorageController}), and how that splits
 * by video status — failed videos still holding their originals are usually what is worth seeing.
 */
class ProjectStorageData extends Data
{
    public function __construct(
        public int $videos,
        public StorageData $storage,
        /** @var StatusStorageData[] */
        public array $byStatus,
    ) {}
}
