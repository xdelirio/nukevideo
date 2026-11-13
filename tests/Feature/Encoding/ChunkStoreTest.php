<?php

use App\Models\Node;
use App\Services\NodeService;
use App\Settings\CdnSettings;
use App\Settings\NodeSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    CdnSettings::fake(['token_secret' => 'secret']);
});

function storeNode(array $attributes = []): Node
{
    return Node::create([
        'ip_address' => '10.0.0.'.random_int(2, 250),
        'user' => 'deploy',
        'name' => 'node-'.uniqid(),
        'type' => 'worker',
        'is_active' => true,
        ...$attributes,
    ]);
}

function chunkStoreAddress(): string
{
    return app(NodeSettings::class)->refresh()->chunk_store_address;
}

describe('where the chunk store lives', function () {
    it('goes to the first worker, so a new fleet needs nothing configured', function () {
        $first = storeNode(['ip_address' => '10.0.0.20']);
        storeNode(['ip_address' => '10.0.0.21']);

        expect(chunkStoreAddress())->toBe($first->ip_address);
    });

    it('never goes to a proxy, which runs no chunk store', function () {
        storeNode(['type' => 'proxy', 'hostname' => 'edge.example.com']);

        expect(chunkStoreAddress())->toBe('');
    });

    it('is cleared when the node holding it is deleted, and only then', function () {
        $store = storeNode(['ip_address' => '10.0.0.20']);
        storeNode(['ip_address' => '10.0.0.21'])->delete();

        expect(chunkStoreAddress())->toBe('10.0.0.20');

        $store->delete();

        expect(chunkStoreAddress())->toBe('');
    });
});

describe('what the workers are told', function () {
    it('raises the worker to drain on docker stop', function () {
        // Horizon traps SIGTERM only; the base image's SIGQUIT never reaches it.
        $script = app(NodeService::class)->buildDeployScript(storeNode());

        expect($script)->toContain('--stop-signal SIGTERM')
            ->and($script)->toContain('--stop-timeout '.NodeService::WORKER_STOP_GRACE);
    });

    it('points every worker at the chunk store', function () {
        storeNode(['ip_address' => '10.0.0.20']);

        $env = app(NodeService::class)->getEnvironmentVariables(storeNode());

        expect($env)->toContain('CHUNKS_S3_ENDPOINT=http://10.0.0.20:9000');
    });

    it('takes the port with the address, where 9000 is already in use', function () {
        // A host running other S3-compatible stores (or a development stack) has 9000 taken.
        storeNode(['ip_address' => '10.0.0.20']);
        app(NodeSettings::class)->fill(['chunk_store_address' => '10.0.0.20:9009'])->save();

        $worker = storeNode();
        $env = app(NodeService::class)->getEnvironmentVariables($worker);
        $script = app(NodeService::class)->buildDeployScript($worker);

        expect($env)->toContain('CHUNKS_S3_ENDPOINT=http://10.0.0.20:9009')
            ->and($script)->toContain("CHUNK_STORE_HOST='10.0.0.20'")
            ->and($script)->toContain('CHUNK_STORE_PORT=9009');
    });

    it('forgets the address of a deleted node, port or not', function () {
        $store = storeNode(['ip_address' => '10.0.0.20']);
        app(NodeSettings::class)->fill(['chunk_store_address' => '10.0.0.20:9009'])->save();

        $store->delete();

        expect(chunkStoreAddress())->toBe('');
    });

    it('tells a proxy nothing about it', function () {
        storeNode(['ip_address' => '10.0.0.20']);

        $env = app(NodeService::class)->getEnvironmentVariables(storeNode(['type' => 'proxy', 'hostname' => 'edge.example.com']));

        expect(implode("\n", $env))->not->toContain('CHUNKS_S3_ENDPOINT');
    });

    it('refuses to deploy a worker with nowhere to put its chunks', function () {
        $worker = storeNode();
        app(NodeSettings::class)->fill(['chunk_store_address' => ''])->save();

        expect(fn () => app(NodeService::class)->buildDeployScript($worker))
            ->toThrow(RuntimeException::class, 'chunk store');
    });
});

describe('the deploy', function () {
    it('lets each worker find out on its own host whether the store is its to run', function () {
        // The address is the private one the workers use, and the panel often reaches a node
        // over another: the only reliable comparison is against the node's own interfaces.
        app(NodeSettings::class)->fill(['chunk_store_address' => 'chunks.internal'])->save();

        $script = app(NodeService::class)->buildDeployScript(storeNode());

        expect($script)->toContain("CHUNK_STORE_HOST='chunks.internal'")
            ->and($script)->toContain('CHUNK_STORE_PORT=9000')
            ->and($script)->toContain('getent ahostsv4 "$CHUNK_STORE_HOST"')
            ->and($script)->toContain('hostname -I');
    });

    it('fails a worker whose chunk store address no node answers on', function () {
        // An address that is no worker's own would have every worker deploy fine and then fail
        // every chunk. The worker that does not run the store waits for it — it may be deploying
        // alongside — and gives up with the reason rather than with the first video.
        $script = app(NodeService::class)->buildDeployScript(storeNode());

        expect($script)->toContain('</dev/tcp/$CHUNK_STORE_IP/$CHUNK_STORE_PORT')
            ->and($script)->toContain('No node answers on $CHUNK_STORE_HOST');
    });

    it('publishes the store on the private address alone', function () {
        // On every interface it was reachable from the internet, guarded only by the same
        // credentials as the primary bucket.
        $script = app(NodeService::class)->buildDeployScript(storeNode());

        expect($script)->toContain('-p "$CHUNK_STORE_IP:$CHUNK_STORE_PORT:9000"')
            ->and($script)->not->toContain("-p '9000:9000'")
            ->and($script)->not->toContain('-p 9000:9000');
    });

    it('leaves a running store alone unless what it runs with changed', function () {
        // Recreating it on every deploy of its worker cut every other worker's transfers for a
        // few seconds, mid fleet deploy, for nothing.
        app(NodeSettings::class)->fill(['chunk_store_address' => '10.0.0.20'])->save();
        $worker = storeNode();
        $config = fn () => preg_match("/^STORAGE_CONFIG='([0-9a-f]+)'$/m", app(NodeService::class)->buildDeployScript($worker), $m) ? $m[1] : null;

        $before = $config();
        $script = app(NodeService::class)->buildDeployScript($worker);
        app(NodeSettings::class)->fill(['chunk_store_address' => '10.0.0.20:9009'])->save();

        expect($before)->not->toBeNull()
            ->and($config())->not->toBe($before)
            ->and($script)->toContain("nukevideo.config={$before}")
            // The resolved IP too: a DNS name can move without the address changing.
            ->and($script)->toContain('"true $STORAGE_CONFIG-$CHUNK_STORE_IP"');
    });
});

describe('shared service versions', function () {
    it('pins the chunk store image and pulls it, so a version bump is what updates it', function () {
        // `latest` was never pulled again after a host's first deploy, and its tag never changed
        // the config hash: every host kept whatever version it had happened to download.
        $script = app(NodeService::class)->buildDeployScript(storeNode());

        expect($script)->toMatch("/^STORAGE_IMAGE='rustfs\\/rustfs:\\d+\\.\\d+\\.\\d+'$/m")
            ->and($script)->toContain('pull_image "$STORAGE_IMAGE"')
            ->and($script)->not->toContain('rustfs/rustfs:latest');
    });

    it('pins Traefik to a patch release and pulls it before recreating it', function () {
        $script = app(NodeService::class)->buildDeployScript(storeNode(['type' => 'proxy', 'hostname' => 'edge.example.com']));

        expect($script)->toMatch("/^TRAEFIK_IMAGE='traefik:v\\d+\\.\\d+\\.\\d+'$/m")
            ->and($script)->toContain('pull_image "$TRAEFIK_IMAGE"');
    });
});
