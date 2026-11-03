<?php

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
