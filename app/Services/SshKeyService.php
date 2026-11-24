<?php

namespace App\Services;

use App\Data\AppSettings\NodeRotationData;
use App\Data\AppSettings\SshKeyRotationData;
use App\Jobs\RemoveOldSshPublicKeyJob;
use App\Models\Node;
use App\Settings\AppSettings;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\PublicKeyLoader;

/**
 * The panel's one SSH key ({@see AppSettings}): what every connection to a node authenticates
 * with, and the only thing an operator has to install on a node for the panel to manage it.
 */
class SshKeyService
{
    /** The comment on the public key line, so a node's authorized_keys says whose line it is. */
    private const COMMENT = 'nukevideo';

    public function __construct(
        private AppSettings $settings,
        private SSHService $ssh,
    ) {}

    /**
     * The private key every SSH call uses. Absent means the installation was never set up for
     * nodes; saying so beats an SSH error about an empty identity file.
     */
    public function privateKey(): string
    {
        if ($this->settings->ssh_private_key === '') {
            throw new \RuntimeException('No SSH key is configured. Generate or import one under Settings → App before managing nodes.');
        }

        return $this->settings->ssh_private_key;
    }

    /**
     * Replace the key: with the private key given, or a freshly generated Ed25519 pair. The
     * public half and the fingerprint are always derived from the private key, so what the panel
     * shows — and what the operator pastes into a node's authorized_keys — is the pair the panel
     * will actually present. Nothing is done to the nodes: this is for the first key, or when the
     * new public half already reached them by other means; {@see rotate} for a live fleet.
     */
    public function set(?string $privateKey): AppSettings
    {
        if ($privateKey === null || $privateKey === '') {
            // Ed25519: small, fast, accepted by every OpenSSH of the last decade.
            $privateKey = EC::createKey('Ed25519')->toString('OpenSSH');
        }

        $publicKey = self::publicKeyOf($privateKey);

        $this->settings->ssh_private_key = $privateKey;
        $this->settings->ssh_public_key = $publicKey;
        $this->settings->ssh_fingerprint = self::fingerprintOf($publicKey);
        $this->settings->save();

        return $this->settings;
    }

    /**
     * Swap the fleet to a new key without losing access to it on the way.
     *
     * Order is what matters: the new public key is installed on every node with the CURRENT key,
     * the new private key is proven to log in, and only then does the panel switch — a swap done
     * the other way round locks the panel out of every node it has not reached yet, with no key
     * left to reach them with. If any active node refuses, nothing is switched and the per-node
     * result says which; an inactive node is tried but cannot block, since it may be gone for
     * good and the fleet cannot stay on a leaked key for its sake. If it does come back, the new
     * public line has to be put in its authorized_keys by hand — the per-node result says so.
     *
     * Two SSH sessions per node is the floor — the install has to use the old key and the proof
     * the new one — and the operator waits through all of them, so nothing else is added to the
     * request: the old public line is removed by a queued job once the panel has switched. That
     * step can only ever leave a stale line behind, never cost access, so nothing waits on it.
     */
    public function rotate(): SshKeyRotationData
    {
        $oldPrivate = $this->privateKey();
        $oldPublic = $this->settings->ssh_public_key;
        $newPrivate = EC::createKey('Ed25519')->toString('OpenSSH');
        $newPublic = self::publicKeyOf($newPrivate);

        $results = [];
        $blocked = false;

        foreach (Node::orderBy('id')->get() as $node) {
            try {
                $this->ssh->run($node->sshHost(), $node->user, $oldPrivate, self::installCommand($newPublic), timeout: 30);
                $this->ssh->run($node->sshHost(), $node->user, $newPrivate, 'true', timeout: 15);
                $results[] = new NodeRotationData($node->id, $node->name, true, null);
            } catch (\Throwable $e) {
                $error = $node->is_active
                    ? $e->getMessage()
                    : "{$e->getMessage()} — inactive, so it did not block; install the new public key on it by hand before reactivating it.";
                $results[] = new NodeRotationData($node->id, $node->name, false, $error);
                $blocked = $blocked || $node->is_active;
            }
        }

        if ($blocked) {
            return new SshKeyRotationData(false, $results);
        }

        $this->set($newPrivate);

        RemoveOldSshPublicKeyJob::dispatchFor($oldPublic);

        return new SshKeyRotationData(true, $results);
    }

    /** Append the line once, creating the file with the permissions sshd insists on. */
    public static function installCommand(string $publicKey): string
    {
        $line = escapeshellarg($publicKey);

        return 'mkdir -p ~/.ssh && chmod 700 ~/.ssh && touch ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys'
            ." && (grep -qxF {$line} ~/.ssh/authorized_keys || echo {$line} >> ~/.ssh/authorized_keys)";
    }

    public static function removeCommand(string $publicKey): string
    {
        $line = escapeshellarg($publicKey);

        // A rewrite through a temp file rather than `sed -i`, which is not portable across the
        // BSD and GNU flavours a node may run.
        return "grep -vxF {$line} ~/.ssh/authorized_keys > ~/.ssh/authorized_keys.new && mv ~/.ssh/authorized_keys.new ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys";
    }

    public static function publicKeyOf(string $privateKey): string
    {
        return PublicKeyLoader::load($privateKey)->getPublicKey()->toString('OpenSSH', ['comment' => self::COMMENT]);
    }

    public static function fingerprintOf(string $publicKey): string
    {
        $parts = explode(' ', trim($publicKey));
        $keyData = base64_decode($parts[1] ?? $parts[0]);

        return implode(':', str_split(md5($keyData), 2));
    }
}
