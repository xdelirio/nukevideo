<?php

namespace App\Models;

use App\Enums\NodeAccel;
use App\Enums\NodeType;
use App\Observers\NodeObserver;
use App\Services\NodeService;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ObservedBy(NodeObserver::class)]
class Node extends Model
{
    protected $fillable = [
        'name',
        'user',
        'ip_address',
        'type',
        'accel',
        'hostname',
        'cloudflare_proxied',
        'is_active',
        'is_draining',
        // Written by the probe and the deploy, never by the API: the Data objects do not expose them.
        'health_failures',
        'last_healthy_at',
        'log',
        'env',
    ];

    protected function casts()
    {
        return [
            'type' => NodeType::class,
            'accel' => NodeAccel::class,
            // Nullable on purpose: null is "as the installation says" (CloudflareSettings).
            'cloudflare_proxied' => 'boolean',
            'is_active' => 'boolean',
            'is_draining' => 'boolean',
            'last_healthy_at' => 'datetime',
        ];
    }

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }

    /**
     * The docker namespace this installation owns. Development and production are separate
     * databases with separate id sequences, so both call their first worker `nukevideo_worker_1` —
     * and a machine is routinely both a production node and the development host at once. Without
     * the split, deploying the dev node would `docker rm -f` the *running production worker* and
     * hand the fleet's chunk volume to the dev store. The workdir has always been keyed by uuid;
     * this is the rest of what a deploy names.
     */
    public static function containerPrefix(): string
    {
        return app()->isLocal() ? 'nukevideo_dev' : 'nukevideo';
    }

    /**
     * The scheme every URL to a proxy node is built with: the link the API mints, the URL the
     * health probe fetches, the `VOD_BASE_URL` a node is deployed with and the Traefik router in
     * front of the edge ({@see NodeService}) all have to agree on this one place.
     * It follows APP_URL rather than the environment, so a development stack served over HTTPS
     * works exactly like production, and a plain-HTTP one keeps working. An APP_URL with no
     * scheme is taken as HTTPS, the safe side for links handed to the public.
     */
    public static function proxyScheme(): string
    {
        return (parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https').'://';
    }

    /**
     * The container that makes this node do its job — the Horizon worker on a worker node, Traefik's
     * companion on a proxy. Built from the type and the id only, so it can never be confused with
     * the containers that serve the whole fleet from this host: the storage container is the chunk
     * store every other node reads and writes through, and Vector ships the edge logs. Stopping the
     * node's own service takes it out of rotation; stopping the others takes the cluster down.
     */
    public function serviceContainerName(): string
    {
        return self::containerPrefix()."_{$this->type->value}_{$this->id}";
    }

    /**
     * The chunk store this node runs, when the fleet's chunk store address is its own. Named apart from the
     * service container on purpose, and deliberately absent from {@see deployedContainerNames()}:
     * it is the one container on a node that the *whole fleet* depends on — every other node's
     * `chunks` disk reads and writes through it. Taking a node out of rotation must not take it
     * down; deleting the node does, and then the chunk store needs another address.
     */
    public function storageContainerName(): string
    {
        return self::containerPrefix()."_storage_{$this->id}";
    }

    /**
     * Every container this node's deploy raises on its host and that serves this node alone.
     *
     * Listed unconditionally, whether or not the current settings would still create each one: a
     * `docker stop` on a name that is not there is a no-op, so this never has to reconstruct which
     * CDN provider or which environment the deploy ran under. That matters most for the ones a
     * settings change can orphan — Vector stops being deployed the moment the CDN switches to
     * Bunny, and a node deactivated afterwards would otherwise leave it running forever.
     */
    public function deployedContainerNames(): array
    {
        $names = [$this->serviceContainerName()];

        // Traefik and Vector are deliberately absent, even though a proxy deploy raises them. They
        // serve every proxy on the host, not the one being acted on: Traefik owns ports 80 and 443
        // so a second instance could not exist anyway, and Vector reads the Docker socket rather
        // than any single container. Listing them here meant deactivating one proxy tore down TLS
        // and bandwidth accounting for its neighbours — and left them off after a reboot, since the
        // stop outlives it.
        //
        // The cost is that removing the last proxy from a host leaves both running idle, which is
        // the cheaper mistake.
        return $names;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeProxy($query)
    {
        return $query->where('type', 'proxy');
    }

    public function scopeWorker($query)
    {
        return $query->where('type', 'worker');
    }

    /**
     * Consecutive failed probes before a proxy stops receiving new playback links. The probe
     * runs every minute, so this is three minutes of silence — long enough that a single
     * slow answer or a restart does not move a node's whole catalogue, cold, onto its
     * neighbours, short enough that a dead node stops being handed viewers.
     */
    public const HEALTH_FAILURE_THRESHOLD = 3;

    public function scopeHealthy($query)
    {
        return $query->where('health_failures', '<', self::HEALTH_FAILURE_THRESHOLD);
    }

    /**
     * A proxy the resolver may put in a playback URL: active, answering probes, not being
     * drained, and with a hostname to put there at all — a proxy without one was being
     * chosen and rendered as `https:///...`.
     *
     * `$requireHealthy = false` drops only the probe's verdict, for the resolver's fallback
     * (`ProxyRing`): draining is the operator's word and always holds.
     */
    public function scopeRoutable($query, bool $requireHealthy = true)
    {
        $query->proxy()->active()->where('is_draining', false)->whereNotNull('hostname');

        return $requireHealthy ? $query->healthy() : $query;
    }

    public function isHealthy(): bool
    {
        return $this->health_failures < self::HEALTH_FAILURE_THRESHOLD;
    }

    /**
     * Plain query-builder writes on purpose (`toBase()`): the probe updates many nodes a
     * minute. Bypassing Eloquent also leaves `updated_at` alone, which the probe relies on: it
     * reads that column as "when the operator or the deploy last wrote this node", and a
     * failed probe stamping it would renew its own grace period forever.
     */
    public function markHealthy(): void
    {
        static::whereKey($this->id)->toBase()->update(['health_failures' => 0, 'last_healthy_at' => now()]);
        $this->health_failures = 0;
        $this->last_healthy_at = now();
    }

    public function markProbeFailed(): void
    {
        // Capped so a node that is down for a week is not a node that needs a week of good
        // probes — one success is what clears it.
        $failures = min($this->health_failures + 1, 255);
        static::whereKey($this->id)->toBase()->update(['health_failures' => $failures]);
        $this->health_failures = $failures;
    }
}
