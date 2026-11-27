<?php

/**
 * The panel has one SSH key, defined by its private half — it is what every connection to a
 * node authenticates with. The public half and the fingerprint are derived from it on the
 * server, so what the operator copies into a node's `authorized_keys` is always the pair the
 * panel presents. Rotation swaps the fleet to a new key in the only order that cannot lock the
 * panel out: install the new public key with the old private one, prove the new one logs in,
 * then switch.
 *
 * Payloads are camelCase, the spelling the panel sends.
 */

use App\Models\Node;
use App\Models\User;
use App\Services\SshKeyService;
use App\Services\SSHService;
use App\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use phpseclib4\Crypt\EC;

uses(RefreshDatabase::class);

function rotationNode(string $name, bool $active = true): Node
{
    return Node::create(['name' => $name, 'ip_address' => '10.0.0.9', 'user' => 'root', 'type' => 'worker', 'is_active' => $active]);
}

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create(['is_admin' => true]));
});

it('starts with no key, and says so instead of connecting with nothing', function () {
    $this->getJson('/api/app-settings')->assertOk()
        ->assertJsonPath('data.sshPublicKey', null)
        ->assertJsonPath('data.sshFingerprint', null);

    expect(fn () => app(SshKeyService::class)->privateKey())
        ->toThrow(RuntimeException::class, 'No SSH key is configured');
});

it('generates an Ed25519 pair when no private key is given', function () {
    $data = $this->putJson('/api/app-settings/ssh-key')->assertOk()->json('data');

    expect($data['sshPublicKey'])->toStartWith('ssh-ed25519 ')
        ->and($data['sshPublicKey'])->toEndWith(' nukevideo')
        ->and($data['sshFingerprint'])->toMatch('/^([0-9a-f]{2}:){15}[0-9a-f]{2}$/')
        ->and($data)->not->toHaveKey('sshPrivateKey');

    // Stored encrypted, and the public half really is the stored private key's.
    $settings = app(AppSettings::class);
    expect(SshKeyService::publicKeyOf($settings->ssh_private_key))->toBe($data['sshPublicKey']);
});

it('derives the public key from an imported private key', function () {
    $private = EC::createKey('Ed25519');
    $expected = $private->getPublicKey()->toString('OpenSSH', ['comment' => 'nukevideo']);

    $data = $this->putJson('/api/app-settings/ssh-key', ['privateKey' => $private->toString('OpenSSH')])
        ->assertOk()->json('data');

    expect($data['sshPublicKey'])->toBe($expected);
});

it('rejects a private key it cannot load, and a public key posing as one', function () {
    $this->putJson('/api/app-settings/ssh-key', ['privateKey' => 'not a key'])
        ->assertStatus(422)->assertJsonValidationErrors(['privateKey']);

    $public = EC::createKey('Ed25519')->getPublicKey()->toString('OpenSSH');

    $this->putJson('/api/app-settings/ssh-key', ['privateKey' => $public])
        ->assertStatus(422)->assertJsonValidationErrors(['privateKey']);
});

describe('rotation', function () {
    beforeEach(function () {
        $this->old = EC::createKey('Ed25519')->toString('OpenSSH');
        AppSettings::fake([
            'ssh_private_key' => $this->old,
            'ssh_public_key' => SshKeyService::publicKeyOf($this->old),
            'ssh_fingerprint' => 'ff',
        ]);
    });

    it('installs the new public key with the old key, verifies the new one, then switches', function () {
        rotationNode('a');
        $calls = [];

        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('run')->andReturnUsing(function (string $ip, ?string $user, string $privateKey, string $command) use (&$calls) {
            $calls[] = [$privateKey, $command];

            return '';
        });
        app()->instance(SSHService::class, $ssh);

        $data = $this->postJson('/api/app-settings/ssh-key/rotate')->assertOk()->json('data');
        $new = app(AppSettings::class)->ssh_private_key;
        $newPublic = SshKeyService::publicKeyOf($new);
        $oldPublic = SshKeyService::publicKeyOf($this->old);

        expect($data['rotated'])->toBeTrue()
            ->and($data['nodes'])->toBe([['id' => 1, 'name' => 'a', 'ok' => true, 'error' => null]])
            ->and($new)->not->toBe($this->old)
            // 1. install with OLD key, 2. log in with NEW key, 3. remove old line with NEW key
            // (queued, but the test queue is sync).
            ->and($calls[0][0])->toBe($this->old)
            ->and($calls[0][1])->toContain('authorized_keys')->toContain($newPublic)
            ->and($calls[1][0])->toBe($new)
            ->and($calls[1][1])->toBe('true')
            ->and($calls[2][0])->toBe($new)
            ->and($calls[2][1])->toContain('grep -vxF')->toContain($oldPublic);
    });

    it('switches nothing when an active node refuses the new key', function () {
        rotationNode('a');
        rotationNode('b');

        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('run')->andReturnUsing(function (string $ip, ?string $user, string $privateKey, string $command) {
            if ($ip === '10.0.0.9' && str_contains($command, 'authorized_keys') && Node::where('name', 'b')->exists() && $privateKey !== '') {
                // Second node's install fails; the first succeeded.
                static $n = 0;
                if (++$n === 2) {
                    throw new RuntimeException('Connection refused');
                }
            }

            return '';
        });
        app()->instance(SSHService::class, $ssh);

        $data = $this->postJson('/api/app-settings/ssh-key/rotate')->assertOk()->json('data');

        // Switching would have locked the panel out of `b` for good; the old key stays, and the
        // per-node result says which node to fix.
        expect($data['rotated'])->toBeFalse()
            ->and(collect($data['nodes'])->pluck('ok', 'name')->all())->toBe(['a' => true, 'b' => false])
            ->and(collect($data['nodes'])->firstWhere('name', 'b')['error'])->toBe('Connection refused')
            ->and(app(AppSettings::class)->ssh_private_key)->toBe($this->old);
    });

    it('does not let an inactive node hold the fleet on the old key', function () {
        rotationNode('gone', active: false);

        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('run')->andThrow(new RuntimeException('No route to host'));
        app()->instance(SSHService::class, $ssh);

        $data = $this->postJson('/api/app-settings/ssh-key/rotate')->assertOk()->json('data');

        expect($data['rotated'])->toBeTrue()
            ->and($data['nodes'][0]['ok'])->toBeFalse()
            ->and($data['nodes'][0]['error'])->toContain('No route to host')->toContain('by hand')
            ->and(app(AppSettings::class)->ssh_private_key)->not->toBe($this->old);
    });
});
