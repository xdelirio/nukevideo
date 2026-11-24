<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * The Tailscale auth key the workers join the tailnet with at their deploy, or ''. Optional: with
 * none stored the workers still get Tailscale installed, and simply do not join.
 *
 * An auth key is a credential that can add machines to the tailnet, so it is encrypted and never
 * sent back to the panel, the same as the Cloudflare token. It is stored here and not in `.env`
 * for the same reason: the panel is what deploys the nodes, so it is the panel that needs it.
 */
class TailscaleSettings extends Settings
{
    /** A Tailscale auth key (`tskey-…`), or ''. */
    public string $auth_key;

    /** The key the deploy passes to `tailscale up`, or null when none is stored. */
    public function authKey(): ?string
    {
        return $this->auth_key !== '' ? $this->auth_key : null;
    }

    public static function group(): string
    {
        return 'tailscale';
    }

    public static function encrypted(): array
    {
        return ['auth_key'];
    }
}
