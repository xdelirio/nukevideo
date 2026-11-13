<?php

use App\Services\NodeService;

/**
 * The entrypoint renders the nginx template with an explicit list of variables, because the file is
 * full of nginx's own `$variables`. A `${NAME}` the list leaves out survives into the config, where
 * nginx refuses it, and the edge crashloops on the next deploy with nothing in CI to say why.
 */
it('renders every variable the edge template names', function () {
    $template = file_get_contents(base_path('vod/nginx/nginx.conf.template'));
    $entrypoint = file_get_contents(base_path('vod/entrypoint.sh'));

    preg_match("/^FILTER_VARS='([^']*)'/m", $entrypoint, $filter);
    preg_match_all('/\$\{([A-Z0-9_]+)\}/', $template, $named);

    $rendered = array_map(fn (string $variable) => ltrim($variable, '$'), explode(' ', $filter[1] ?? ''));

    expect(array_diff(array_unique($named[1]), $rendered))->toBe([])
        ->and($named[1])->toContain('VOD_S3_TLS_VERIFY');
});

/**
 * The manifest's file name is copied, decoded, into the request line of the edge's hop to the API.
 * While the edge matched it as "anything without a slash", a name carrying an encoded CR LF wrote
 * header lines of the viewer's choosing into that request — next to the internal secret, and
 * ahead of the real `X-Link-Token`, which let any link holder pick another link's options. The
 * pattern has to stay the API route's own: letters and digits, an optional numeric cap, the
 * extension.
 */
it('routes to the manifest API only the file names its route accepts', function () {
    $template = file_get_contents(base_path('vod/nginx/nginx.conf.template'));
    $route = collect(app('router')->getRoutes())->first(fn ($route) => $route->uri() === 'api/internal/manifests/{video}/{file}');

    expect($route->wheres['video'])->toBe('[0-9A-Za-z]{26}')
        ->and($route->wheres['file'])->toBe('[0-9A-Za-z]+(\\.[0-9]+)?\\.(mpd|m3u8)');

    // The map that sends a request to the API hop, and the location that builds its URI.
    expect($template)->toContain('"~^/[0-9A-Za-z]{26}/play/[0-9A-Za-z]+(\\.[0-9]+)?\\.(mpd|m3u8)$"  ${MANIFEST_UPSTREAM};')
        ->toContain('location ~ "^/([0-9A-Za-z]{26})/play/([0-9A-Za-z]+(?:\\.[0-9]+)?\\.(?:mpd|m3u8))$" {');

    // And nothing looser left anywhere a manifest is matched.
    preg_match_all('/play\/\[\^\/\]\+/', $template, $loose);
    expect($loose[0])->toBe([]);
});

it('keys a cached master manifest on the options it was built with, and nothing else on them', function () {
    $template = file_get_contents(base_path('vod/nginx/nginx.conf.template'));

    // One cached copy per set of options; without the key in it a viewer would be handed the
    // manifest another viewer's link asked for.
    expect($template)->toContain('proxy_cache_key "$uri$manifest_variant";')
        ->toContain('manifest_origin  "|$link_mo";')
        ->toContain('"~(^|~)mo=(?<m>[0-9a-f]{16})(~|$)"  $m;');
});

/**
 * The edge's access log is the usage meter, and it books each line to the video named in the
 * request path — which a request needs no token to choose. Logging refusals let anyone add bytes,
 * requests and an address to another tenant's video with an unsigned URL.
 */
it('meters only responses that delivered content', function () {
    $template = file_get_contents(base_path('vod/nginx/nginx.conf.template'));

    preg_match_all('/^\s*access_log\s+\/dev\/stdout\s+bandwidth[^;]*;/m', $template, $logs);

    expect($logs[0])->toHaveCount(1)
        ->and($logs[0][0])->toContain('if=$billable')
        ->and($template)->toMatch('/map \$status \$billable \{\s+200\s+1;\s+206\s+1;\s+default\s+0;\s+\}/');
});

/**
 * Everything the edge container is given ends up in its nginx config as text, and some of it comes
 * from a node's free-text environment. `VOD_CACHE_INACTIVE=30d; server { … }` used to add a server
 * that handed out the rendered config, secrets included. Each variable is checked against the
 * shape its value can have before the template is rendered — and one added to the container later
 * fails here until it is.
 */
it('checks every variable the edge is given before it reaches nginx config', function () {
    $entrypoint = file_get_contents(base_path('vod/entrypoint.sh'));
    $given = (new ReflectionClassConstant(NodeService::class, 'EDGE_ENV'))->getValue();

    // Not written into the config as text, or checked by the block that reads them.
    $checkedWhereRead = ['VOD_ALLOWED_DOMAINS', 'VOD_ALLOW_NULL_ORIGIN', 'VOD_S3_TLS_VERIFY', 'INTERNAL_API_URL', 'INTERNAL_API_SECRET'];

    foreach (array_diff($given, $checkedWhereRead) as $variable) {
        expect($entrypoint)->toMatch("/^(require|tunable) {$variable}\\s+'/m", "{$variable} reaches nginx config unchecked");
    }

    // And the checks run before anything is rendered or parsed.
    expect(strpos($entrypoint, 'require AWS_ACCESS_KEY_ID'))->toBeLessThan(strpos($entrypoint, 'split_url AWS_ENDPOINT'))
        ->and(strpos($entrypoint, 'tunable VOD_CACHE_INACTIVE'))->toBeLessThan(strpos($entrypoint, 'envsubst "$FILTER_VARS"'));
});
