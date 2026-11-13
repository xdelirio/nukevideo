<?php

namespace App\Settings;

use App\Support\AllowedDomains;
use Spatie\LaravelSettings\Settings;

/**
 * How the proxy nodes sign and gate what they deliver. Kept here and not in the environment so it
 * can be set from the panel; the edge's own nginx receives what it needs of it at deploy time.
 */
class CdnSettings extends Settings
{
    /** Hex HMAC key of the link tokens. Empty mints unsigned links, and refuses to deploy a proxy. */
    public string $token_secret;

    /** The query argument that carries the token. Read by the edge's nginx, so a change waits for a redeploy. */
    public string $token_name;

    /** Seconds a link stays valid when the mint does not ask for a lifetime of its own. */
    public int $token_window;

    /**
     * Whether playback links are signed with the viewer's address, which the edge's validator
     * then enforces: a link fetched from any other address is a 403. Off by default because it
     * turns every address change into one — a phone moving between Wi-Fi and mobile data, a
     * dual-stack viewer who reached the API over IPv4 and the edge over IPv6 — and it only works
     * when integrators mint with the viewer's `ip`.
     */
    public bool $bind_ip;

    /**
     * The sites whose pages the edges will serve; empty is no restriction. Written into the
     * edge's nginx at deploy, unlike `bind_ip`, so a change waits for a redeploy of the proxies.
     * See {@see AllowedDomains}.
     *
     * @var string[]
     */
    public array $allowed_domains;

    /**
     * Whether the edges also serve a page whose Origin is `null`, which the list above would
     * refuse: an app's page on file:// (a webOS or Tizen app, a WebView) has no other origin to
     * send. Off by default because any website can send the same from a sandboxed iframe, so with
     * it on the list only stops the sites that do not hide. Means nothing without a list, and is
     * written into the edge's nginx at deploy like it.
     */
    public bool $allow_null_origin;

    public static function group(): string
    {
        return 'cdn';
    }
}
