<?php

namespace App\Data;

use App\Settings\CdnSettings;
use App\Settings\EdgeS3Settings;
use Spatie\LaravelData\Data;

/** The panel's view of {@see CdnSettings}, which documents each field. */
class CdnSettingsData extends Data
{
    public function __construct(
        public string $tokenSecret,
        public string $tokenName,
        public int $tokenWindow,
        public bool $bindIp,
        /** @var string[] */
        public array $allowedDomains,
        public bool $allowNullOrigin,
        public EdgeS3Data $edgeS3,
    ) {}

    public static function fromSettings(CdnSettings $settings): self
    {
        return new self(
            tokenSecret: $settings->token_secret,
            tokenName: $settings->token_name,
            tokenWindow: $settings->token_window,
            bindIp: $settings->bind_ip,
            allowedDomains: $settings->allowed_domains,
            allowNullOrigin: $settings->allow_null_origin,
            edgeS3: EdgeS3Data::fromSettings(app(EdgeS3Settings::class)),
        );
    }
}
