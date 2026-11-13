<?php

namespace App\Services;

use App\Data\Node\DeployNodeData;
use App\Data\NodeData;
use App\Enums\NodeAccel;
use App\Enums\NodeType;
use App\Models\Node;
use App\Settings\CdnSettings;
use App\Settings\EdgeS3Settings;
use App\Settings\NodeSettings;
use App\Support\AllowedDomains;
use App\Support\Cpu;
use Illuminate\Support\Facades\Log;

class NodeService
{
    public function __construct(
        private SshKeyService $sshKeys,
        private SSHService $ssh,
        private NodeSettings $nodeSettings,
        private CdnSettings $cdnSettings,
        private CloudflareDns $cloudflare,
        private EdgeS3Settings $edgeS3,
    ) {}

    public function index(): array
    {
        return [
            'data' => [
                'nodes' => Node::all()->map(fn ($n) => NodeData::fromModel($n))->all(),
            ],
        ];
    }

    public function createNode(array $data): Node
    {
        return Node::create($data);
    }

    private const DOCKER_RUN_FLAGS = ['DOCKER_CPUSET_CPUS', 'DOCKER_MEMORY'];

    // What every worker node runs with. Public because they are also the recovery window a video
    // gets when a worker dies mid-job: the queue re-delivers after RETRY_AFTER and the redelivered
    // job may run for WORKER_TIMEOUT, so {@see \App\Console\Commands\ReapStuckVideos} waits both
    // out before calling a video stuck. The API host's own env says nothing about either.
    public const WORKER_TIMEOUT = 600;

    public const QUEUE_RETRY_AFTER = 1850;

    // How long a redeploy waits for the old worker to finish its in-flight jobs before SIGKILL.
    // Horizon on SIGTERM stops taking work and finishes what it has; killing it early instead
    // strands those jobs as `reserved` in Redis for QUEUE_RETRY_AFTER (~31 min) with the new
    // container up and idle. One chunk pass (WORKER_TIMEOUT) plus slack covers what is running
    // almost always; a packaging job caught mid-run still pays the redelivery wait, by choice —
    // covering it too would hold every deploy for up to 30 minutes.
    public const WORKER_STOP_GRACE = self::WORKER_TIMEOUT + 60;

    // Environments whose in-flight work is disposable, so a deploy kills instead of draining.
    private const UNDRAINED_ENVIRONMENTS = ['local', 'staging'];

    // The images of the containers a host shares, pinned to a release and pulled at deploy. Their
    // tag is part of the config hash that decides whether a running one is kept, so bumping a
    // version here is what updates the fleet — once, at each host's next deploy. A floating tag
    // was only ever downloaded once per host and never changed the hash.
    private const TRAEFIK_IMAGE = 'traefik:v3.6.25';

    private const CHUNK_STORE_IMAGE = 'rustfs/rustfs:1.0.0';

    // Keeps the worker's idle Redis connection alive through ISP CGNAT during long ffmpeg
    // encodes; without outgoing traffic the NAT mapping is dropped and the next command read-errors.
    private const WORKER_SYSCTLS = [
        'net.ipv4.tcp_keepalive_time=60',
        'net.ipv4.tcp_keepalive_intvl=10',
        'net.ipv4.tcp_keepalive_probes=6',
    ];

    private const PROPAGATED_FROM_HOST = [
        'DB_CONNECTION',
        'DB_HOST',
        'DB_PORT',
        'DB_DATABASE',
        'DB_USERNAME',
        'DB_PASSWORD',

        'REDIS_HOST',
        'REDIS_PORT',
        'REDIS_PASSWORD',

        'AWS_ACCESS_KEY_ID',
        'AWS_SECRET_ACCESS_KEY',
        'AWS_DEFAULT_REGION',
        'AWS_BUCKET',
        'AWS_ENDPOINT',
        'AWS_USE_PATH_STYLE_ENDPOINT',

        'WEBHOOK_SECRET',
        'INTERNAL_API_SECRET',

        'SENTRY_LARAVEL_DSN',
        'SENTRY_TRACES_SAMPLE_RATE',
    ];

    public function getEnvironmentVariables(Node $node): array
    {
        $scheme = Node::proxyScheme();

        $base = [
            'APP_ENV' => 'APP_ENV='.config('app.env'),
            'APP_DEBUG' => 'APP_DEBUG='.(config('app.debug') ? 'true' : 'false'),
            'APP_KEY' => 'APP_KEY='.config('app.key'),
            'APP_URL' => 'APP_URL='.config('app.url'),
            'API_UPSTREAM_HOST' => 'API_UPSTREAM_HOST='.parse_url(config('app.url'), PHP_URL_HOST),
            'NODE_ID' => "NODE_ID={$node->id}",
            'NODE_TYPE' => "NODE_TYPE={$node->type->value}",
            'VIDEO_WORKER_TIMEOUT' => 'VIDEO_WORKER_TIMEOUT='.self::WORKER_TIMEOUT,
            'REDIS_QUEUE_RETRY_AFTER' => 'REDIS_QUEUE_RETRY_AFTER='.self::QUEUE_RETRY_AFTER,
            // Unset, Laravel logs at `debug`, and a worker writes every ffmpeg command it runs:
            // 409 of the 417 MB one node logged in nine days, each line carrying a presigned
            // chunk-store URL. A node's own env can still ask for `debug` while chasing something.
            'LOG_LEVEL' => 'LOG_LEVEL='.(config('app.debug') ? 'debug' : 'info'),
            'DOMAIN' => "DOMAIN={$node->hostname}",
            'VOD_BASE_URL' => "VOD_BASE_URL={$scheme}{$node->hostname}",
            'INTERNAL_API_URL' => 'INTERNAL_API_URL='.config('nuke.internal.url'),
        ];

        // Workers only: a proxy reads the primary bucket and never the chunk store.
        $chunkStore = $this->nodeSettings->chunkStore();
        if ($node->type === NodeType::WORKER && $chunkStore) {
            [$host, $port] = $chunkStore;
            $base['CHUNKS_S3_ENDPOINT'] = "CHUNKS_S3_ENDPOINT=http://{$host}:{$port}";
        }

        if ($node->accel) {
            $base['NODE_ACCEL'] = "NODE_ACCEL={$node->accel->value}";
        }

        foreach (self::PROPAGATED_FROM_HOST as $key) {
            $value = env($key);
            if ($value !== null && $value !== false) {
                $base[$key] = "{$key}=".(is_bool($value) ? ($value ? 'true' : 'false') : $value);
            }
        }

        // VOD/edge env now lives in CdnSettings (UI-editable), no longer host env. The nginx
        // container on proxy nodes still consumes these names; empty values are skipped so its
        // entrypoint defaults apply. Cache sizing is deliberately not among them: the edge sizes
        // its cache to the filesystem it is given ({@see vod/entrypoint.sh}), and the one case
        // that needs a cap — no pool, caching on the OS disk — is set by the deploy script.
        $cdn = $this->cdnSettings;
        $cdnEnv = [
            'VOD_TOKEN_SECRET' => $cdn->token_secret,
            'VOD_TOKEN_NAME' => $cdn->token_name,
            'VOD_ALLOWED_DOMAINS' => implode(',', AllowedDomains::forEdge($cdn->allowed_domains)),
            // Absent rather than `0` while off: the entrypoint reads anything but `1` as off.
            'VOD_ALLOW_NULL_ORIGIN' => $cdn->allow_null_origin ? '1' : '',
        ];
        foreach ($cdnEnv as $key => $value) {
            if ($value !== '') {
                $base[$key] = "{$key}={$value}";
            }
        }

        $settings = $this->parseEnvText($this->nodeSettings->environment);
        $nodeOverrides = $this->parseEnvText($node->env ?? '');

        // Overrides still win — for everything but the deploy-owned keys, which are stripped
        // from them (and logged) rather than honoured. Node overrides used to win outright, so a line in
        // one node's env — a text field anyone with the admin panel can edit — could point that
        // edge's INTERNAL_API_URL at a host of its own choosing, swap its S3 credentials, or
        // replace VOD_TOKEN_SECRET with one it knows and mint its own playback tokens. Those keys
        // are the deploy's to set; the override field is for tuning, not for re-plumbing.
        $overrides = array_merge($settings, $nodeOverrides);
        foreach (array_intersect_key($overrides, array_flip(self::DEPLOY_OWNED_ENV)) as $key => $line) {
            if (isset($base[$key]) && $base[$key] !== $line) {
                Log::warning('Ignoring a node env override of a deploy-owned variable', ['node_id' => $node->id, 'key' => $key]);
            }
        }

        return array_values(array_filter(
            array_merge($base, array_diff_key($overrides, array_flip(self::DEPLOY_OWNED_ENV))),
            fn ($v) => ! in_array(explode('=', $v, 2)[0], self::DOCKER_RUN_FLAGS)
        ));
    }

    /**
     * Variables the deploy sets and neither the global node environment nor a node's own `env`
     * may replace: the credentials and the endpoints that make a node part of *this*
     * installation. See {@see getEnvironmentVariables()} for what an override of one would buy.
     */
    private const DEPLOY_OWNED_ENV = [
        'APP_KEY',
        'APP_URL',
        'API_UPSTREAM_HOST',
        'NODE_ID',
        'NODE_TYPE',
        'INTERNAL_API_URL',
        'INTERNAL_API_SECRET',
        'WEBHOOK_SECRET',
        'VOD_TOKEN_SECRET',
        'VOD_TOKEN_NAME',
        // One line in a node's env would otherwise exempt that edge from the operator's list.
        'VOD_ALLOWED_DOMAINS',
        'VOD_ALLOW_NULL_ORIGIN',
        'AWS_ACCESS_KEY_ID',
        'AWS_SECRET_ACCESS_KEY',
        'AWS_BUCKET',
        'AWS_ENDPOINT',
        // Fleet-wide by nature: chunks are planned against the timeout of the node that prepares
        // the video and encoded on whichever node pulls them, and the reaper and the redeploy
        // drain read the constants these come from. One node set apart kills the others' chunks.
        'VIDEO_WORKER_TIMEOUT',
        'REDIS_QUEUE_RETRY_AFTER',
    ];

    /**
     * What the edge's container is given: exactly what its entrypoint and nginx template read. Not
     * the whole node environment, which carries the database password and APP_KEY, because this is
     * the one container that answers the internet directly and a process with a foothold in it
     * could read them. Named one by one rather than by prefix, so nothing reaches it that nobody
     * decided should: adding a variable to the edge means adding it here, and a test fails until
     * it is.
     */
    private const EDGE_ENV = [
        'NODE_ID',
        'AWS_ACCESS_KEY_ID',
        'AWS_SECRET_ACCESS_KEY',
        'AWS_DEFAULT_REGION',
        'AWS_BUCKET',
        'AWS_ENDPOINT',
        'VOD_TOKEN_SECRET',
        'VOD_TOKEN_NAME',
        'VOD_ALLOWED_DOMAINS',
        'VOD_ALLOW_NULL_ORIGIN',
        'VOD_S3_TLS_VERIFY',
        // Where the edge asks for master manifests, and the secret it asks with — the same one
        // the node's Vector ships the access log with. The only credential in this container
        // besides the bucket's read keys and the token secret.
        'INTERNAL_API_URL',
        'INTERNAL_API_SECRET',
        'VOD_CACHE_MAX_SIZE',
        'VOD_CACHE_MIN_FREE',
        'VOD_CACHE_KEYS_ZONE',
        'VOD_CACHE_INACTIVE',
    ];

    /** @return list<string> */
    private function edgeEnvironment(Node $node): array
    {
        $env = array_values(array_filter(
            $this->getEnvironmentVariables($node),
            fn (string $line) => in_array(explode('=', $line, 2)[0], self::EDGE_ENV, true),
        ));

        // Both or neither: half a pair signs every S3 request wrongly, and every cache miss on the
        // edge would be a 403. The panel refuses it, and this keeps a row that got here anyway
        // from taking delivery down.
        if ($this->edgeS3->access_key_id === '' || $this->edgeS3->secret_access_key === '') {
            return $env;
        }

        return [
            ...array_values(array_filter(
                $env,
                fn (string $line) => ! in_array(explode('=', $line, 2)[0], ['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY'], true),
            )),
            "AWS_ACCESS_KEY_ID={$this->edgeS3->access_key_id}",
            "AWS_SECRET_ACCESS_KEY={$this->edgeS3->secret_access_key}",
        ];
    }

    /**
     * Reads the same merged chain the environment does, node overriding settings. It used to look
     * only at the node's own `env`, so a `DOCKER_MEMORY` set once in the global node environment
     * was stripped out as a docker flag and then never recovered as one — the container ran with
     * no `--memory` and no variable either, and {@see Cpu} sized the encode pool for
     * the host's whole RAM. The two sides have to read from one place or the knob lies.
     */
    private function extractDockerFlags(Node $node): array
    {
        $merged = array_merge(
            $this->parseEnvText($this->nodeSettings->environment),
            $this->parseEnvText($node->env ?? ''),
        );

        $flags = [];
        foreach ($merged as $key => $line) {
            if (in_array($key, self::DOCKER_RUN_FLAGS)) {
                $flags[$key] = explode('=', $line, 2)[1] ?? '';
            }
        }

        return $flags;
    }

    private function parseEnvText(string $text): array
    {
        $vars = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_contains($line, '=')) {
                $key = explode('=', $line, 2)[0];
                $vars[$key] = $line;
            }
        }

        return $vars;
    }

    public static function workdir(Node $node): string
    {
        return "/home/{$node->user}/nukevideo/node-{$node->uuid}";
    }

    /**
     * Seconds a deploy gives the old worker to finish its in-flight jobs before SIGKILL.
     *
     * Killing one is not free: it sits `reserved` in Redis for QUEUE_RETRY_AFTER (~31 min) with
     * the new container idle, and the video stays in an active status the whole time, holding a
     * slot `videos:dispatch` counts against the fleet's concurrency. That is worth waiting out on
     * a live fleet and pure friction on a machine that redeploys every few minutes, so
     * development and staging kill outright. Allowlisted rather than derived from
     * `isProduction()`, because an unset or unexpected APP_ENV must still drain — the safe side
     * of this choice is the one that waits.
     */
    public static function drainGrace(): int
    {
        return app()->environment(self::UNDRAINED_ENVIRONMENTS) ? 0 : self::WORKER_STOP_GRACE;
    }

    /**
     * @param  array<int, string>|null  $disks  spare disks to format into the cache pool (proxies);
     *                                          null formats every spare disk, [] formats none.
     *                                          The controller never passes null for a proxy
     *                                          ({@see DeployNodeData}).
     * @param  array<int, string>  $sharedDisks  system disks that give the pool a partition over
     *                                           their unpartitioned space; never implied.
     */
    public function runFullDeploy(Node $node, \Closure $onOutput, bool $drain = true, ?array $disks = null, array $sharedDisks = []): void
    {
        $script = $this->buildDeployScript($node, $disks, $sharedDisks);
        $grace = $drain ? self::drainGrace() : 0;

        // Before anything runs on the node: Traefik asks for the certificate the moment the edge
        // is up, and the challenge is answered at the hostname. After the script is built, so a
        // deploy refused for its own reasons has not already published a record.
        if ($node->type === NodeType::PROXY && ($dns = $this->cloudflare->sync($node)) !== null) {
            $onOutput("=== Cloudflare DNS ===\n{$dns}\n");
        }

        // SSH timeout covers the worst case: the drain window plus image pull and startup.
        // The old flat 300s cut the session mid-drain and left no container at all.
        $this->ssh($node, 'bash -s'.($drain ? '' : ' -- --no-drain'), $grace + 600, $script, $onOutput);

        // A deploy that returned is a stronger signal than any probe: the host answered over SSH
        // and the script reached its last line. Without this a redeployed proxy stayed out of
        // rotation until the next probe cycle confirmed it.
        $node->markHealthy();
    }

    /**
     * `docker start` on what the deploy raised. Only starts containers that exist: a node that
     * was never deployed has nothing to start, and saying so beats a deploy half-happening
     * behind a button.
     */
    public function startServices(Node $node, \Closure $onOutput): void
    {
        $containers = implode(' ', $node->deployedContainerNames());
        $this->ssh($node, "docker start {$containers} 2>&1", 60, onOutput: $onOutput);
        $node->markHealthy();
    }

    /**
     * Graceful by default: the worker was created with `--stop-timeout` WORKER_STOP_GRACE, so a
     * plain `docker stop` is Horizon finishing its in-flight jobs first. `$force` kills — those
     * jobs redeliver after QUEUE_RETRY_AFTER instead.
     *
     * A worker's signal is named here as well as at `docker run`: a container keeps the signal it
     * was created with, and one raised before {@see workerVars()} set it still has the image's
     * SIGQUIT, which Horizon ignores. A proxy keeps its own, SIGQUIT, nginx's graceful stop.
     */
    public function stopServices(Node $node, bool $force, \Closure $onOutput): void
    {
        $containers = implode(' ', $node->deployedContainerNames());
        $verb = match (true) {
            $force => 'kill',
            $node->type === NodeType::WORKER => 'stop -s SIGTERM',
            default => 'stop',
        };
        $this->ssh($node, "docker {$verb} {$containers} 2>&1 || true", self::WORKER_STOP_GRACE + 60, onOutput: $onOutput);
    }

    /**
     * The deploy is bash that lives in `resources/deploy/*.sh`, unchanged from node to node. What
     * this composes is the header in front of it: one assignment per variable, every value
     * quoted, and nothing a shell would read as code. The PHP side decides (which image, which
     * containers, which disks); the shell side acts. Keeping the two apart is what makes the
     * scripts readable and lintable as scripts, and keeps every node-supplied value from ever
     * being interpolated into one.
     */
    public function buildDeployScript(Node $node, ?array $disks = null, array $sharedDisks = []): string
    {
        $type = $node->type->value;

        $vars = [
            'NODE_ID' => $node->id,
            'NODE_TYPE' => $type,
            'WORKDIR' => self::workdir($node),
            'DRAIN' => self::drainGrace(),
            'SERVICE_CONTAINER' => $node->serviceContainerName(),
            ...$this->imageVars($node->type === NodeType::PROXY ? 'proxy' : 'api'),
            ...match ($node->type) {
                NodeType::WORKER => $this->workerVars($node),
                NodeType::PROXY => $this->proxyVars($node, $disks, $sharedDisks),
            },
            ...$this->vectorVars($node),
        ];

        // The name is free text and lands on a comment line, which a newline would end —
        // everything after it would run on the node as part of the script.
        $name = preg_replace('/[^\w .\-]/u', '', $node->name);

        $header = "#!/bin/bash\n# Nukevideo Node Deployment — {$name} ({$type}, ID: {$node->id})\n\n";
        foreach ($vars as $key => $value) {
            // Integers bare, everything else single-quoted by escapeshellarg: a value can only
            // ever be a value. A null leaves the variable unset, which the scripts test for.
            if ($value === null) {
                continue;
            }
            $header .= $key.'='.(is_int($value) ? $value : escapeshellarg((string) $value))."\n";
        }

        // sudo.sh first: everything after it leans on $SUDO having been settled or the run aborted.
        // packages.sh next, for the same reason: it is how every later section installs a tool.
        $sections = ['sudo', 'packages', 'common', $node->type === NodeType::PROXY ? 'cache-disks' : null, $type, 'vector'];

        return $header."\n".implode("\n", array_map(
            fn ($section) => self::deployScript($section),
            array_filter($sections),
        ))."\necho \"\"\necho \"=== Deployment complete — node {$node->id} is running ===\"\n";
    }

    public static function deployScript(string $name): string
    {
        return "# --- {$name}.sh ---\n".file_get_contents(resource_path("deploy/{$name}.sh"));
    }

    /**
     * A node only ever pulls. Production pulls the released tag; development pulls the
     * `node-dev` tag that `bin/push-node-dev` built from the working copy and pushed to the
     * configured registry. Nodes used to build that tag themselves, from the checkout the panel
     * runs from — which meant the deploy user had to read the developer's home, a build ran
     * inside the deploy window, and an external node got a different image than the local one.
     * One producer, one tag, and a node never needs the source.
     */
    private function imageVars(string $type): array
    {
        if (app()->isLocal() && ! config('nuke.registry')) {
            // Without a registry the name resolves to Docker Hub, where no `node-dev` tag exists
            // (and must never exist: that namespace is where releases are published). Failing here
            // beats a node reporting "image not found" at the end of an SSH session.
            throw new \RuntimeException('Deploying a node from a development panel needs DOCKER_REGISTRY: build and push the image with bin/push-node-dev first.');
        }

        return [
            'IMAGE' => $this->resolveImage($type),
        ];
    }

    private function workerVars(Node $node): array
    {
        if (! $this->nodeSettings->chunkStore()) {
            throw new \RuntimeException('No chunk store configured. Set its address under Nodes → Environment before deploying a worker.');
        }

        $dockerFlags = $this->extractDockerFlags($node);

        $runArgs = $this->buildDockerRunArgs($node->serviceContainerName(), $this->resolveImage('api'), [
            'env' => $this->getEnvironmentVariables($node),
            'labels' => ['vector.enable='.Node::containerPrefix()],
            'sysctls' => self::WORKER_SYSCTLS,
            // So a plain `docker stop` (host reboot included) also drains instead of killing at 10s.
            'stop_timeout' => self::WORKER_STOP_GRACE,
            // The base image declares STOPSIGNAL SIGQUIT, which is php-fpm's graceful stop. Horizon
            // only traps SIGTERM and SIGINT, and as PID 1 an untrapped signal is dropped by the
            // kernel: every stop sat out the whole grace with Horizon still taking work, then
            // SIGKILLed it mid-job.
            'stop_signal' => 'SIGTERM',
            'command' => 'php /var/www/html/artisan horizon',
            'healthcheck' => 'healthcheck-horizon',
            'cpuset' => $dockerFlags['DOCKER_CPUSET_CPUS'] ?? null,
            'memory' => $dockerFlags['DOCKER_MEMORY'] ?? null,
            // $RENDER_GID is resolved by worker.sh, on the node itself.
            'devices' => $node->accel === NodeAccel::INTEL ? ['/dev/dri:/dev/dri'] : [],
            'group_add' => $node->accel === NodeAccel::INTEL ? '"${RENDER_GID:-44}"' : null,
            'gpus' => $node->accel === NodeAccel::NVIDIA,
        ]);

        return [
            'NODE_ACCEL' => $node->accel?->value ?? '',
            'RUN_ARGS' => $runArgs,
            ...$this->chunkStoreVars($node),
        ];
    }

    /**
     * Every worker gets the store's run arguments, and its deploy decides on the host itself
     * whether to use them: the store runs where the fleet's chunk store address resolves to one of
     * the node's own interfaces. Compared there and not against the node's `ip_address`, which is
     * the address the panel reaches it by over SSH — often the public one, while the store must
     * answer on the private network, or by a DNS name. Published on that address alone, never on
     * every interface: it holds every in-flight source and chunk, behind the same credentials as
     * the primary bucket.
     */
    private function chunkStoreVars(Node $node): array
    {
        $disk = config('filesystems.disks.chunks');

        $options = [
            'env' => [
                'RUSTFS_ACCESS_KEY='.$disk['key'],
                'RUSTFS_SECRET_KEY='.$disk['secret'],
                'RUSTFS_ADDRESS=:9000',
                'RUSTFS_CONSOLE_ENABLE=false',
            ],
            'labels' => ['vector.enable='.Node::containerPrefix()],
            // Prefixed like the containers: a development store must never be handed the volume
            // holding the fleet's mirrored sources and chunks.
            'volumes' => [Node::containerPrefix().'_chunks:/data'],
            // $CHUNK_STORE_IP is resolved by worker.sh, on the node.
            'raw' => ['-p "$CHUNK_STORE_IP:$CHUNK_STORE_PORT:9000"'],
            'command' => '/data',
        ];

        // worker.sh keeps a running store whose label matches, as proxy.sh does Traefik: recreating
        // it on every deploy of its worker cut the other workers' transfers mid fleet deploy. The
        // hash covers the arguments and the address; the IP the node resolves is appended there,
        // since a DNS name can move without the address changing.
        [$host, $port] = $this->nodeSettings->chunkStore();
        $config = substr(md5($this->buildDockerRunArgs($node->storageContainerName(), self::CHUNK_STORE_IMAGE, $options)."|{$host}:{$port}"), 0, 12);
        $options['raw'][] = '-l "nukevideo.config='.$config.'-$CHUNK_STORE_IP"';
        $runArgs = $this->buildDockerRunArgs($node->storageContainerName(), self::CHUNK_STORE_IMAGE, $options);

        // Readiness and the bucket in one probe, run with the s5cmd the worker image already carries
        // — minio/mc used to do it, and its image is gone from Docker Hub. `mb` alone is not
        // idempotent (a redeploy finds the bucket and errors), so it only runs when the bucket
        // list lacks it; while the store is still booting both fail and the deploy retries.
        // Single-quoted in the header and expanded inside the probe container, which is handed
        // CHUNK_STORE_IP: the store answers on that address only.
        $s5cmd = 's5cmd --endpoint-url http://$CHUNK_STORE_IP:'.$port;
        $bucket = escapeshellarg('s3://'.$disk['bucket']);

        return [
            'CHUNK_STORE_HOST' => $host,
            'CHUNK_STORE_PORT' => $port,
            'STORAGE_CONTAINER' => $node->storageContainerName(),
            'STORAGE_RUN_ARGS' => $runArgs,
            'STORAGE_CONFIG' => $config,
            'STORAGE_IMAGE' => self::CHUNK_STORE_IMAGE,
            'STORAGE_ACCESS_KEY' => 'AWS_ACCESS_KEY_ID='.$disk['key'],
            'STORAGE_SECRET_KEY' => 'AWS_SECRET_ACCESS_KEY='.$disk['secret'],
            'STORAGE_BUCKET_CMD' => "{$s5cmd} ls | grep -qxE '.*[[:space:]]'{$bucket} || {$s5cmd} mb {$bucket}",
        ];
    }

    private function proxyVars(Node $node, ?array $disks, array $sharedDisks): array
    {
        // nginx templates the secure-token key at boot; an empty one renders `key ;` and the
        // container crashloops on an emerg. Fail here with a message that names the fix instead.
        if ($this->cdnSettings->token_secret === '') {
            throw new \RuntimeException('CDN token secret is empty. Set it in CDN Settings before deploying a proxy node.');
        }

        $labels = ['vector.enable='.Node::containerPrefix()];
        // The scheme the links are minted with, not the environment: a router on `web` behind an
        // https:// link is a 404 from Traefik ({@see Node::proxyScheme}).
        $tls = Node::proxyScheme() === 'https://';

        if ($node->hostname) {
            $entrypoint = $tls ? 'websecure' : 'web';

            // Namespaced per node AND per environment. Traefik keys routers by name across every
            // container it discovers on the host, and it discovers all of them — so a fixed `proxy`
            // meant a second proxy node, or the same host running development alongside production,
            // redefined production's router with a different rule and entrypoint. Traefik drops the
            // conflicting definition, and the edge stops resolving. The container prefix isolates
            // names and volumes; this is the same boundary in Traefik's namespace.
            $router = Node::containerPrefix()."-proxy-{$node->id}";

            $labels[] = 'traefik.enable=true';
            $labels[] = "traefik.http.routers.{$router}.rule=Host(`{$node->hostname}`)";
            $labels[] = "traefik.http.routers.{$router}.entrypoints={$entrypoint}";
            $labels[] = "traefik.http.services.{$router}.loadbalancer.server.port=80";
            // TLS without naming a resolver: the certificate is the business of whichever Traefik
            // fronts the edge. Ours makes `le` the default of `websecure` ({@see traefikVars});
            // a host whose own reverse proxy holds port 80 uses the default it was given.
            if ($tls) {
                $labels[] = "traefik.http.routers.{$router}.tls=true";
            }
        }

        $runArgs = $this->buildDockerRunArgs($node->serviceContainerName(), $this->resolveImage('proxy'), [
            'env' => $this->edgeEnvironment($node),
            'labels' => $labels,
            'network' => 'nukevideo_default',
            // Resolved on the node by proxy.sh: CACHE_MOUNT is the pool's directory, or the
            // fallback volume when the host has no pool — and only then is the cache capped,
            // because a volume shares the OS disk. With a pool, CACHE_EXPECT_POOL tells the
            // entrypoint to look for the marker cache-disks.sh left on it, so a container that
            // comes back on a host booted without the pool caps itself instead of sizing the
            // cache to the OS disk ({@see vod/entrypoint.sh}).
            'raw' => [
                '-v "$CACHE_MOUNT:'.ProxyCacheService::CONTAINER_PATH.'"',
                '${CACHE_MAX_SIZE:+-e "VOD_CACHE_MAX_SIZE=$CACHE_MAX_SIZE"}',
                '${CACHE_EXPECT_POOL:+-e "VOD_CACHE_EXPECT_POOL=$CACHE_EXPECT_POOL"}',
                // What proxy.sh found this host accepts out of EDGE_TUNING_FLAGS.
                '$EDGE_TUNING',
                // Set by proxy.sh when the host's own reverse proxy holds port 80: the network the
                // edge joins to be reachable from it, named so that proxy routes over that one.
                '${EDGE_PROXY_NETWORK:+-l "traefik.docker.network=$EDGE_PROXY_NETWORK"}',
            ],
            'log_opts' => self::PROXY_LOG_OPTS,
            // nginx is PID 1, and SIGTERM is its fast shutdown: open connections are dropped.
            // SIGQUIT finishes the requests in flight, which is what a `docker stop` means here.
            'stop_signal' => 'SIGQUIT',
        ]);

        return [
            'RUN_ARGS' => $runArgs,
            'CACHE_DIRECTORY' => ProxyCacheService::directoryFor($node),
            'CACHE_VOLUME' => ProxyCacheService::volumeFor($node),
            'CACHE_FALLBACK_MAX_SIZE' => ProxyCacheService::FALLBACK_MAX_SIZE,
            // Unset means every spare disk; a list (possibly empty) means exactly those.
            'CHOSEN_DISKS' => $disks === null ? null : implode(' ', $disks),
            // Always set: there is no "every shared disk". Two lists rather than one because
            // the script reads each disk's state again, and what was agreed to for a disk —
            // format it, or only add a partition to it — must not depend on that reading.
            'CHOSEN_SHARED' => implode(' ', $sharedDisks),
            'EDGE_TUNING_CANDIDATES' => implode("\n", self::EDGE_TUNING_FLAGS),
            'HOST_PROXY_NETWORK' => self::HOST_PROXY_NETWORK,
            ...$this->traefikVars($tls, $this->cloudflare->proxies($node)),
        ];
    }

    /**
     * The reverse proxy in front of the edge, on every proxy node — proxy.sh skips it when
     * another container already holds port 80, which is a host with its own reverse proxy (the
     * development machine, say). Development terminates no TLS: the edge is plain HTTP behind a
     * hostname; production adds the ACME resolver the proxy's router labels name.
     *
     * Returned with a hash of everything it is run with. proxy.sh keeps a running
     * Traefik whose label carries the same hash — replacing it drops TLS on the host for a few
     * seconds — and replaces it otherwise. The image alone would not do: a development panel's
     * Traefik has no TLS and runs under the same name, and a flag change keeps the image tag.
     */
    private function traefikVars(bool $tls, bool $behindCloudflare = false): array
    {
        $command = '--providers.docker=true --providers.docker.exposedbydefault=false'
            .' --entrypoints.web.address=:80';

        if ($tls) {
            $command .= ' --entrypoints.websecure.address=:443'
                .' --entrypoints.websecure.http.tls.certresolver=le'
                .' --certificatesresolvers.le.acme.httpchallenge.entrypoint=web'
                .' --certificatesresolvers.le.acme.storage=/certs/acme.json';
        }

        // Traefik replaces any `X-Forwarded-For` it is handed with the address that connected,
        // unless told that address may be believed. Behind Cloudflare's proxy that address is
        // Cloudflare's, so the edge logged — and GeoIP placed — every viewer in a Cloudflare
        // data centre, whatever nginx trusts further in. Only for a node that is behind it:
        // the flag is part of the hash below, and adding it everywhere would restart Traefik,
        // and drop TLS for a few seconds, on every host of a fleet that never asked for this.
        if ($behindCloudflare) {
            $trusted = implode(',', CloudflareDns::proxyRanges());
            foreach ($tls ? ['web', 'websecure'] : ['web'] as $entrypoint) {
                $command .= " --entrypoints.{$entrypoint}.forwardedheaders.trustedips={$trusted}";
            }
        }

        // No dashboard and no :8080. `--api.insecure` served Traefik's API unauthenticated on a
        // port published to the internet — every router, hostname and backend of the node to
        // anyone who asked — and nothing in the deploy or the panel ever read it.
        $options = [
            'ports' => $tls ? ['80:80', '443:443'] : ['80:80'],
            // Traefik holds the viewers' sockets, so it is where the TCP tuning matters most.
            'raw' => ['$EDGE_TUNING'],
            'volumes' => [
                '/var/run/docker.sock:/var/run/docker.sock:ro',
                'traefik_certs:/certs',
            ],
            'command' => $command,
            'network' => 'nukevideo_default',
        ];
        $config = substr(md5($this->buildDockerRunArgs('nukevideo_traefik', self::TRAEFIK_IMAGE, $options)), 0, 12);

        return [
            // The node appends the checksum of the tuning it found the host accepts, which is
            // decided there and sits in the hashed arguments as a literal: a Traefik created
            // without BBR would otherwise be kept by every deploy after the kernel gained it.
            'TRAEFIK_RUN_ARGS' => $this->buildDockerRunArgs('nukevideo_traefik', self::TRAEFIK_IMAGE, [
                ...$options,
                'raw' => [...$options['raw'], '-l "nukevideo.config='.$config.'-$EDGE_TUNING_ID"'],
            ]),
            'TRAEFIK_CONFIG' => $config,
            'TRAEFIK_IMAGE' => self::TRAEFIK_IMAGE,
        ];
    }

    /**
     * Vector only earns its keep on an edge. It ships exactly one thing — the
     * `ip=/bytes=/video=` lines the vod nginx writes — into the bandwidth pipeline, and every other
     * container's logs are dropped by its own transform, so on a worker node it reads all of
     * Horizon's output to produce nothing. It was also the one deploy artifact with no node id in
     * its name, so every environment sharing a host fought over it.
     *
     * Deploying anywhere else therefore removes it (empty VECTOR_RUN_ARGS), including where a
     * previous deploy left one running.
     */
    private function vectorVars(Node $node): array
    {
        $name = Node::containerPrefix().'_vector';

        if ($node->type !== NodeType::PROXY) {
            return ['VECTOR_CONTAINER' => $name, 'VECTOR_RUN_ARGS' => ''];
        }

        $image = 'timberio/vector:0.56.0-alpine';

        $runArgs = $this->buildDockerRunArgs($name, $image, [
            // Only the two variables the config interpolates. Handing a third-party image the whole
            // node environment — database, S3 and webhook credentials — bought nothing. Filtered
            // out of the merged list rather than rebuilt, so the two stay exactly what the edge
            // itself was given (both are deploy-owned: no override can redirect the reports).
            'env' => array_merge(
                array_values(array_filter(
                    $this->getEnvironmentVariables($node),
                    fn ($v) => in_array(explode('=', $v, 2)[0], ['INTERNAL_API_URL', 'INTERNAL_API_SECRET'], true)
                )),
                // Which containers this Vector may read, by label. Passed rather than baked into
                // the config so one file serves both environments.
                ['VECTOR_SCOPE='.Node::containerPrefix()],
            ),
            'volumes' => [
                '/var/run/docker.sock:/var/run/docker.sock:ro',
                self::workdir($node).'/config/vector.yaml:/etc/vector/vector.yaml:ro',
            ],
        ]);

        return [
            'VECTOR_CONTAINER' => $name,
            'VECTOR_IMAGE' => $image,
            'VECTOR_RUN_ARGS' => $runArgs,
            'VECTOR_CONFIG' => file_get_contents(base_path('vector/vector.yaml')),
        ];
    }

    public function runValidation(Node $node): array
    {
        $checks = [
            ['key' => 'docker', 'label' => 'Docker'],
            ['key' => 'network', 'label' => 'Docker Network'],
            ['key' => 'containers', 'label' => 'Containers'],
            ['key' => 'disk', 'label' => 'Disk Space'],
        ];

        if ($node->accel) {
            $checks[] = ['key' => 'gpu', 'label' => 'GPU Encode'];
        }

        if ($node->type === NodeType::PROXY) {
            $checks[] = ['key' => 'cache', 'label' => 'Cache Pool'];
        }

        $results = [];

        foreach ($checks as $check) {
            try {
                $output = match ($check['key']) {
                    'docker' => $this->ssh($node, 'docker --version && docker info --format "Server: {{.ServerVersion}}"', 15),
                    'network' => $this->ssh($node, 'docker network inspect nukevideo_default --format "{{.Name}} ({{.Driver}})"', 15),
                    'containers' => $this->ssh($node, 'docker ps --filter '.escapeshellarg('name='.$this->containerFilter()).' --format "{{.Names}}\t{{.Status}}"', 15),
                    'disk' => $this->ssh($node, 'df -h / | tail -1', 15),
                    'gpu' => $this->ssh($node, $this->gpuProbeCommand($node), 120),
                    'cache' => $this->ssh($node, $this->cachePoolCommand(), 15),
                };

                $results[] = [
                    'key' => $check['key'],
                    'label' => $check['label'],
                    'status' => 'ok',
                    'output' => trim($output),
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'key' => $check['key'],
                    'label' => $check['label'],
                    'status' => 'error',
                    'output' => trim($e->getMessage()),
                ];
            }
        }

        return $results;
    }

    /**
     * What "this installation's containers" means to `docker ps`. The filter is a regex over a
     * substring, so a bare `nukevideo_` also reports the `nukevideo_dev_*` containers of a
     * development install sharing the host — and the development panel would report production's.
     * The alternation is what keeps the production filter from swallowing the dev prefix.
     */
    private function containerFilter(): string
    {
        return app()->isLocal()
            ? 'nukevideo_dev_'
            : 'nukevideo_(worker|proxy|storage|vector|traefik)';
    }

    /**
     * Usage of the cache pool and the state of the array beneath it. Pool-aware: a host that was
     * never given one (no device carrying the label, no array) is caching on a capped docker
     * volume by design, and says so. A pool that exists but is not mounted is the error — the
     * edge is then caching into whatever is at the path, the OS disk, which is exactly the
     * regression the check exists to surface.
     */
    private function cachePoolCommand(): string
    {
        $mount = ProxyCacheService::MOUNT;
        $md = ProxyCacheService::MD_DEVICE;
        $label = ProxyCacheService::FS_LABEL;

        return "if ! mountpoint -q {$mount}; then"
            ." if [ -e {$md} ] || blkid -L {$label} >/dev/null 2>&1; then echo 'Cache pool exists but is NOT mounted at {$mount} — the edge is caching on the OS disk'; exit 1; fi;"
            ." echo 'no cache pool provisioned, edge caches on a docker volume'; exit 0; fi;"
            ." df -h {$mount} | tail -1 | awk '{ print \$2 \" total, \" \$3 \" used (\" \$5 \"), \" \$4 \" free\" }';"
            ." [ -e {$md} ] && grep -A1 '^md' /proc/mdstat | head -2 || echo 'single disk, no array'";
    }

    /**
     * A real hardware encode of a synthetic second inside the worker image — proof the GPU,
     * its driver and the container flags all line up, not just that a device file exists.
     */
    private function gpuProbeCommand(Node $node): string
    {
        $image = $this->resolveImage('api');
        // --entrypoint ffmpeg: the probe has no DB/Redis env, so the image's entrypoint
        // (migrations/optimize) must not run — only the encoder matters here.
        $run = 'docker run --rm --entrypoint ffmpeg';
        $probe = '-hide_banner -v error -f lavfi -i testsrc2=duration=1:size=640x360:rate=30';

        return match ($node->accel) {
            NodeAccel::INTEL => "{$run} --device /dev/dri --group-add \"\$(getent group render | cut -d: -f3)\" "
                ."{$image} {$probe} -c:v h264_qsv -f null - && echo 'QSV hardware encode OK'",
            NodeAccel::NVIDIA => "{$run} --gpus all {$image} {$probe} -c:v h264_nvenc -f null -"
                ." && echo 'NVENC hardware encode OK'",
            default => throw new \RuntimeException('Node has no GPU to probe.'),
        };
    }

    /**
     * A node image built in development gets its own tag, never `:dev`. That one belongs to
     * compose — it builds it from the `api-dev` target, which is the runtime with no application
     * code in it, and the next `docker compose up --build` would rebuild it from under a node that
     * needs the opposite. This tag is built from the release targets by `bin/push-node-dev`, so
     * what a node runs in development is shaped exactly like production.
     */
    private const DEV_NODE_TAG = 'node-dev';

    /**
     * Pinned on APP_ENV rather than read from APP_VERSION: the version is a release concept and
     * means nothing to a working copy, and a development panel must not be able to put a released
     * production image on a node by accident.
     */
    private function resolveImage(string $type): string
    {
        $tag = app()->isLocal() ? self::DEV_NODE_TAG : config('app.version');

        return $this->registry()."/nukevideo-{$type}:{$tag}";
    }

    /**
     * The host part of every image name. Unset means Docker Hub, under the namespace the releases
     * are published in — so production says nothing and keeps pulling exactly what it always did.
     */
    private function registry(): string
    {
        return rtrim((string) config('nuke.registry'), '/') ?: 'chikenare';
    }

    private function ssh(Node $node, string $command, int $timeout = 30, ?string $input = null, ?\Closure $onOutput = null): string
    {
        return $this->ssh->run(
            ip: $node->ip_address,
            user: $node->user,
            privateKey: $this->sshKeys->privateKey(),
            command: $command,
            timeout: $timeout,
            input: $input,
            onOutput: $onOutput,
        );
    }

    /**
     * Every container's json-file log is capped. The edge writes one access-log line per segment
     * to stdout for Vector to read, and once its cache survives redeploys there is nothing that
     * ever truncates that file: a busy node wrote gigabytes onto the OS disk. Horizon and Traefik
     * are quieter, but nothing bounds them either, and one rule is easier to trust than three.
     */
    private const LOG_OPTS = '--log-opt max-size=100m --log-opt max-file=5';

    /**
     * The edge keeps far less. Every access-log line it writes carries the raw playback token
     * (Vector hashes it downstream, the file on disk does not), and every token in that file is
     * a replayable playback link until its `exp` — the provider's token window. Sixty megabytes is a
     * few hours of a busy node: enough for Vector to catch up after a restart, not enough to
     * hand whoever reads the OS disk a week of live links.
     */
    private const PROXY_LOG_OPTS = '--log-opt max-size=20m --log-opt max-file=3';

    /**
     * The network a reverse proxy of the host's own is expected on, when one holds port 80 in
     * place of ours — the external network the getting-started guide creates for the
     * development Traefik. Fixed rather than read off that proxy, so a deploy does not guess.
     */
    private const HOST_PROXY_NETWORK = 'traefik';

    /**
     * Per-container tuning for Traefik and the edge, the sockets that carry video. Candidates:
     * proxy.sh tries each one alone on the node and keeps those the host accepts, because a
     * single refused flag stops `docker run` and leaves the node without that container.
     *
     *  - slow_start_after_idle: a player fetches a 10 s segment and then waits, and Linux resets
     *    an idle connection to slow start, so every segment started over at the initial window.
     *  - notsent_lowat: keeps what waits unsent in the kernel small, so BBR paces it instead of
     *    it piling up. A new namespace starts it at "unlimited" rather than copying the host's.
     *  - BBR: CUBIC halves the window on a single loss, and a 10 s segment over a long or lossy
     *    path never climbs back before it ends. Needs the kernel module, hence the probe.
     *  - nofile: `worker_rlimit_nofile` can only raise the soft limit to the hard one the daemon
     *    hands out, which is not the same on every host.
     *
     * @var list<string>
     */
    private const EDGE_TUNING_FLAGS = [
        '--sysctl net.ipv4.tcp_slow_start_after_idle=0',
        '--sysctl net.ipv4.tcp_notsent_lowat=131072',
        '--sysctl net.ipv4.tcp_congestion_control=bbr',
        '--ulimit nofile=65535:65535',
    ];

    /**
     * How every container resolves names. glibc sends a lookup's A and AAAA queries at once from
     * one socket, and a home router's NAT dropped one of the pair often enough that 1 in 10-30
     * lookups stalled for glibc's default 5s before the retry — on the same hosts, their own
     * systemd-resolved never did, and every stall cost whatever the worker was reaching for.
     * Separate sockets per query, and a 1s wait per try: a lost packet costs a second, not five.
     * Harmless on a network that drops nothing.
     */
    private const DNS_OPTS = '--dns-opt single-request-reopen --dns-opt timeout:1 --dns-opt attempts:3';

    private function buildDockerRunArgs(string $name, string $image, array $options): string
    {
        $cmd = "--name {$name} --restart unless-stopped ".($options['log_opts'] ?? self::LOG_OPTS).' '.self::DNS_OPTS;

        foreach ($options['env'] ?? [] as $env) {
            $cmd .= ' -e '.escapeshellarg($env);
        }
        foreach ($options['volumes'] ?? [] as $volume) {
            $cmd .= ' -v '.escapeshellarg($volume);
        }
        foreach ($options['ports'] ?? [] as $port) {
            $cmd .= " -p {$port}";
        }
        foreach ($options['labels'] ?? [] as $label) {
            $cmd .= ' -l '.escapeshellarg($label);
        }
        foreach ($options['sysctls'] ?? [] as $sysctl) {
            $cmd .= ' --sysctl '.escapeshellarg($sysctl);
        }

        foreach ($options['devices'] ?? [] as $device) {
            $cmd .= ' --device '.escapeshellarg($device);
        }
        if (! empty($options['gpus'])) {
            $cmd .= ' --gpus all';
        }
        if (! empty($options['group_add'])) {
            // Raw on purpose: the value may be a shell expansion resolved on the node.
            $cmd .= " --group-add {$options['group_add']}";
        }

        if (! empty($options['stop_signal'])) {
            $cmd .= ' --stop-signal '.$options['stop_signal'];
        }

        if (! empty($options['stop_timeout'])) {
            $cmd .= ' --stop-timeout '.(int) $options['stop_timeout'];
        }

        if (! empty($options['cpuset'])) {
            $cmd .= ' --cpuset-cpus '.escapeshellarg($options['cpuset']);
        }
        if (! empty($options['memory'])) {
            $cmd .= ' --memory '.escapeshellarg($options['memory']);
        }

        if (isset($options['network'])) {
            $cmd .= ' --network '.escapeshellarg($options['network']);
        }

        if (isset($options['healthcheck'])) {
            $cmd .= ' --health-cmd '.escapeshellarg($options['healthcheck']);
        }

        // Raw on purpose, like group_add: these carry shell expansions resolved on the node.
        foreach ($options['raw'] ?? [] as $arg) {
            $cmd .= " {$arg}";
        }

        $cmd .= isset($options['command']) ? " {$image} {$options['command']}" : " {$image}";

        return $cmd;
    }
}
