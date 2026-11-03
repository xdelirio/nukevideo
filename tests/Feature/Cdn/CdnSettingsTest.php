<?php

/**
 * The CDN settings form ({@see CdnSettingsController}): what it stores, so the deploy and the
 * signer read the same value back.
 */

use App\Http\Controllers\Api\CdnSettingsController;
use App\Models\User;
use App\Settings\CdnSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    CdnSettings::fake([
        'provider' => 'self_hosted',
        'providers' => ['self_hosted' => ['token_secret' => 'secret', 'token_window' => 3600], 'bunny' => []],
    ]);
    Sanctum::actingAs(User::factory()->create(['is_admin' => true]));
});

$selfHosted = fn (array $extra = []) => ['provider' => 'self_hosted', 'selfHosted' => ['tokenName' => '__hdnea__', 'tokenWindow' => 3600, ...$extra]];

it('turns address binding on and off, and reads it back', function () use ($selfHosted) {
    $this->getJson('/api/cdn-settings')->assertJsonPath('data.selfHosted.bindIp', false);

    $this->patchJson('/api/cdn-settings', $selfHosted(['bindIp' => true]))->assertOk()->assertJsonPath('data.selfHosted.bindIp', true);
    expect(app(CdnSettings::class)->providers['self_hosted']['bind_ip'])->toBeTrue();

    $this->patchJson('/api/cdn-settings', $selfHosted(['bindIp' => false]))->assertOk()->assertJsonPath('data.selfHosted.bindIp', false);
});

it('keeps the binding when a save does not mention it', function () use ($selfHosted) {
    $this->patchJson('/api/cdn-settings', $selfHosted(['bindIp' => true]))->assertOk();

    // An older panel, or a script, saving the other fields must not switch it off behind the operator.
    $this->patchJson('/api/cdn-settings', $selfHosted())->assertOk()->assertJsonPath('data.selfHosted.bindIp', true);
});

it('refuses a value that is not a boolean', function () use ($selfHosted) {
    $this->patchJson('/api/cdn-settings', $selfHosted(['bindIp' => 'sometimes']))->assertStatus(422)->assertJsonValidationErrors('selfHosted.bindIp');
});

it('stores the allowed domains lowercased and without repeats, and reads them back', function () use ($selfHosted) {
    $this->getJson('/api/cdn-settings')->assertJsonPath('data.selfHosted.allowedDomains', []);

    $this->patchJson('/api/cdn-settings', $selfHosted(['allowedDomains' => ['Example.com', '*.example.com', 'example.com']]))
        ->assertOk()
        ->assertJsonPath('data.selfHosted.allowedDomains', ['example.com', '*.example.com']);

    expect(app(CdnSettings::class)->providers['self_hosted']['allowed_domains'])->toBe(['example.com', '*.example.com']);
});

it('keeps the allowed domains when a save does not mention them, and clears them when told to', function () use ($selfHosted) {
    $this->patchJson('/api/cdn-settings', $selfHosted(['allowedDomains' => ['example.com']]))->assertOk();

    $this->patchJson('/api/cdn-settings', $selfHosted())->assertOk()->assertJsonPath('data.selfHosted.allowedDomains', ['example.com']);

    $this->patchJson('/api/cdn-settings', $selfHosted(['allowedDomains' => []]))->assertOk()->assertJsonPath('data.selfHosted.allowedDomains', []);
});

it('refuses what is not a bare hostname', function (string $domain) use ($selfHosted) {
    $this->patchJson('/api/cdn-settings', $selfHosted(['allowedDomains' => [$domain]]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['selfHosted.allowedDomains.0' => 'bare hostname']);
})->with([
    'a url' => 'https://example.com',
    'a path' => 'example.com/videos',
    'a port' => 'example.com:8443',
    'a bare wildcard' => '*',
    'a wildcard over a whole tld' => '*.com',
    'a wildcard mid-name' => 'foo.*.example.com',
    'something aimed at the nginx config' => 'example.com" 0; } #',
]);
