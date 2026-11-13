<?php

/**
 * One scheme for everything that reaches a proxy node: the links the API mints, the health probe,
 * the edge's own VOD_BASE_URL and the Traefik router in front of it. It comes from APP_URL, so a
 * development stack served over HTTPS works the same as production, and one served over plain
 * HTTP keeps working too. The router used to follow the environment instead, so a link minted as
 * https:// pointed at a router that only listened on `web` — a 404 from Traefik.
 */

use App\Models\Node;
use App\Services\NodeService;
use App\Settings\AppSettings;
use App\Settings\CdnSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()->detectEnvironment(fn () => 'local');
    config(['nuke.registry' => '10.0.0.240:5000']);
    AppSettings::fake(['ssh_private_key' => 'PRIVATE', 'ssh_public_key' => 'ssh-ed25519 AAAA nukevideo', 'ssh_fingerprint' => 'ff']);
    CdnSettings::fake(['token_secret' => 'secret']);

    $this->node = Node::create([
        'ip_address' => '10.0.0.240', 'user' => 'deploy', 'name' => 'edge', 'type' => 'proxy',
        'hostname' => 'nv-cdn.delirio.org', 'is_active' => true,
    ]);
    // Read per test: the container prefix follows the environment, which a test may switch.
    $this->router = fn () => Node::containerPrefix()."-proxy-{$this->node->id}";
});

it('serves the edge over TLS when APP_URL is https, even in development', function () {
    config(['app.url' => 'https://nv-api.delirio.org']);

    $script = app(NodeService::class)->buildDeployScript($this->node, []);
    $router = ($this->router)();

    expect(Node::proxyScheme())->toBe('https://')
        ->and($script)->toContain("traefik.http.routers.{$router}.entrypoints=websecure")
        ->and($script)->toContain("traefik.http.routers.{$router}.tls=true")
        // No resolver named: whichever Traefik fronts the edge picks it — ours defaults
        // `websecure` to `le`, a host's own reverse proxy to whatever it is configured with.
        ->and($script)->not->toContain("traefik.http.routers.{$router}.tls.certresolver")
        ->and($script)->toContain('--entrypoints.websecure.http.tls.certresolver=le')
        ->and(app(NodeService::class)->getEnvironmentVariables($this->node))->toContain('VOD_BASE_URL=https://nv-cdn.delirio.org');
});

it('serves the edge over plain HTTP when a development APP_URL is http', function () {
    config(['app.url' => 'http://api.nukevideo.localhost']);

    $script = app(NodeService::class)->buildDeployScript($this->node, []);
    $router = ($this->router)();

    expect(Node::proxyScheme())->toBe('http://')
        ->and($script)->toContain("traefik.http.routers.{$router}.entrypoints=web")
        ->and($script)->not->toContain("traefik.http.routers.{$router}.tls")
        ->and(app(NodeService::class)->getEnvironmentVariables($this->node))->toContain('VOD_BASE_URL=http://nv-cdn.delirio.org');
});

it('keeps production on HTTPS whatever APP_URL says', function () {
    // TLS terminated in front of the API is no reason to serve the public edge over http.
    app()->detectEnvironment(fn () => 'production');
    config(['app.url' => 'http://api.example.test']);

    $script = app(NodeService::class)->buildDeployScript($this->node, []);
    $router = ($this->router)();

    expect(Node::proxyScheme())->toBe('https://')
        ->and($script)->toContain("traefik.http.routers.{$router}.entrypoints=websecure")
        ->and(app(NodeService::class)->getEnvironmentVariables($this->node))->toContain('VOD_BASE_URL=https://nv-cdn.delirio.org');
});

it('falls back to https when APP_URL states no scheme', function () {
    config(['app.url' => 'nv-api.delirio.org']);

    expect(Node::proxyScheme())->toBe('https://');
});
