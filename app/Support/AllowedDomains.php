<?php

namespace App\Support;

/**
 * The sites whose pages the self-hosted edges agree to serve, as the operator keeps them in CDN
 * Settings: `example.com` for that exact host, `*.example.com` for its subdomains (not the host
 * itself). Empty means no restriction.
 *
 * The list reaches the edge at deploy, as VOD_ALLOWED_DOMAINS, and vod/entrypoint.sh writes it into
 * the nginx config. These hostnames end up inside that config, which is why they are validated this
 * strictly here, and again by the entrypoint for whatever else may have written the variable.
 */
class AllowedDomains
{
    /** Each one is a line of an nginx `map` tested on every request of a viewer. */
    public const MAX = 100;

    private const LABEL = '[a-z0-9]([a-z0-9-]*[a-z0-9])?';

    /** Loopback is nobody's panel in production, and any page on a viewer's machine can claim it. */
    private const NOT_A_PANEL = ['localhost', '127.0.0.1'];

    /**
     * ASCII only: a browser sends an internationalised name in its punycode form, so a Unicode entry
     * would validate and then never match. A wildcard needs two labels under it, because `*.com`
     * would allow nearly everyone while reading like a restriction.
     */
    public static function pattern(): string
    {
        $label = self::LABEL;

        return "/^(\*\.{$label}(\.{$label})+|{$label}(\.{$label})*)\$/iD";
    }

    /**
     * @param  list<string>  $domains
     * @return list<string>
     */
    public static function normalize(array $domains): array
    {
        return array_values(array_unique(array_map(strtolower(...), $domains)));
    }

    /**
     * What a deploy hands the edge: the operator's list plus the panel, which plays from another
     * origin and would otherwise be locked out by a list that did not think to name it. The panel's
     * hosts are the ones Sanctum already trusts with a session, so there is no second place to keep
     * them. Nothing configured stays nothing: adding the panel to an empty list would turn "no
     * restriction" into "only the panel".
     *
     * @param  list<string>  $configured
     * @return list<string>
     */
    public static function forEdge(array $configured): array
    {
        if ($configured === []) {
            return [];
        }

        $panel = array_filter(
            array_map(fn (string $host) => preg_replace('/:\d+$/', '', trim($host)), config('sanctum.stateful', [])),
            fn (string $host) => preg_match(self::pattern(), $host) === 1 && ! in_array($host, self::NOT_A_PANEL, true),
        );

        return self::normalize([...$configured, ...$panel]);
    }
}
