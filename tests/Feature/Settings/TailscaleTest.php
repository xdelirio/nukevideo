<?php

/**
 * Workers install Tailscale at every deploy and join the tailnet with the auth key stored under
 * Settings. The key is a credential that can add machines to the tailnet, so it is validated for
 * shape, stored encrypted and never handed back to the panel. Proxies are public edges and get
 * nothing from it.
 */

use App\Models\Node;
use App\Models\User;
use App\Services\NodeService;
use App\Settings\CdnSettings;
use App\Settings\NodeSettings;
use App\Settings\TailscaleSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    CdnSettings::fake(['token_secret' => 'secret']);
    app(NodeSettings::class)->fill(['chunk_store_address' => '10.0.0.20'])->save();
    Sanctum::actingAs(User::factory()->create(['is_admin' => true]));
});

function tailscaleWorker(array $attributes = []): Node
{
    return Node::create([
        'name' => 'Worker One',
        'ip_address' => '10.0.0.21',
        'user' => 'deploy',
        'type' => 'worker',
        'is_active' => true,
        ...$attributes,
    ]);
}

describe('the auth key', function () {
    it('must look like a Tailscale key', function () {
        $this->putJson('/api/app-settings/tailscale', ['authKey' => "tskey-auth-x\nrm -rf /"])
            ->assertStatus(422)->assertJsonValidationErrors(['authKey']);

        $this->putJson('/api/app-settings/tailscale', ['authKey' => 'not-a-key'])
            ->assertStatus(422)->assertJsonValidationErrors(['authKey']);
    });

    it('is stored, but never handed back to the panel', function () {
        $this->putJson('/api/app-settings/tailscale', ['authKey' => 'tskey-auth-abc123-XYZ'])->assertOk()
            ->assertJsonPath('data.tailscaleConfigured', true);

        expect(app(TailscaleSettings::class)->refresh()->auth_key)->toBe('tskey-auth-abc123-XYZ');

        $body = $this->getJson('/api/app-settings')->assertOk()
            ->assertJsonPath('data.tailscaleConfigured', true)
            ->getContent();

        expect($body)->not->toContain('tskey-auth-abc123-XYZ');
    });

    it('is forgotten on request, and nodes already joined are not touched', function () {
        TailscaleSettings::fake(['auth_key' => 'tskey-auth-abc123']);

        $this->deleteJson('/api/app-settings/tailscale')->assertOk()
            ->assertJsonPath('data.tailscaleConfigured', false);

        expect(app(TailscaleSettings::class)->refresh()->authKey())->toBeNull();
    });
});

describe('how the panel reaches a node', function () {
    it('opens SSH over the tailnet once the node has an address there, and the public IP before', function () {
        $worker = tailscaleWorker(['ip_address' => '203.0.113.7']);

        expect($worker->sshHost())->toBe('203.0.113.7');

        $worker->forceFill(['tailscale_ip' => '100.64.0.7'])->save();

        expect($worker->fresh()->sshHost())->toBe('100.64.0.7')
            // The public address is kept: it is still what DNS and the chunk store use.
            ->and($worker->fresh()->ip_address)->toBe('203.0.113.7');
    });
});

describe('what a worker is told', function () {
    it('gets the key and a hostname named after it, so it can join', function () {
        TailscaleSettings::fake(['auth_key' => 'tskey-auth-abc123']);

        $script = app(NodeService::class)->buildDeployScript(tailscaleWorker());

        expect($script)->toContain("TAILSCALE_AUTH_KEY='tskey-auth-abc123'")
            ->and($script)->toContain("TAILSCALE_HOSTNAME='worker-one'")
            ->and($script)->toContain('# --- tailscale.sh ---');
    });

    it('installs Tailscale without a key, and joins nothing', function () {
        TailscaleSettings::fake(['auth_key' => '']);

        $script = app(NodeService::class)->buildDeployScript(tailscaleWorker());

        expect($script)->not->toContain('TAILSCALE_AUTH_KEY=')
            ->and($script)->toContain('# --- tailscale.sh ---');
    });

    it('is never given Tailscale when it is a proxy', function () {
        TailscaleSettings::fake(['auth_key' => 'tskey-auth-abc123']);

        $script = app(NodeService::class)->buildDeployScript(Node::create([
            'name' => 'edge-1',
            'ip_address' => '203.0.113.10',
            'user' => 'root',
            'type' => 'proxy',
            'hostname' => 'edge1.example.com',
            'is_active' => true,
        ]));

        expect($script)->not->toContain('TAILSCALE_AUTH_KEY')
            ->and($script)->not->toContain('# --- tailscale.sh ---');
    });
});
