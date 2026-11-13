<?php

/**
 * The CDN settings form ({@see CdnSettingsController}): what it stores, so the deploy and the
 * signer read the same value back.
 */

use App\Http\Controllers\Api\CdnSettingsController;
use App\Models\User;
use App\Settings\CdnSettings;
use App\Settings\EdgeS3Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    CdnSettings::fake(['token_secret' => 'secret', 'token_window' => 3600]);
    Sanctum::actingAs(User::factory()->create(['is_admin' => true]));
});

$settings = fn (array $extra = []) => ['tokenName' => '__hdnea__', 'tokenWindow' => 3600, ...$extra];

it('turns address binding on and off, and reads it back', function () use ($settings) {
    $this->getJson('/api/cdn-settings')->assertJsonPath('data.bindIp', false);

    $this->patchJson('/api/cdn-settings', $settings(['bindIp' => true]))->assertOk()->assertJsonPath('data.bindIp', true);
    expect(app(CdnSettings::class)->bind_ip)->toBeTrue();

    $this->patchJson('/api/cdn-settings', $settings(['bindIp' => false]))->assertOk()->assertJsonPath('data.bindIp', false);
});

it('keeps the binding when a save does not mention it', function () use ($settings) {
    $this->patchJson('/api/cdn-settings', $settings(['bindIp' => true]))->assertOk();

    // An older panel, or a script, saving the other fields must not switch it off behind the operator.
    $this->patchJson('/api/cdn-settings', $settings())->assertOk()->assertJsonPath('data.bindIp', true);
});

describe('the read-only S3 key for the proxies', function () use ($settings) {
    $withKey = fn (array $edgeS3) => [...$settings(), 'edgeS3' => $edgeS3];

    it('starts empty', function () {
        $this->getJson('/api/cdn-settings')
            ->assertJsonPath('data.edgeS3.accessKeyId', '')
            ->assertJsonPath('data.edgeS3.secretConfigured', false);
    });

    it('stores the pair and never hands the secret back', function () use ($withKey) {
        $response = $this->patchJson('/api/cdn-settings', $withKey(['accessKeyId' => 'edge-key', 'secretAccessKey' => 'edge-secret']))
            ->assertOk()
            ->assertJsonPath('data.edgeS3.accessKeyId', 'edge-key')
            ->assertJsonPath('data.edgeS3.secretConfigured', true);

        expect($response->getContent())->not->toContain('edge-secret')
            ->and($this->getJson('/api/cdn-settings')->getContent())->not->toContain('edge-secret')
            ->and(app(EdgeS3Settings::class)->secret_access_key)->toBe('edge-secret');
    });

    it('keeps the secret when a save leaves it out or empty', function (array $edgeS3) use ($withKey) {
        $this->patchJson('/api/cdn-settings', $withKey(['accessKeyId' => 'edge-key', 'secretAccessKey' => 'edge-secret']))->assertOk();

        $this->patchJson('/api/cdn-settings', $withKey($edgeS3))->assertOk()->assertJsonPath('data.edgeS3.secretConfigured', true);

        expect(app(EdgeS3Settings::class)->secret_access_key)->toBe('edge-secret');
    })->with([
        'left out' => [['accessKeyId' => 'edge-key']],
        'empty' => [['accessKeyId' => 'edge-key', 'secretAccessKey' => '']],
    ]);

    it('leaves the pair alone when a save does not mention it', function () use ($withKey, $settings) {
        $this->patchJson('/api/cdn-settings', $withKey(['accessKeyId' => 'edge-key', 'secretAccessKey' => 'edge-secret']))->assertOk();

        $this->patchJson('/api/cdn-settings', $settings())->assertOk()->assertJsonPath('data.edgeS3.accessKeyId', 'edge-key');
    });

    it('clears the pair when the key is emptied', function () use ($withKey) {
        $this->patchJson('/api/cdn-settings', $withKey(['accessKeyId' => 'edge-key', 'secretAccessKey' => 'edge-secret']))->assertOk();

        $this->patchJson('/api/cdn-settings', $withKey(['accessKeyId' => '']))
            ->assertOk()
            ->assertJsonPath('data.edgeS3.accessKeyId', '')
            ->assertJsonPath('data.edgeS3.secretConfigured', false);

        expect(app(EdgeS3Settings::class)->secret_access_key)->toBe('');
    });

    // A half-set pair signs every S3 request wrongly, and every cache miss on the edge is a 403.
    it('refuses a new key without its secret', function () use ($withKey) {
        $this->patchJson('/api/cdn-settings', $withKey(['accessKeyId' => 'edge-key']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('edgeS3.secretAccessKey');
    });

    it('refuses a changed key that would keep the old secret', function () use ($withKey) {
        $this->patchJson('/api/cdn-settings', $withKey(['accessKeyId' => 'edge-key', 'secretAccessKey' => 'edge-secret']))->assertOk();

        $this->patchJson('/api/cdn-settings', $withKey(['accessKeyId' => 'other-key']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('edgeS3.secretAccessKey');

        expect(app(EdgeS3Settings::class)->access_key_id)->toBe('edge-key');
    });

    it('refuses a secret without a key', function () use ($withKey) {
        $this->patchJson('/api/cdn-settings', $withKey(['accessKeyId' => '', 'secretAccessKey' => 'edge-secret']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('edgeS3.accessKeyId');
    });

    it('refuses whitespace, which could not be a key and would corrupt the deploy', function (string $field) use ($withKey) {
        $this->patchJson('/api/cdn-settings', $withKey(['accessKeyId' => 'edge-key', 'secretAccessKey' => 'edge-secret', $field => "bad value\nLINE=1"]))
            ->assertStatus(422)
            ->assertJsonValidationErrors("edgeS3.{$field}");
    })->with(['accessKeyId', 'secretAccessKey']);
});

it('refuses a value that is not a boolean', function (string $field) use ($settings) {
    $this->patchJson('/api/cdn-settings', $settings([$field => 'sometimes']))->assertStatus(422)->assertJsonValidationErrors($field);
})->with(['bindIp', 'allowNullOrigin']);

it('lets a null origin through only once told to, and reads it back', function () use ($settings) {
    $this->getJson('/api/cdn-settings')->assertJsonPath('data.allowNullOrigin', false);

    $this->patchJson('/api/cdn-settings', $settings(['allowNullOrigin' => true]))->assertOk()->assertJsonPath('data.allowNullOrigin', true);
    expect(app(CdnSettings::class)->allow_null_origin)->toBeTrue();

    $this->patchJson('/api/cdn-settings', $settings(['allowNullOrigin' => false]))->assertOk()->assertJsonPath('data.allowNullOrigin', false);
});

it('keeps the null origin setting when a save does not mention it', function () use ($settings) {
    $this->patchJson('/api/cdn-settings', $settings(['allowNullOrigin' => true]))->assertOk();

    $this->patchJson('/api/cdn-settings', $settings())->assertOk()->assertJsonPath('data.allowNullOrigin', true);
});

it('stores the allowed domains lowercased and without repeats, and reads them back', function () use ($settings) {
    $this->getJson('/api/cdn-settings')->assertJsonPath('data.allowedDomains', []);

    $this->patchJson('/api/cdn-settings', $settings(['allowedDomains' => ['Example.com', '*.example.com', 'example.com']]))
        ->assertOk()
        ->assertJsonPath('data.allowedDomains', ['example.com', '*.example.com']);

    expect(app(CdnSettings::class)->allowed_domains)->toBe(['example.com', '*.example.com']);
});

it('keeps the allowed domains when a save does not mention them, and clears them when told to', function () use ($settings) {
    $this->patchJson('/api/cdn-settings', $settings(['allowedDomains' => ['example.com']]))->assertOk();

    $this->patchJson('/api/cdn-settings', $settings())->assertOk()->assertJsonPath('data.allowedDomains', ['example.com']);

    $this->patchJson('/api/cdn-settings', $settings(['allowedDomains' => []]))->assertOk()->assertJsonPath('data.allowedDomains', []);
});

it('refuses what is not a bare hostname', function (string $domain) use ($settings) {
    $this->patchJson('/api/cdn-settings', $settings(['allowedDomains' => [$domain]]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['allowedDomains.0' => 'bare hostname']);
})->with([
    'a url' => 'https://example.com',
    'a path' => 'example.com/videos',
    'a port' => 'example.com:8443',
    'a bare wildcard' => '*',
    'a wildcard over a whole tld' => '*.com',
    'a wildcard mid-name' => 'foo.*.example.com',
    'something aimed at the nginx config' => 'example.com" 0; } #',
]);

/**
 * The token secret and the edge's S3 keys are written into the edge's nginx config as they stand.
 * Anything nginx reads as syntax in one of them is a line of config — or a config that does not
 * load, which is every proxy crashlooping at its next deploy, after the old container is gone.
 */
it('refuses a token secret that is not hex', function (string $secret) use ($settings) {
    $this->patchJson('/api/cdn-settings', $settings(['tokenSecret' => $secret]))
        ->assertStatus(422)->assertJsonValidationErrors('tokenSecret');

    expect(app(CdnSettings::class)->token_secret)->toBe('secret');
})->with([
    'a directive' => '00; proxy_cache off',
    'a space' => 'ab cd',
    'not hex' => 'not-hex-at-all',
    'half a byte' => 'abc',
]);

it('accepts a hex token secret', function () use ($settings) {
    $this->patchJson('/api/cdn-settings', $settings(['tokenSecret' => 'A1b2C3d4e5F60718']))->assertOk();

    expect(app(CdnSettings::class)->token_secret)->toBe('A1b2C3d4e5F60718');
});

it('refuses edge S3 keys that carry config syntax', function (array $edgeS3) use ($settings) {
    $this->patchJson('/api/cdn-settings', [...$settings(), 'edgeS3' => $edgeS3])->assertStatus(422);
})->with([
    'a semicolon in the key' => [['accessKeyId' => 'edge;key', 'secretAccessKey' => 'edge-secret']],
    'a brace in the secret' => [['accessKeyId' => 'edge-key', 'secretAccessKey' => 'edge{secret}']],
    'a variable in the secret' => [['accessKeyId' => 'edge-key', 'secretAccessKey' => 'edge$secret']],
]);
