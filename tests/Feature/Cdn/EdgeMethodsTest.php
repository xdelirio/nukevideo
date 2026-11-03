<?php

/**
 * The edge signs what it forwards with credentials that can write to the bucket, so what it agrees
 * to forward is the only thing between a viewer and those writes. The suite has no nginx to send a
 * DELETE through, so this guards the rule itself: at server level, where no location can miss it,
 * and ahead of the locations.
 */
it('refuses every method but the reads, before any location', function () {
    $template = file_get_contents(base_path('vod/nginx/nginx.conf.template'));

    $rule = strpos($template, 'if ($request_method !~ ^(GET|HEAD|OPTIONS)$) {');
    $firstLocation = strpos($template, 'location = /healthz');

    expect($rule)->not->toBeFalse()
        ->and($rule)->toBeLessThan($firstLocation)
        ->and(substr($template, $rule, 80))->toContain('return 405;');
});
