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
