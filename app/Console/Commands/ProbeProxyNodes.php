<?php

namespace App\Console\Commands;

use App\Models\Node;
use App\Services\Cdn\ProxyRing;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Keeps dead proxies out of new playback links.
 *
 * A proxy that stops answering is not stopped by this — its containers are the operator's to
 * manage, and nothing here can tell a crashed node from one the API host simply cannot reach.
 * It only counts silence: after {@see Node::HEALTH_FAILURE_THRESHOLD} consecutive failures the
 * resolver hands the node's videos to its ring neighbours, and one good probe puts it back.
 * Viewers mid-session on a node that dies are lost either way; this is for the next ones.
 *
 * Liveness is a 2xx/3xx carrying `X-NukeVideo-Edge` with the node's own id. The header is the
 * whole test: a Traefik with no router for the host answers 404, Cloudflare answers 403 or a
 * challenge page, a wrong DNS record answers somebody else's site — all of which have status
 * codes and none of which is the edge. An edge from before the header existed fails the probe
 * too — redeploy it. Should the API host lose the fleet altogether, the resolver's fallback
 * keeps linking to every active node regardless ({@see ProxyRing}).
 */
class ProbeProxyNodes extends Command
{
    protected $signature = 'nodes:probe';

    protected $description = 'Probe the self-hosted proxy nodes and keep the silent ones out of new playback links';

    public const HEADER = 'X-NukeVideo-Edge';

    /** Seconds a probe waits. A node that takes longer to answer a 204 is not one to send viewers to. */
    private const TIMEOUT = 5;

    /**
     * Minutes after a node's row was last written during which a failed probe is not counted.
     *
     * A freshly deployed production proxy answers with Traefik's self-signed certificate until
     * the ACME challenge completes, and DNS for a new or renamed hostname takes its own time to
     * settle: either is a TLS failure to the probe, and three of those in a row would take a
     * node out of rotation the moment it came up. `updated_at` is the right clock because only
     * the operator's and the deploy's writes move it — {@see Node::markHealthy()} and
     * {@see Node::markProbeFailed()} bypass Eloquent on purpose — so the grace starts on a
     * deploy or a hostname change and never renews itself. The probe still runs during it: a
     * good answer clears any failures at once.
     */
    private const GRACE_MINUTES = 10;

    public function handle(): int
    {
        $nodes = Node::proxy()->active()->whereNotNull('hostname')->get();

        if ($nodes->isEmpty()) {
            return self::SUCCESS;
        }

        $scheme = Node::proxyScheme();

        $responses = Http::pool(fn (Pool $pool) => $nodes->map(
            fn (Node $node) => $pool->as((string) $node->id)
                ->timeout(self::TIMEOUT)
                ->connectTimeout(self::TIMEOUT)
                ->withOptions(['allow_redirects' => false])
                ->get("{$scheme}{$node->hostname}/healthz"),
        )->all());

        foreach ($nodes as $node) {
            $response = $responses[(string) $node->id] ?? null;

            if ($this->isAlive($response, $node)) {
                $wasUnhealthy = ! $node->isHealthy();
                $node->markHealthy();
                $this->line("{$node->name}: ok".($wasUnhealthy ? ' (back in rotation)' : ''));

                continue;
            }

            $reason = $response instanceof Response ? "HTTP {$response->status()}" : 'unreachable';

            if ($node->updated_at?->gt(now()->subMinutes(self::GRACE_MINUTES))) {
                $this->line("{$node->name}: {$reason} (not counted: deployed or edited under ".self::GRACE_MINUTES.' minutes ago)');

                continue;
            }

            $node->markProbeFailed();
            $this->warn("{$node->name}: {$reason} ({$node->health_failures} consecutive)".($node->isHealthy() ? '' : ' — out of rotation'));
        }

        return self::SUCCESS;
    }

    /**
     * A failed connection arrives as an exception object in the pool result rather than as a
     * Response, which is what the instanceof check covers.
     */
    private function isAlive(mixed $response, Node $node): bool
    {
        return $response instanceof Response
            && $response->status() < 400
            && $response->header(self::HEADER) === (string) $node->id;
    }
}
