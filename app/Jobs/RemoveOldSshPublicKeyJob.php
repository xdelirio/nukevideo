<?php

namespace App\Jobs;

use App\Models\Node;
use App\Services\SshKeyService;
use App\Services\SSHService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * The tail of a key rotation ({@see SshKeyService::rotate}): drop the retired public line from
 * every node's authorized_keys. Runs after the panel has switched, with the new key, so it can
 * never cost access — the worst it leaves behind is a stale line on a node, which is why it is
 * queued rather than done in the request: the rotation already spent two SSH sessions per node
 * while the operator waited, and a third buys nothing they need to see.
 */
class RemoveOldSshPublicKeyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const QUEUE = 'default';

    /**
     * A single pass. A node that fails here either is unreachable — it missed the rotation too,
     * and its authorized_keys is being fixed by hand anyway — or rejected the command, which
     * retrying will not change. Every other node was handled in this same run.
     */
    public $tries = 1;

    public $backoff = 0;

    /** @param  string  $oldPublicKey  the retired public line, as it appears in authorized_keys */
    public function __construct(private readonly string $oldPublicKey) {}

    public static function dispatchFor(string $oldPublicKey): void
    {
        self::dispatch($oldPublicKey)->onQueue(self::QUEUE);
    }

    public function handle(SSHService $ssh, SshKeyService $keys): void
    {
        $privateKey = $keys->privateKey();

        foreach (Node::orderBy('id')->get() as $node) {
            try {
                $ssh->run($node->sshHost(), $node->user, $privateKey, SshKeyService::removeCommand($this->oldPublicKey), 30);
            } catch (\Throwable $e) {
                Log::warning("Old SSH public key left on node {$node->id}: {$e->getMessage()}");
            }
        }
    }
}
