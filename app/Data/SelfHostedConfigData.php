<?php

namespace App\Data;

use App\Support\AllowedDomains;
use Spatie\LaravelData\Data;

class SelfHostedConfigData extends Data
{
    public function __construct(
        public string $tokenSecret = '',
        public string $tokenName = '__hdnea__',
        public int $tokenWindow = 3600,
        // Whether playback links are signed with the viewer's address, which the edge's validator
        // then enforces: a link fetched from any other address is a 403. Off by default because it
        // turns every address change into one — a phone moving between Wi-Fi and mobile data, a
        // dual-stack viewer who reached the API over IPv4 and the edge over IPv6 — and it only
        // works when integrators mint with the viewer's `ip`.
        public bool $bindIp = false,
        // The sites whose pages the edges will serve; empty is no restriction. Written into the
        // edge's nginx at deploy, unlike `bindIp`, so a change waits for a redeploy of the proxies.
        // See {@see AllowedDomains}.
        /** @var string[] */
        public array $allowedDomains = [],
        // Whether the edges also serve a page whose Origin is `null`, which the list above would
        // refuse: an app's page on file:// (a webOS or Tizen app, a WebView) has no other origin
        // to send. Off by default because any website can send the same from a sandboxed iframe,
        // so with it on the list only stops the sites that do not hide. Means nothing without a
        // list, and is written into the edge's nginx at deploy like it.
        public bool $allowNullOrigin = false,
    ) {}
}
