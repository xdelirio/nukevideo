<?php

declare(strict_types=1);

namespace App\Services\Cdn;

use App\Data\VideoData;
use App\Models\Node;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Which proxy serves a given video, by consistent hashing over the routable fleet.
 *
 * Every node shares the token secret, so any of them can serve any video; what the ring buys is
 * that a video's segments are cached on one node rather than on all of them.
 *
 * The answer is resolved once per request rather than once per call. The ring is derived state —
 * the routable set queried, then {@see REPLICAS} points hashed per node and sorted — and it used
 * to be rebuilt on every lookup. That is invisible on a playback link, which asks once, and
 * expensive on a listing: {@see VideoData::fromModel} resolves a thumbnail AND a storyboard URL
 * per row through {@see AssetUrlResolver}, so a page of 25 videos paid the query and the sort
 * fifty times over for an answer that cannot change inside one request.
 *
 * The memo is per instance, and the instance is bound `scoped` ({@see AppServiceProvider}) — so
 * the answer lives exactly as long as the request or the job that asked for it, and is never
 * cached across them. That matters: the set it reads is what `nodes:probe` rewrites
 * every minute, and a node leaving the fleet has to reach the next link rather than the next TTL.
 * The table is a handful of rows behind one small query, so the single query a request now makes
 * is not worth trading for staleness.
 */
class ProxyRing
{
    /**
     * Points per node on the ring. High enough that the arc lengths even out — with a handful of
     * nodes, one point each would hand most of the catalogue to whichever node hashed highest.
     */
    private const REPLICAS = 150;

    /** The routable fleet, resolved once. Empty is a real answer: no node can serve anything. */
    private ?Collection $nodes = null;

    /**
     * point => node, ascending. Built only when there is more than one node to choose between.
     *
     * @var array<int, Node>|null
     */
    private ?array $ring = null;

    public function for(string $videoUlid): ?Node
    {
        $nodes = $this->nodes();

        if ($nodes->isEmpty()) {
            return null;
        }

        // One node owns everything; hashing 150 points to reach that conclusion is waste.
        if ($nodes->count() === 1) {
            return $nodes->first();
        }

        $ring = $this->ring($nodes);
        $hash = hexdec(substr(md5($videoUlid), 0, 8));

        foreach ($ring as $point => $node) {
            if ($hash <= $point) {
                return $node;
            }
        }

        // Past the last point, so the ring wraps to the first.
        return $ring[array_key_first($ring)];
    }

    /**
     * Falls back to every active proxy when none is routable: that is the probe being wrong for
     * the whole fleet (the API host losing its own network, say), and a link to a node that may
     * be down beats no link at all. Draining stays honoured either way — it is the operator's
     * word, the probe's is only a guess.
     *
     * The warning is now one line per request instead of one per call, which is the point of
     * saying it at all: a listing used to file fifty copies of it.
     */
    private function nodes(): Collection
    {
        if ($this->nodes !== null) {
            return $this->nodes;
        }

        $nodes = Node::routable()->orderBy('id')->get();

        if ($nodes->isEmpty()) {
            $nodes = Node::routable(requireHealthy: false)->orderBy('id')->get();

            if ($nodes->isNotEmpty()) {
                Log::warning('No routable proxy node; falling back to every active one', ['count' => $nodes->count()]);
            }
        }

        return $this->nodes = $nodes;
    }

    /**
     * @param  Collection<int, Node>  $nodes
     * @return array<int, Node>
     */
    private function ring(Collection $nodes): array
    {
        if ($this->ring !== null) {
            return $this->ring;
        }

        $ring = [];

        foreach ($nodes as $node) {
            for ($i = 0; $i < self::REPLICAS; $i++) {
                $point = hexdec(substr(md5("{$node->id}:{$i}"), 0, 8));
                $ring[$point] = $node;
            }
        }

        ksort($ring);

        return $this->ring = $ring;
    }
}
