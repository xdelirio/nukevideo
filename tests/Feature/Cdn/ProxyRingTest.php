<?php

use App\Console\Commands\ProbeProxyNodes;
use App\Models\Node;
use App\Services\Cdn\ProxyRing;
use App\Settings\CdnSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function ringProxy(array $attributes = []): Node
{
    static $n = 0;
    $n++;

    return Node::create([
        'ip_address' => "10.0.0.{$n}",
        'user' => 'deploy',
        'name' => "edge-{$n}",
        'type' => 'proxy',
        'hostname' => "edge-{$n}.example.com",
        'is_active' => true,
        ...$attributes,
    ]);
}

/** A ring with nothing memoized yet — what a fresh request or job is handed. */
function ring(): ProxyRing
{
    // The ring is scoped: forgetting the scope is the request boundary Octane and Horizon draw.
    app()->forgetScopedInstances();

    return app(ProxyRing::class);
}

beforeEach(function () {
    app()->detectEnvironment(fn () => 'production');
    // Production is served over HTTPS, which is the scheme every edge URL follows ({@see \App\Models\Node::proxyScheme}).
    config(['app.url' => 'https://api.example.com']);
    CdnSettings::fake(['token_secret' => 'secret']);
});

describe('the proxy ring', function () {
    it('keeps a video on the same node for as long as the fleet is unchanged', function () {
        ringProxy();
        ringProxy();
        ringProxy();

        $first = ring()->for('01HZZZZZZZZZZZZZZZZZZZZZZZ');

        expect(ring()->for('01HZZZZZZZZZZZZZZZZZZZZZZZ')->id)->toBe($first->id);
    });

    it('resolves the fleet once however many links one request mints', function () {
        ringProxy();
        ringProxy();
        ringProxy();

        // One ring for the whole page, which is what the scoped provider hands every caller.
        $ring = ring();
        $ring->for('01HZZZZZZZZZZZZZZZZZZZZZZZ');

        DB::enableQueryLog();

        foreach (range(1, 25) as $i) {
            $ring->for(str_pad((string) $i, 26, '0', STR_PAD_LEFT));
        }

        // A listing resolves a thumbnail AND a storyboard URL per row, so this used to be a query
        // and a 450-point sort per asset — fifty of each for one page, for an answer that cannot
        // change inside a request.
        expect(DB::getQueryLog())->toBeEmpty();
    });

    it('holds its answer for its own lifetime and no longer', function () {
        $ring = ring();
        expect($ring->for('01HZZZZZZZZZZZZZZZZZZZZZZZ'))->toBeNull();

        ringProxy();

        // This one keeps the answer it resolved: a fleet change mid-request must not move a video
        // between two links on the same page.
        expect($ring->for('01HZZZZZZZZZZZZZZZZZZZZZZZ'))->toBeNull();

        // The next request gets its own, and sees the node. Under php-fpm that boundary is the
        // process; under Octane and Horizon it is the scoped ring being forgotten.
        expect(ring()->for('01HZZZZZZZZZZZZZZZZZZZZZZZ'))->not->toBeNull();
    });

    it('skips a draining node without stopping it', function () {
        // Draining is the maintenance tool: the node goes on serving the sessions it has and
        // only stops receiving new links. Deactivating is what stops containers.
        $kept = ringProxy();
        $draining = ringProxy(['is_draining' => true]);

        $ring = ring();
        foreach (range(1, 20) as $i) {
            expect($ring->for(str_pad((string) $i, 26, '0', STR_PAD_LEFT))->id)->toBe($kept->id);
        }
        expect($draining->fresh()->is_active)->toBeTrue();
    });

    it('skips a node the probe has given up on', function () {
        $kept = ringProxy();
        ringProxy(['health_failures' => Node::HEALTH_FAILURE_THRESHOLD]);

        $ring = ring();
        foreach (range(1, 20) as $i) {
            expect($ring->for(str_pad((string) $i, 26, '0', STR_PAD_LEFT))->id)->toBe($kept->id);
        }
    });

    it('tolerates failures below the threshold', function () {
        // One slow answer must not move a node's whole catalogue, cold, onto its neighbours.
        $flaky = ringProxy(['health_failures' => Node::HEALTH_FAILURE_THRESHOLD - 1]);

        expect(ring()->for('01HZZZZZZZZZZZZZZZZZZZZZZZ')->id)->toBe($flaky->id);
    });

    it('never picks a proxy without a hostname', function () {
        // It used to, and rendered the link as `https:///...`.
        ringProxy(['hostname' => null]);
        $named = ringProxy();

        expect(ring()->for('01HZZZZZZZZZZZZZZZZZZZZZZZ')->id)->toBe($named->id);
    });

    it('falls back to every active node when the probe has condemned the whole fleet', function () {
        // If nothing is routable, the probe is more likely wrong than the fleet dead — the API
        // host may have lost its own network. A link to a node that may be down beats none.
        ringProxy(['health_failures' => 10]);
        ringProxy(['health_failures' => 10]);

        expect(ring()->for('01HZZZZZZZZZZZZZZZZZZZZZZZ'))->not->toBeNull();
    });

    it('still honours draining inside the fallback', function () {
        ringProxy(['health_failures' => 10, 'is_draining' => true]);

        expect(ring()->for('01HZZZZZZZZZZZZZZZZZZZZZZZ'))->toBeNull();
    });
});

/**
 * A proxy whose deploy is long past: the probe does not count failures against a node written
 * in the last minutes (its own test below), and a node just created is exactly that.
 */
function settledProxy(array $attributes = []): Node
{
    $node = ringProxy($attributes);
    Node::whereKey($node->id)->update(['updated_at' => now()->subMinutes(15)]);

    return $node->fresh();
}

describe('nodes:probe', function () {
    it('does not count a failure against a node deployed or edited minutes ago', function () {
        // A fresh production proxy serves Traefik's self-signed certificate until ACME finishes;
        // three of those in a row must not condemn a node that is only just coming up.
        $node = ringProxy();
        Http::fake(["https://{$node->hostname}/healthz" => Http::response('', 500)]);

        foreach (range(1, Node::HEALTH_FAILURE_THRESHOLD) as $i) {
            $this->artisan('nodes:probe')->assertSuccessful();
        }
        expect($node->fresh()->health_failures)->toBe(0);

        $this->travel(15)->minutes();
        $this->artisan('nodes:probe')->assertSuccessful();
        expect($node->fresh()->health_failures)->toBe(1);
    });

    it('counts silence and clears it on the first good answer', function () {
        $node = settledProxy();

        // Stubs stack rather than replace, so the whole story is one sequence.
        $sequence = Http::sequence();
        foreach (range(1, Node::HEALTH_FAILURE_THRESHOLD) as $i) {
            $sequence->push('', 500);
        }
        $sequence->push('', 204, [ProbeProxyNodes::HEADER => (string) $node->id]);
        Http::fake(["https://{$node->hostname}/healthz" => $sequence]);

        foreach (range(1, Node::HEALTH_FAILURE_THRESHOLD) as $i) {
            $this->artisan('nodes:probe')->assertSuccessful();
        }
        expect($node->fresh()->isHealthy())->toBeFalse();

        $this->artisan('nodes:probe')->assertSuccessful();

        expect($node->fresh()->isHealthy())->toBeTrue()
            ->and($node->fresh()->last_healthy_at)->not->toBeNull();
    });

    it('does not take a 200 from something that is not the edge as the edge being up', function () {
        // Traefik with no router answers 404, Cloudflare 403 or a challenge page, a wrong DNS
        // record somebody else's site. The header is the only thing that proves nginx answered.
        $node = settledProxy();
        Http::fake(["https://{$node->hostname}/healthz" => Http::response('<html>welcome</html>', 200)]);

        $this->artisan('nodes:probe')->assertSuccessful();

        expect($node->fresh()->health_failures)->toBe(1);
    });

    it('does not mistake one edge for another', function () {
        $node = settledProxy();
        Http::fake(["https://{$node->hostname}/healthz" => Http::response('', 204, [ProbeProxyNodes::HEADER => '999'])]);

        $this->artisan('nodes:probe')->assertSuccessful();

        expect($node->fresh()->health_failures)->toBe(1);
    });

    it('leaves inactive nodes alone', function () {
        $inactive = ringProxy(['is_active' => false, 'health_failures' => 2]);
        Http::fake();

        $this->artisan('nodes:probe')->assertSuccessful();

        Http::assertNothingSent();
        expect($inactive->fresh()->health_failures)->toBe(2);
    });
});
