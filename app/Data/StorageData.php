<?php

namespace App\Data;

use Spatie\LaravelData\Data;

/**
 * What a set of videos — a customer's, a project's — holds on storage right now: a snapshot, not a
 * figure over a range. The same sums each video reports ({@see VideoData}), added up.
 */
class StorageData extends Data
{
    public function __construct(
        /** Everything stored: the packaged renditions plus any kept file (original, processed). */
        public int $totalBytes,
        /** What playback serves: the packaged renditions only. */
        public int $servedBytes,
        /** The originals as uploaded, while they are kept. */
        public int $sourceBytes,
    ) {}
}
