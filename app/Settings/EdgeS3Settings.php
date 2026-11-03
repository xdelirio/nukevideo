<?php

namespace App\Settings;

use App\Services\NodeService;
use Spatie\LaravelSettings\Settings;

/**
 * The S3 key the proxies sign with, when they should not hold the main one. Optional: with no pair
 * they use the host's AWS key, as they always did ({@see NodeService}).
 *
 * The proxies only ever read, and they are the machines that answer the internet directly, so this
 * is meant to be a key limited to reading the bucket. A key leaked from one of them then cannot
 * delete or rewrite the catalogue. Workers keep the main key, because they upload what they encode.
 *
 * Kept here and not in the environment so that it can be set and rotated from the panel.
 */
class EdgeS3Settings extends Settings
{
    public string $access_key_id;

    public string $secret_access_key;

    public static function group(): string
    {
        return 'edge_s3';
    }
}
