<?php

/**
 * The settings migration that takes the edge's config out of the per-provider map. It runs once
 * against whatever an installation has stored, so what is pinned here is what it must not lose —
 * the signing secret every live link depends on — and what it must leave behind.
 */

use App\Settings\CdnSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function flattenMigration(): object
{
    return require database_path('settings/2026_11_13_000000_flatten_cdn_settings.php');
}

/** Puts the `cdn` group back the way an installation that predates the migration stores it. */
function storeLegacyCdnSettings(array $providers, string $provider = 'bunny'): void
{
    DB::table('settings')->where('group', 'cdn')->delete();
    DB::table('settings')->insert([
        ['group' => 'cdn', 'name' => 'provider', 'locked' => false, 'payload' => json_encode($provider)],
        ['group' => 'cdn', 'name' => 'providers', 'locked' => false, 'payload' => json_encode($providers)],
    ]);
}

it('carries the edge config over and drops the provider switch and the Bunny credentials', function () {
    storeLegacyCdnSettings([
        'self_hosted' => [
            'token_secret' => 'abcd',
            'token_name' => 'nv_token',
            'token_window' => '7200',
            'bind_ip' => true,
            'allowed_domains' => ['example.com'],
            'cache_max_size' => '10g',
        ],
        'bunny' => ['host' => 'zone.b-cdn.net', 'token_key' => 'bunny-key', 'api_key' => 'bunny-api'],
    ]);

    flattenMigration()->up();

    $settings = app(CdnSettings::class)->refresh();

    expect($settings->token_secret)->toBe('abcd')
        ->and($settings->token_name)->toBe('nv_token')
        ->and($settings->token_window)->toBe(7200)
        ->and($settings->bind_ip)->toBeTrue()
        ->and($settings->allowed_domains)->toBe(['example.com'])
        ->and($settings->allow_null_origin)->toBeFalse()
        ->and(DB::table('settings')->where('group', 'cdn')->pluck('name')->sort()->values()->all())
        ->toBe(['allow_null_origin', 'allowed_domains', 'bind_ip', 'token_name', 'token_secret', 'token_window'])
        ->and(DB::table('settings')->where('group', 'cdn')->pluck('payload')->implode(' '))->not->toContain('bunny');
});

it('falls back to the defaults for what was never stored', function () {
    storeLegacyCdnSettings(['self_hosted' => ['token_secret' => 'abcd']], 'self_hosted');

    flattenMigration()->up();

    $settings = app(CdnSettings::class)->refresh();

    expect($settings->token_secret)->toBe('abcd')
        ->and($settings->token_name)->toBe('__hdnea__')
        ->and($settings->token_window)->toBe(3600)
        ->and($settings->allowed_domains)->toBe([]);
});

it('can be run again, and rolled back to the map it replaced', function () {
    storeLegacyCdnSettings(['self_hosted' => ['token_secret' => 'abcd', 'token_window' => 900]]);

    flattenMigration()->up();
    flattenMigration()->up();

    expect(app(CdnSettings::class)->refresh()->token_window)->toBe(900);

    flattenMigration()->down();

    $stored = DB::table('settings')->where('group', 'cdn')->pluck('payload', 'name');

    expect($stored->keys()->sort()->values()->all())->toBe(['provider', 'providers'])
        ->and(json_decode($stored['providers'], true)['self_hosted'])->toMatchArray(['token_secret' => 'abcd', 'token_window' => 900]);
});
