<?php

use App\Models\Node;
use App\Services\NodeService;
use App\Settings\CdnSettings;
use App\Settings\EdgeS3Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function deployableNode(array $attributes = []): Node
{
    return Node::create([
        'ip_address' => '10.0.0.99',
        'user' => 'deploy',
        'name' => 'node-test',
        'type' => 'worker',
        'is_active' => true,
        ...$attributes,
    ]);
}

function fakeCdnProvider(string $provider): void
{
    CdnSettings::fake([
        'provider' => $provider,
        'providers' => [
            'self_hosted' => ['token_secret' => 'secret'],
            'bunny' => ['host' => 'cdn.example.com', 'token_key' => 'key'],
        ],
    ]);
}

function asLocalEnvironment(): void
{
    // `isLocal()` reads the container's `env` binding, which `config()` does not touch.
    app()->detectEnvironment(fn () => 'local');
    // A development panel always has a registry: it is where `node-dev` is pushed to and pulled
    // from, and a deploy without one is refused (its own test clears it again).
    config(['nuke.registry' => '10.0.0.240:5000']);
}

// The image names are assertions about Docker Hub unless a test says otherwise, and the developer
// running the suite may well have a registry of their own in `.env`.
beforeEach(fn () => config(['nuke.registry' => null]));

describe('development and production deploys on one host', function () {
    beforeEach(fn () => fakeCdnProvider('self_hosted'));

    it('keeps every docker name a development deploy writes out of production space', function () {
        // The same machine is a production worker and the development host. Node ids come from
        // each environment's own database, so both want `nukevideo_worker_1`: unprefixed, deploying
        // the dev node replaces the running production container and repoints the chunk volume.
        asLocalEnvironment();
        $node = deployableNode();
        $script = app(NodeService::class)->buildDeployScript($node);

        expect($script)->toContain("nukevideo_dev_worker_{$node->id}")
            ->and($script)->toContain("nukevideo_dev_storage_{$node->id}")
            ->and($script)->toContain('nukevideo_dev_chunks:/data')
            ->and($script)->not->toContain('nukevideo_worker_')
            ->and($script)->not->toContain('nukevideo_storage_')
            ->and($script)->not->toContain('nukevideo_chunks:');
    });

    it('gives each proxy its own Traefik router name', function () {
        // Traefik keys routers by name across every container it discovers on the host, and it
        // discovers all of them. A fixed `routers.proxy` meant a second proxy — or the development
        // host beside production — redefined the same router with a different rule and entrypoint;
        // Traefik drops the conflicting one and the edge stops resolving.
        $first = deployableNode(['type' => 'proxy', 'hostname' => 'a.example.com']);
        $second = deployableNode(['type' => 'proxy', 'hostname' => 'b.example.com']);

        $scripts = app(NodeService::class);

        expect($scripts->buildDeployScript($first))->toContain("routers.nukevideo-proxy-{$first->id}.rule")
            ->and($scripts->buildDeployScript($second))->toContain("routers.nukevideo-proxy-{$second->id}.rule")
            ->and($scripts->buildDeployScript($first))->not->toContain('routers.proxy.');
    });

    it('keeps a development proxy out of production\'s Traefik namespace', function () {
        asLocalEnvironment();
        $node = deployableNode(['type' => 'proxy', 'hostname' => 'dev.example.com']);

        $script = app(NodeService::class)->buildDeployScript($node);

        expect($script)->toContain("routers.nukevideo_dev-proxy-{$node->id}.")
            ->and($script)->not->toContain("routers.nukevideo-proxy-{$node->id}.");
    });

    it('scopes the vector label to the environment', function () {
        // Vector reads the Docker socket, so it sees the other environment's containers too. A
        // shared `vector.enable=true` had production ingesting development's access logs and
        // billing them to production.
        $prod = app(NodeService::class)->buildDeployScript(deployableNode(['type' => 'proxy', 'hostname' => 'a.example.com']));

        asLocalEnvironment();
        $dev = app(NodeService::class)->buildDeployScript(deployableNode(['type' => 'proxy', 'hostname' => 'b.example.com']));

        expect($prod)->toContain('vector.enable=nukevideo')
            ->and($prod)->not->toContain('vector.enable=true')
            ->and($dev)->toContain('vector.enable=nukevideo_dev')
            ->and($dev)->toContain('VECTOR_SCOPE=nukevideo_dev');
    });

    it('still deploys production under the plain names', function () {
        $node = deployableNode();
        $script = app(NodeService::class)->buildDeployScript($node);

        expect($script)->toContain("nukevideo_worker_{$node->id}")
            ->and($script)->toContain("nukevideo_storage_{$node->id}")
            ->and($script)->toContain('nukevideo_chunks:/data')
            ->and($script)->not->toContain('nukevideo_dev');
    });

    it('pulls the development tag from the configured registry, never building on the node', function () {
        // Built and pushed by bin/push-node-dev from the working copy; the node only pulls. Under
        // its own tag, because `:dev` is compose's (target `api-dev`, no code in it) and the next
        // `compose up --build` would rebuild it from under the node.
        asLocalEnvironment();
        config(['nuke.registry' => '10.0.0.240:5000']);
        $script = app(NodeService::class)->buildDeployScript(deployableNode());

        expect($script)->toContain("IMAGE='10.0.0.240:5000/nukevideo-api:node-dev'")
            ->and($script)->toContain('pull_image "$IMAGE"')
            ->and($script)->not->toContain('docker build')
            ->and($script)->not->toContain('BUILD_TARGET')
            // Compose's own tag, the one with no code in it, must never be what a node runs.
            ->and($script)->not->toContain('nukevideo-api:dev');
    });

    it('pulls the proxy under the same development tag', function () {
        asLocalEnvironment();
        config(['nuke.registry' => '10.0.0.240:5000']);
        $script = app(NodeService::class)->buildDeployScript(
            deployableNode(['type' => 'proxy', 'hostname' => 'edge.example.com'])
        );

        expect($script)->toContain("IMAGE='10.0.0.240:5000/nukevideo-proxy:node-dev'");
    });

    it('refuses a development deploy with no registry rather than pulling a tag Docker Hub does not have', function () {
        // Unset means the namespace the releases are published under. A working copy has no
        // business there, in either direction: nothing to pull, and nothing must ever be pushed.
        asLocalEnvironment();
        config(['nuke.registry' => null]);

        app(NodeService::class)->buildDeployScript(deployableNode());
    })->throws(RuntimeException::class, 'bin/push-node-dev');

    it('leaves production on docker hub when no registry is configured', function () {
        $script = app(NodeService::class)->buildDeployScript(deployableNode());

        expect($script)->toContain("IMAGE='chikenare/nukevideo-api:".config('app.version')."'");
    });

    it('pulls production from the configured registry', function () {
        config(['nuke.registry' => 'registry.example.com:5000/']);
        $script = app(NodeService::class)->buildDeployScript(deployableNode());

        // Trailing slash trimmed — the name would otherwise carry a double separator.
        expect($script)->toContain("IMAGE='registry.example.com:5000/nukevideo-api:".config('app.version')."'")
            ->and($script)->not->toContain('chikenare/');
    });

    it('leaves production pulling the released image, never building on the node', function () {
        $script = app(NodeService::class)->buildDeployScript(deployableNode());
        $image = 'chikenare/nukevideo-api:'.config('app.version');

        expect($script)->toContain("IMAGE='{$image}'")
            ->and($script)->toContain('pull_image "$IMAGE"')
            ->and($script)->not->toContain('docker build');
    });
});

describe('chunk store bootstrap', function () {
    it('creates the bucket with the s5cmd the worker image already carries', function () {
        // minio/mc used to do this, and its image vanished from Docker Hub mid-deploy: the probe
        // may not depend on any image the node would not pull anyway.
        $script = app(NodeService::class)->buildDeployScript(deployableNode());

        expect($script)->not->toContain('minio/mc')
            ->toContain('--entrypoint sh "$IMAGE" -c "$STORAGE_BUCKET_CMD"')
            // The store listens on the private address only, so the probe asks it there.
            ->toMatch('/^STORAGE_BUCKET_CMD=\'s5cmd --endpoint-url http:\/\/\$CHUNK_STORE_IP:9000 ls \| grep /m')
            // `mb` errors on a bucket it already made, so a redeploy must only list it.
            ->toContain('|| s5cmd --endpoint-url http://$CHUNK_STORE_IP:9000 mb');
    });
});

describe('vector placement', function () {
    it('ships edge logs from a self-hosted proxy', function () {
        fakeCdnProvider('self_hosted');
        $script = app(NodeService::class)->buildDeployScript(
            deployableNode(['type' => 'proxy', 'hostname' => 'edge.example.com'])
        );

        expect($script)->toContain("VECTOR_RUN_ARGS='--name nukevideo_vector")
            ->and($script)->toContain('/etc/vector/vector.yaml');
    });

    it('does not run vector on a worker node, and clears one an older deploy left', function () {
        // Only the vod nginx writes the `ip=/bytes=/video=` lines the transform keeps; a worker's
        // logs are all dropped, so vector there reads every line of Horizon to produce nothing.
        fakeCdnProvider('self_hosted');
        $script = app(NodeService::class)->buildDeployScript(deployableNode());

        expect($script)->toContain("VECTOR_RUN_ARGS=''")
            ->and($script)->toContain("VECTOR_CONTAINER='nukevideo_vector'");
    });

    it('does not run vector behind bunny, whose logs come from its own API', function () {
        // Viewer traffic never touches our edge on Bunny; `bunny:ingest-logs` polls the Logging
        // API into the same bandwidth pipeline instead.
        fakeCdnProvider('bunny');
        $script = app(NodeService::class)->buildDeployScript(
            deployableNode(['type' => 'proxy', 'hostname' => 'edge.example.com'])
        );

        expect($script)->toContain("VECTOR_RUN_ARGS=''")
            ->and($script)->toContain("VECTOR_CONTAINER='nukevideo_vector'");
    });

    it('hands vector only the two variables its config reads', function () {
        fakeCdnProvider('self_hosted');
        $script = app(NodeService::class)->buildDeployScript(
            deployableNode(['type' => 'proxy', 'hostname' => 'edge.example.com'])
        );

        preg_match('/^VECTOR_RUN_ARGS=.*$/m', $script, $matches);

        expect($matches)->not->toBeEmpty()
            ->and($matches[0])->toContain('INTERNAL_API_URL=')
            ->and($matches[0])->toContain('INTERNAL_API_SECRET=')
            // The node environment carries database, S3 and webhook credentials with it.
            ->and($matches[0])->not->toContain('APP_KEY=')
            ->and($matches[0])->not->toContain('AWS_SECRET_ACCESS_KEY=');
    });
});

describe('what the edge container is given', function () {
    // Set for real, since a host without them would pass the assertions below by having nothing to
    // leak. `env()` reads these live, and `$_SERVER` ahead of `$_ENV`: the container running the
    // suite has some of them set already, which is why both are replaced and put back.
    beforeEach(function () {
        fakeCdnProvider('self_hosted');
        $this->hostEnv = [
            'DB_PASSWORD' => 'db-secret',
            'REDIS_PASSWORD' => 'redis-secret',
            'WEBHOOK_SECRET' => 'webhook-secret',
            'INTERNAL_API_SECRET' => 'internal-secret',
            'SENTRY_LARAVEL_DSN' => 'https://sentry.example/1',
            'AWS_ACCESS_KEY_ID' => 's3-key',
            'AWS_SECRET_ACCESS_KEY' => 's3-secret',
            'AWS_BUCKET' => 's3-bucket',
            'AWS_ENDPOINT' => 'https://s3.example.com',
            'AWS_DEFAULT_REGION' => 'us-east-1',
        ];
        $this->previousEnv = [$_SERVER, $_ENV];
        foreach ($this->hostEnv as $key => $value) {
            $_SERVER[$key] = $_ENV[$key] = $value;
        }
    });

    afterEach(function () {
        [$_SERVER, $_ENV] = $this->previousEnv;
    });

    $runArgs = function (Node $node): string {
        preg_match('/^RUN_ARGS=.*$/m', app(NodeService::class)->buildDeployScript($node), $matches);

        return $matches[0] ?? '';
    };

    it('leaves out every credential nginx has no use for', function () use ($runArgs) {
        $args = $runArgs(deployableNode(['type' => 'proxy', 'hostname' => 'edge.example.com']));

        expect($args)->not->toBe('');

        foreach (['DB_PASSWORD=', 'REDIS_PASSWORD=', 'APP_KEY=', 'WEBHOOK_SECRET=', 'INTERNAL_API_SECRET=', 'SENTRY_LARAVEL_DSN='] as $variable) {
            expect($args)->not->toContain($variable);
        }
    });

    it('leaves out a credential set in the node environment too', function () use ($runArgs) {
        expect($runArgs(deployableNode(['type' => 'proxy', 'hostname' => 'edge.example.com', 'env' => 'SOME_API_TOKEN=abc'])))
            ->not->toContain('SOME_API_TOKEN=');
    });

    it('keeps everything the entrypoint and the nginx template read', function () use ($runArgs) {
        $args = $runArgs(deployableNode(['type' => 'proxy', 'hostname' => 'edge.example.com']));

        foreach (['AWS_ACCESS_KEY_ID=s3-key', 'AWS_SECRET_ACCESS_KEY=s3-secret', 'AWS_BUCKET=s3-bucket', 'AWS_ENDPOINT=https://s3.example.com', 'AWS_DEFAULT_REGION=us-east-1', 'VOD_TOKEN_SECRET=', 'VOD_TOKEN_NAME=', 'NODE_ID='] as $variable) {
            expect($args)->toContain($variable);
        }
    });

    it('keeps the tuning a node sets for itself', function () use ($runArgs) {
        $args = $runArgs(deployableNode(['type' => 'proxy', 'hostname' => 'edge.example.com', 'env' => "VOD_S3_TLS_VERIFY=off\nVOD_CACHE_INACTIVE=7d"]));

        expect($args)->toContain('VOD_S3_TLS_VERIFY=off')
            ->and($args)->toContain('VOD_CACHE_INACTIVE=7d');
    });

    it('leaves out what merely looks like the edge\'s', function () use ($runArgs) {
        $args = $runArgs(deployableNode(['type' => 'proxy', 'hostname' => 'edge.example.com', 'env' => "AWS_OTHER_SECRET=abc\nVOD_SOMETHING=abc"]));

        foreach (['AWS_OTHER_SECRET=', 'VOD_SOMETHING=', 'VOD_BASE_URL=', 'AWS_USE_PATH_STYLE_ENDPOINT='] as $variable) {
            expect($args)->not->toContain($variable);
        }
    });

    it('hands the edge every variable its entrypoint and nginx template read', function () use ($runArgs) {
        // The other side of naming them one by one: a variable added to the edge and forgotten in
        // the list would reach nginx empty, on the next deploy, with nothing else to say why.
        // A list configured, because that variable is deploy-owned and absent without one.
        CdnSettings::fake([
            'provider' => 'self_hosted',
            'providers' => ['self_hosted' => ['token_secret' => 'secret', 'allowed_domains' => ['example.com']]],
        ]);

        $sources = file_get_contents(base_path('vod/entrypoint.sh')).file_get_contents(base_path('vod/nginx/nginx.conf.template'));
        preg_match_all('/\$\{?((?:AWS|VOD)_[A-Z0-9_]+)/', $sources, $named);

        $read = array_diff(array_unique($named[1]), [
            // Worked out by the entrypoint from AWS_ENDPOINT.
            'AWS_ENDPOINT_SCHEME', 'AWS_ENDPOINT_HOST', 'AWS_ENDPOINT_SERVER', 'AWS_ENDPOINT_RESOLVE',
            // Set by proxy.sh, which alone knows what this host's disks turned out to be.
            'VOD_CACHE_EXPECT_POOL',
        ]);

        $args = $runArgs(deployableNode([
            'type' => 'proxy',
            'hostname' => 'edge.example.com',
            'env' => implode("\n", array_map(fn (string $variable) => "{$variable}=1", $read)),
        ]));

        expect($read)->not->toBeEmpty();

        foreach ($read as $variable) {
            expect($args)->toContain("{$variable}=");
        }
    });

    it('signs with the read-only key when one is set, and the main key otherwise', function () use ($runArgs) {
        $node = deployableNode(['type' => 'proxy', 'hostname' => 'edge.example.com']);

        expect($runArgs($node))->toContain('AWS_ACCESS_KEY_ID=s3-key')->toContain('AWS_SECRET_ACCESS_KEY=s3-secret');

        EdgeS3Settings::fake(['access_key_id' => 'edge-key', 'secret_access_key' => 'edge-secret']);
        $args = $runArgs($node);

        expect($args)->toContain('AWS_ACCESS_KEY_ID=edge-key')
            ->toContain('AWS_SECRET_ACCESS_KEY=edge-secret')
            ->not->toContain('s3-secret')
            ->not->toContain('AWS_ACCESS_KEY_ID=s3-key');
    });

    it('does not sign with half a pair', function () use ($runArgs) {
        // The panel refuses this. If the row got there anyway, the main key still works.
        EdgeS3Settings::fake(['access_key_id' => 'edge-key', 'secret_access_key' => '']);

        expect($runArgs(deployableNode(['type' => 'proxy', 'hostname' => 'edge.example.com'])))
            ->toContain('AWS_ACCESS_KEY_ID=s3-key')
            ->toContain('AWS_SECRET_ACCESS_KEY=s3-secret');
    });

    it('never gives the read-only key to a worker, which has to write', function () {
        EdgeS3Settings::fake(['access_key_id' => 'edge-key', 'secret_access_key' => 'edge-secret']);

        expect(app(NodeService::class)->getEnvironmentVariables(deployableNode(['type' => 'worker'])))
            ->toContain('AWS_ACCESS_KEY_ID=s3-key')
            ->toContain('AWS_SECRET_ACCESS_KEY=s3-secret')
            ->not->toContain('AWS_ACCESS_KEY_ID=edge-key');
    });

    it('leaves vector and workers with what they need', function () {
        $service = app(NodeService::class);
        $proxy = $service->buildDeployScript(deployableNode(['type' => 'proxy', 'hostname' => 'edge.example.com']));
        preg_match('/^VECTOR_RUN_ARGS=.*$/m', $proxy, $vector);

        expect($vector[0])->toContain('INTERNAL_API_SECRET=internal-secret');

        // A worker runs the application against the database, so it keeps the whole environment.
        expect($service->getEnvironmentVariables(deployableNode(['type' => 'worker'])))
            ->toContain('DB_PASSWORD=db-secret')
            ->toContain('AWS_SECRET_ACCESS_KEY=s3-secret');
    });
});

describe('edge token settings', function () {
    it('hands the proxy the query argument the signer actually writes', function () {
        // The name is one setting read by two sides: `SelfHostedProvider` signs `?<name>=...` and
        // the edge's nginx.conf validates `$arg_<name>`. While it was hardcoded in the template,
        // changing it in the panel left the edge reading an argument nobody sent — a 403 on every
        // manifest and segment, with only the unsigned assets still served.
        CdnSettings::fake([
            'provider' => 'self_hosted',
            'providers' => ['self_hosted' => ['token_secret' => 'secret', 'token_name' => 'nv_token']],
        ]);

        $env = app(NodeService::class)->getEnvironmentVariables(
            deployableNode(['type' => 'proxy', 'hostname' => 'edge.example.com'])
        );

        expect($env)->toContain('VOD_TOKEN_NAME=nv_token');
    });

    it('falls back to the akamai name both sides default to', function () {
        fakeCdnProvider('self_hosted');

        $env = app(NodeService::class)->getEnvironmentVariables(
            deployableNode(['type' => 'proxy', 'hostname' => 'edge.example.com'])
        );

        expect($env)->toContain('VOD_TOKEN_NAME=__hdnea__');
    });

    it('hands the edge no domain list while none is configured', function () {
        fakeCdnProvider('self_hosted');
        config()->set('sanctum.stateful', ['panel.example.com']);

        $env = app(NodeService::class)->getEnvironmentVariables(
            deployableNode(['type' => 'proxy', 'hostname' => 'edge.example.com'])
        );

        expect(implode("\n", $env))->not->toContain('VOD_ALLOWED_DOMAINS');
    });

    it('hands the edge the allowed domains plus the panel, which must keep playing', function () {
        CdnSettings::fake([
            'provider' => 'self_hosted',
            'providers' => ['self_hosted' => ['token_secret' => 'secret', 'allowed_domains' => ['example.com', '*.site.example']]],
        ]);
        // What the default of SANCTUM_STATEFUL_DOMAINS looks like, plus a panel on its own port.
        // The loopback names are nobody's panel in production, and any page on a viewer's machine
        // could claim them.
        config()->set('sanctum.stateful', ['localhost', 'localhost:3000', '127.0.0.1', '127.0.0.1:8000', '::1', 'Panel.example.org:8443', 'example.com']);

        $env = app(NodeService::class)->getEnvironmentVariables(
            deployableNode(['type' => 'proxy', 'hostname' => 'edge.example.com'])
        );

        expect($env)->toContain('VOD_ALLOWED_DOMAINS=example.com,*.site.example,panel.example.org');
    });

    it('does not let a node override loosen the allowed domains', function () {
        CdnSettings::fake([
            'provider' => 'self_hosted',
            'providers' => ['self_hosted' => ['token_secret' => 'secret', 'allowed_domains' => ['example.com']]],
        ]);
        config()->set('sanctum.stateful', []);

        $env = app(NodeService::class)->getEnvironmentVariables(
            deployableNode(['type' => 'proxy', 'hostname' => 'edge.example.com', 'env' => 'VOD_ALLOWED_DOMAINS=evil.example'])
        );

        expect($env)->toContain('VOD_ALLOWED_DOMAINS=example.com')
            ->and($env)->not->toContain('VOD_ALLOWED_DOMAINS=evil.example');
    });
});

describe('name resolution inside the containers', function () {
    beforeEach(fn () => fakeCdnProvider('self_hosted'));

    it('keeps a lost DNS packet to a one-second retry in every container a deploy raises', function (array $attributes) {
        // A home router's NAT dropped one of the A/AAAA pair glibc sends from a single socket, and
        // 1 in 10-30 lookups inside the workers stalled 5s.
        $script = app(NodeService::class)->buildDeployScript(deployableNode($attributes));

        preg_match_all('/^[A-Z_]*RUN_ARGS=\'(--name .*)$/m', $script, $runs);

        expect($runs[1])->not->toBeEmpty();

        foreach ($runs[1] as $run) {
            expect($run)->toContain('--dns-opt single-request-reopen --dns-opt timeout:1 --dns-opt attempts:3');
        }
    })->with([
        'worker with its storage' => [['type' => 'worker']],
        'proxy' => [['type' => 'proxy', 'hostname' => 'edge.example.com']],
    ]);
});

describe('fleet-wide timing', function () {
    beforeEach(fn () => fakeCdnProvider('self_hosted'));

    it('keeps the worker timeout and the redelivery window the same on every node', function () {
        // Chunks are planned against the timeout of the node that prepares the video and encoded
        // on whichever node pulls them: one node running longer than the rest plans windows the
        // others kill mid-encode. The reaper and the redeploy drain read the same constants.
        $env = app(NodeService::class)->getEnvironmentVariables(
            deployableNode(['env' => "VIDEO_WORKER_TIMEOUT=1200\nREDIS_QUEUE_RETRY_AFTER=60"])
        );

        expect($env)->toContain('VIDEO_WORKER_TIMEOUT='.NodeService::WORKER_TIMEOUT)
            ->and($env)->toContain('REDIS_QUEUE_RETRY_AFTER='.NodeService::QUEUE_RETRY_AFTER)
            ->and($env)->not->toContain('VIDEO_WORKER_TIMEOUT=1200')
            ->and($env)->not->toContain('REDIS_QUEUE_RETRY_AFTER=60');
    });
});
