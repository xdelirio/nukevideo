<?php

/**
 * A proxy's hostname is pointed at the node in Cloudflare when the node is deployed, and the
 * record goes when the node does. The token lives in the settings, encrypted, and is never
 * handed back; the zone is found among those the token can see.
 *
 * The one thing it must never do is rewrite a record it did not create.
 */

use App\Models\Node;
use App\Models\User;
use App\Services\CloudflareDns;
use App\Services\NodeService;
use App\Services\SSHService;
use App\Settings\AppSettings;
use App\Settings\CdnSettings;
use App\Settings\CloudflareSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

const CF = 'https://api.cloudflare.com/client/v4';

function cloudflareProxy(array $attributes = []): Node
{
    return Node::create([
        'name' => 'edge-'.uniqid(),
        'ip_address' => '203.0.113.10',
        'user' => 'root',
        'type' => 'proxy',
        'hostname' => 'edge1.example.com',
        ...$attributes,
    ]);
}

/** Fakes the zone list and the records found under the hostname; everything else succeeds. */
function fakeCloudflare(array $records = [], array $zones = [['id' => 'zone1', 'name' => 'example.com']]): void
{
    Http::fake([
        CF.'/zones?*' => Http::response(['success' => true, 'result' => $zones, 'result_info' => ['total_pages' => 1]]),
        CF.'/zones/*/dns_records?*' => Http::response(['success' => true, 'result' => $records]),
        CF.'/zones/*/dns_records*' => Http::response(['success' => true, 'result' => ['id' => 'new']]),
    ]);
}

function cloudflareRecord(array $attributes = []): array
{
    return [
        'id' => 'rec1',
        'type' => 'A',
        'name' => 'edge1.example.com',
        'content' => '203.0.113.10',
        'proxied' => false,
        'comment' => CloudflareDns::COMMENT,
        ...$attributes,
    ];
}

function writesToCloudflare(): array
{
    return collect(Http::recorded())
        ->map(fn ($pair) => $pair[0])
        ->filter(fn (Request $r) => $r->method() !== 'GET')
        ->map(fn (Request $r) => $r->method().' '.str_replace(CF, '', $r->url()))
        ->values()->all();
}

beforeEach(function () {
    CloudflareSettings::fake(['api_token' => 'cf-token', 'proxied' => false]);
});

describe('syncing a proxy', function () {
    it('creates a DNS-only A record for the hostname, marked as ours', function () {
        fakeCloudflare();

        $line = app(CloudflareDns::class)->sync(cloudflareProxy());

        expect($line)->toContain('Created A edge1.example.com → 203.0.113.10 (DNS only)');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === CF.'/zones/zone1/dns_records'
            && $r->hasHeader('Authorization', 'Bearer cf-token')
            && $r->data() === [
                'type' => 'A',
                'name' => 'edge1.example.com',
                'content' => '203.0.113.10',
                'ttl' => 1,
                'proxied' => false,
                'comment' => CloudflareDns::COMMENT,
            ]);
    });

    it('creates it proxied when the installation says so, and AAAA for an IPv6 node', function () {
        CloudflareSettings::fake(['api_token' => 'cf-token', 'proxied' => true]);
        fakeCloudflare();

        app(CloudflareDns::class)->sync(cloudflareProxy(['ip_address' => '2606:4700:4700::1111']));

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r['type'] === 'AAAA' && $r['proxied'] === true);
    });

    it('lets a node answer for itself, whatever the installation says', function (bool $installation, ?bool $node, bool $expected) {
        CloudflareSettings::fake(['api_token' => 'cf-token', 'proxied' => $installation]);
        fakeCloudflare();

        app(CloudflareDns::class)->sync(cloudflareProxy(['cloudflare_proxied' => $node])->fresh());

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['proxied'] === $expected);
    })->with([
        'proxied on a DNS-only fleet' => [false, true, true],
        'DNS only on a proxied fleet' => [true, false, false],
        'no answer follows the fleet' => [true, null, true],
    ]);

    it('takes the choice from the panel, and gives it back on request', function () {
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));
        Http::fake();

        $id = $this->postJson('/api/nodes', [
            'name' => 'edge-panel', 'ipAddress' => '203.0.113.30', 'type' => 'proxy',
            'hostname' => 'edge3.example.com', 'cloudflareProxied' => true,
        ])->assertOk()->assertJsonPath('data.cloudflareProxied', true)->json('data.id');

        // Absent leaves it alone; null goes back to following the installation.
        $this->putJson("/api/nodes/{$id}", ['isDraining' => true])->assertOk()->assertJsonPath('data.cloudflareProxied', true);
        $this->putJson("/api/nodes/{$id}", ['cloudflareProxied' => null])->assertOk()->assertJsonPath('data.cloudflareProxied', null);
        $this->putJson("/api/nodes/{$id}", ['cloudflareProxied' => 'maybe'])->assertStatus(422);
    });

    it('leaves a record that already says the same alone', function () {
        fakeCloudflare([cloudflareRecord()]);

        expect(app(CloudflareDns::class)->sync(cloudflareProxy()))->toContain('already points at 203.0.113.10')
            ->and(writesToCloudflare())->toBe([]);
    });

    it('rewrites its own record when the node moved or the mode changed', function (array $record) {
        fakeCloudflare([cloudflareRecord($record)]);

        expect(app(CloudflareDns::class)->sync(cloudflareProxy()))->toContain('Updated edge1.example.com')
            ->and(writesToCloudflare())->toBe(['PUT /zones/zone1/dns_records/rec1']);
    })->with([
        'another address' => [['content' => '198.51.100.7']],
        'proxied, now DNS only' => [['proxied' => true]],
    ]);

    it('refuses to overwrite a record it did not create', function (array $record) {
        fakeCloudflare([cloudflareRecord($record)]);

        expect(fn () => app(CloudflareDns::class)->sync(cloudflareProxy()))
            ->toThrow(RuntimeException::class, 'NukeVideo did not create it');
        expect(writesToCloudflare())->toBe([]);
    })->with([
        'no comment' => [['comment' => null, 'content' => '198.51.100.7']],
        'an edited comment' => [['comment' => 'Managed by NukeVideo (do not touch)', 'content' => '198.51.100.7']],
        'a CNAME to somewhere else' => [['comment' => null, 'type' => 'CNAME', 'content' => 'other.example.net']],
    ]);

    it('accepts a hand-made record that already points at the node, without adopting it', function () {
        fakeCloudflare([cloudflareRecord(['comment' => null])]);

        expect(app(CloudflareDns::class)->sync(cloudflareProxy()))->toContain('does not manage')
            ->and(writesToCloudflare())->toBe([]);
    });

    it('lets a hand-made record of the other family stand beside its own', function () {
        fakeCloudflare([cloudflareRecord(['id' => 'v6', 'type' => 'AAAA', 'content' => '2001:db8::1', 'comment' => null])]);

        expect(app(CloudflareDns::class)->sync(cloudflareProxy()))->toContain('Created A edge1.example.com')
            ->and(writesToCloudflare())->toBe(['POST /zones/zone1/dns_records']);
    });

    it('compares an IPv6 record as an address, not as the text it was typed in', function () {
        // Cloudflare hands an AAAA back in its shortest form.
        fakeCloudflare([cloudflareRecord(['type' => 'AAAA', 'content' => '2606:4700::1111'])]);

        $line = app(CloudflareDns::class)->sync(cloudflareProxy(['ip_address' => '2606:4700:0000:0000:0000:0000:0000:1111']));

        expect($line)->toContain('already points at')
            ->and(writesToCloudflare())->toBe([]);
    });

    it('reads every page of records rather than trusting the name filter', function () {
        Http::fake([
            CF.'/zones?*' => Http::response(['success' => true, 'result' => [['id' => 'zone1', 'name' => 'example.com']]]),
            CF.'/zones/zone1/dns_records?*page=1' => Http::response([
                'success' => true,
                'result' => [cloudflareRecord(['id' => 'other', 'name' => 'www.example.com', 'comment' => null])],
                'result_info' => ['total_pages' => 2],
            ]),
            CF.'/zones/zone1/dns_records?*page=2' => Http::response([
                'success' => true,
                'result' => [cloudflareRecord(['comment' => null, 'content' => '198.51.100.7'])],
                'result_info' => ['total_pages' => 2],
            ]),
        ]);

        expect(fn () => app(CloudflareDns::class)->sync(cloudflareProxy()))
            ->toThrow(RuntimeException::class, 'NukeVideo did not create it');
    });

    it('picks the longest zone the hostname ends with', function () {
        fakeCloudflare(zones: [
            ['id' => 'apex', 'name' => 'example.com'],
            ['id' => 'eu', 'name' => 'eu.example.com'],
            // A suffix of the string but not of the name: must never match.
            ['id' => 'trap', 'name' => 'ample.com'],
        ]);

        app(CloudflareDns::class)->sync(cloudflareProxy(['hostname' => 'edge1.eu.example.com']));

        expect(writesToCloudflare())->toBe(['POST /zones/eu/dns_records']);
    });

    it('skips, saying why, what it cannot publish', function (array $attributes, array $zones, string $reason) {
        fakeCloudflare(zones: $zones);

        expect(app(CloudflareDns::class)->sync(cloudflareProxy($attributes)))->toContain($reason)
            ->and(writesToCloudflare())->toBe([]);
    })->with([
        'a hostname in nobody\'s zone' => [[], [['id' => 'z', 'name' => 'other.org']], 'no Cloudflare zone'],
        'a private address' => [['ip_address' => '10.0.0.5'], [['id' => 'zone1', 'name' => 'example.com']], 'not a public address'],
    ]);

    it('does nothing at all without a token or without a hostname', function () {
        Http::fake();

        expect(app(CloudflareDns::class)->sync(cloudflareProxy(['hostname' => null])))->toBeNull();

        CloudflareSettings::fake(['api_token' => '', 'proxied' => false]);
        expect(app(CloudflareDns::class)->sync(cloudflareProxy()))->toBeNull();

        Http::assertNothingSent();
    });

    it('fails with Cloudflare\'s own reason', function () {
        Http::fake([CF.'/*' => Http::response(['success' => false, 'errors' => [['code' => 10000, 'message' => 'Authentication error']]], 403)]);

        expect(fn () => app(CloudflareDns::class)->sync(cloudflareProxy()))
            ->toThrow(RuntimeException::class, 'Authentication error');
    });
});

describe('the deploy', function () {
    beforeEach(function () {
        AppSettings::fake(['ssh_private_key' => 'PRIVATE', 'ssh_public_key' => 'ssh-ed25519 AAAA nukevideo', 'ssh_fingerprint' => 'ff']);
        CdnSettings::fake(['token_secret' => 'abcd']);
        config(['nuke.registry' => 'registry.test']);
    });

    it('publishes the record before it touches the node, and logs it', function () {
        fakeCloudflare();
        $order = [];
        $this->mock(SSHService::class)->shouldReceive('run')->once()->andReturnUsing(function () use (&$order) {
            $order[] = 'ssh';

            return '';
        });

        app(NodeService::class)->runFullDeploy(cloudflareProxy(), function (string $chunk) use (&$order) {
            $order[] = $chunk;
        }, disks: []);

        expect($order[0])->toStartWith("=== Cloudflare DNS ===\nCreated A edge1.example.com")
            ->and($order[1])->toBe('ssh');
    });

    it('stops before the node when the record cannot be written', function () {
        fakeCloudflare([cloudflareRecord(['comment' => null, 'content' => '198.51.100.7'])]);
        $this->mock(SSHService::class)->shouldNotReceive('run');

        expect(fn () => app(NodeService::class)->runFullDeploy(cloudflareProxy(), fn () => null, disks: []))
            ->toThrow(RuntimeException::class, 'NukeVideo did not create it');
    });

    it('lets Traefik believe Cloudflare\'s forwarded address only on a node behind its proxy', function () {
        app()->detectEnvironment(fn () => 'production');
        // HTTPS is what gives the edge a `websecure` entrypoint at all ({@see \App\Models\Node::proxyScheme}).
        config(['app.url' => 'https://api.example.com']);
        config(['nuke.registry' => null]);
        Http::fake();

        $direct = app(NodeService::class)->buildDeployScript(cloudflareProxy(), []);
        $proxied = app(NodeService::class)->buildDeployScript(cloudflareProxy(['cloudflare_proxied' => true])->fresh(), []);

        // Cloudflare's ranges, on both entrypoints, and none of the docker networks the edge's
        // own list starts with: Traefik faces the internet.
        expect($proxied)->toContain('--entrypoints.web.forwardedheaders.trustedips=173.245.48.0/20,')
            ->and($proxied)->toContain('--entrypoints.websecure.forwardedheaders.trustedips=173.245.48.0/20,')
            ->and($proxied)->toContain(',2c0f:f248::/32')
            ->and($proxied)->not->toContain('trustedips=172.16.0.0/12')
            ->and($proxied)->not->toContain('10.0.0.0/8');

        // Untouched for everyone else, hash included: a changed hash restarts Traefik.
        expect($direct)->not->toContain('forwardedheaders');
        preg_match("/TRAEFIK_CONFIG='(\w+)'/", $direct, $a);
        preg_match("/TRAEFIK_CONFIG='(\w+)'/", $proxied, $b);
        expect($a[1])->not->toBe($b[1]);
    });

    it('leaves a worker out of it', function () {
        Http::fake();
        $this->mock(SSHService::class)->shouldReceive('run')->once()->andReturn('');

        app(NodeService::class)->runFullDeploy(
            cloudflareProxy(['type' => 'worker', 'ip_address' => '203.0.113.20']),
            fn () => null,
        );

        Http::assertNothingSent();
    });
});

describe('a node that goes away', function () {
    it('takes its own record with it, and only that one', function () {
        fakeCloudflare([
            cloudflareRecord(),
            cloudflareRecord(['id' => 'theirs', 'type' => 'AAAA', 'content' => '2001:db8::1', 'comment' => null]),
        ]);

        cloudflareProxy()->delete();

        expect(writesToCloudflare())->toBe(['DELETE /zones/zone1/dns_records/rec1']);
    });

    it('leaves the record alone once it points at the node that replaced it', function () {
        // A server is replaced by creating the new node under the same hostname, deploying it,
        // then deleting the old one. The record is the new node's by then.
        $old = cloudflareProxy(['ip_address' => '198.51.100.7']);
        fakeCloudflare([cloudflareRecord(['content' => '203.0.113.10'])]);

        $old->delete();

        expect(writesToCloudflare())->toBe([]);
    });

    it('drops the old record when the hostname changes', function () {
        $node = cloudflareProxy();
        fakeCloudflare([cloudflareRecord()]);

        $node->update(['hostname' => 'edge2.example.com']);

        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_contains($r->url(), 'name=edge1.example.com'));
        expect(writesToCloudflare())->toBe(['DELETE /zones/zone1/dns_records/rec1']);
    });

    it('is deleted all the same when Cloudflare is down', function () {
        $node = cloudflareProxy();
        Http::fake([CF.'/*' => Http::response('', 500)]);

        $node->delete();

        expect(Node::find($node->id))->toBeNull();
    });

    it('asks Cloudflare nothing for a worker, or for an edit that keeps the hostname', function () {
        Http::fake();

        cloudflareProxy()->update(['is_draining' => true]);
        cloudflareProxy(['type' => 'worker', 'hostname' => null])->delete();

        Http::assertNothingSent();
    });
});

describe('the settings', function () {
    beforeEach(function () {
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));
        CloudflareSettings::fake(['api_token' => '', 'proxied' => false]);
    });

    it('stores a token that works and never hands it back', function () {
        fakeCloudflare();

        $response = $this->putJson('/api/app-settings/cloudflare', ['apiToken' => 'new-token', 'proxied' => true])->assertOk();

        $response->assertJsonPath('data.cloudflareConfigured', true)
            ->assertJsonPath('data.cloudflareProxied', true);
        expect($response->getContent())->not->toContain('new-token')
            ->and(app(CloudflareSettings::class)->api_token)->toBe('new-token');

        // Tried with the token being saved, not the one stored.
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer new-token'));
    });

    it('refuses a token Cloudflare refuses, and a first save without one', function () {
        Http::fake([CF.'/*' => Http::response(['success' => false, 'errors' => [['message' => 'Invalid API Token']]], 401)]);

        $this->putJson('/api/app-settings/cloudflare', ['apiToken' => 'bad'])
            ->assertStatus(422)->assertJsonValidationErrors(['apiToken' => 'Invalid API Token']);

        $this->putJson('/api/app-settings/cloudflare', ['proxied' => true])
            ->assertStatus(422)->assertJsonValidationErrors(['apiToken']);

        expect(app(CloudflareSettings::class)->api_token)->toBe('');
    });

    it('flips the mode without being given the token again', function () {
        CloudflareSettings::fake(['api_token' => 'kept', 'proxied' => false]);
        Http::fake();

        $this->putJson('/api/app-settings/cloudflare', ['proxied' => true])->assertOk()
            ->assertJsonPath('data.cloudflareProxied', true);

        expect(app(CloudflareSettings::class)->api_token)->toBe('kept');
        Http::assertNothingSent();
    });

    it('forgets the token on request', function () {
        CloudflareSettings::fake(['api_token' => 'kept', 'proxied' => true]);

        $this->deleteJson('/api/app-settings/cloudflare')->assertOk()
            ->assertJsonPath('data.cloudflareConfigured', false)
            ->assertJsonPath('data.cloudflareProxied', false);

        expect(app(CloudflareSettings::class)->api_token)->toBe('');
    });

    it('lists the zones the stored token sees', function () {
        $this->getJson('/api/app-settings/cloudflare/zones')->assertOk()->assertExactJson(['data' => []]);

        CloudflareSettings::fake(['api_token' => 'kept', 'proxied' => false]);
        fakeCloudflare(zones: [['id' => 'a', 'name' => 'example.com'], ['id' => 'b', 'name' => 'example.org']]);
        $this->getJson('/api/app-settings/cloudflare/zones')->assertOk()
            ->assertExactJson(['data' => ['example.com', 'example.org']]);
    });

    it('says so when the stored token stopped working', function () {
        CloudflareSettings::fake(['api_token' => 'revoked', 'proxied' => false]);
        Http::fake([CF.'/*' => Http::response(['success' => false, 'errors' => [['message' => 'Invalid API Token']]], 401)]);

        $this->getJson('/api/app-settings/cloudflare/zones')->assertStatus(502)
            ->assertJsonPath('message', 'Cloudflare refused the request: Invalid API Token');
    });

    it('is the administrators\' alone', function () {
        Sanctum::actingAs(User::factory()->create(['is_admin' => false]));

        $this->putJson('/api/app-settings/cloudflare', ['apiToken' => 'x'])->assertForbidden();
        $this->deleteJson('/api/app-settings/cloudflare')->assertForbidden();
        $this->getJson('/api/app-settings/cloudflare/zones')->assertForbidden();
    });
});
