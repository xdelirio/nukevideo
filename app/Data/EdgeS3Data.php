<?php

namespace App\Data;

use App\Settings\EdgeS3Settings;
use Spatie\LaravelData\Data;

class EdgeS3Data extends Data
{
    public function __construct(
        public string $accessKeyId,
        // Whether a secret is stored. The secret itself is never exposed, like the Cloudflare token.
        public bool $secretConfigured,
    ) {}

    public static function fromSettings(EdgeS3Settings $settings): self
    {
        return new self(
            accessKeyId: $settings->access_key_id,
            secretConfigured: $settings->secret_access_key !== '',
        );
    }
}
