<?php

namespace App\Data;

use App\Settings\AppSettings;
use App\Settings\CloudflareSettings;
use App\Settings\TailscaleSettings;
use Spatie\LaravelData\Data;

class AppSettingsData extends Data
{
    public function __construct(
        // The public half of the panel's SSH key, or null before one exists. The private half is
        // never exposed: it is what the panel connects with.
        public ?string $sshPublicKey,
        public ?string $sshFingerprint,
        // Whether a Cloudflare API token is stored. The token itself is never exposed, like the
        // private key above: it can rewrite the DNS of every zone it was scoped to.
        public bool $cloudflareConfigured,
        public bool $cloudflareProxied,
        // Whether a Tailscale auth key is stored. Like the token above, the key is never exposed:
        // it can add machines to the tailnet.
        public bool $tailscaleConfigured,
    ) {}

    public static function fromSettings(AppSettings $settings): self
    {
        $cloudflare = app(CloudflareSettings::class);

        return new self(
            sshPublicKey: $settings->ssh_public_key !== '' ? $settings->ssh_public_key : null,
            sshFingerprint: $settings->ssh_fingerprint !== '' ? $settings->ssh_fingerprint : null,
            cloudflareConfigured: $cloudflare->api_token !== '',
            cloudflareProxied: $cloudflare->proxied,
            tailscaleConfigured: app(TailscaleSettings::class)->authKey() !== null,
        );
    }
}
