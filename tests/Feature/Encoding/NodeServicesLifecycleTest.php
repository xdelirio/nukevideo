<?php

use App\Http\Controllers\Api\NodeController;
use App\Models\Node;
use App\Services\DockerService;
use App\Services\ProxyCacheService;
use App\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function lifecycleNode(array $attributes = []): Node
{
    // The panel's one key, as any SSH-backed call reads it ({@see \App\Services\SshKeyService}).
    AppSettings::fake(['ssh_private_key' => 'PRIVATE', 'ssh_public_key' => 'ssh-ed25519 AAAA nukevideo', 'ssh_fingerprint' => 'ff']);

    return Node::create([
        'ip_address' => '10.0.0.99',
        'user' => 'deploy',
        'name' => 'node-test',
        'type' => 'worker',
        'is_active' => true,
        ...$attributes,
    ]);
}

describe('deleting a node', function () {
    it('removes its own containers and its chunk store, but nothing else', function () {
        $node = lifecycleNode();
        $other = lifecycleNode(['name' => 'other']);

        $removed = [];
        $docker = Mockery::mock(DockerService::class);
        $docker->shouldReceive('listContainers')->andReturn([
            ['Names' => "/nukevideo_worker_{$node->id}"],
            ['Names' => "/nukevideo_storage_{$node->id}"],
            ['Names' => "/nukevideo_worker_{$other->id}"],
            ['Names' => '/nukevideo_dev_worker_1'],
            ['Names' => '/unrelated_container'],
        ]);
        $docker->shouldReceive('removeContainer')->andReturnUsing(function ($n, $name) use (&$removed) {
            $removed[] = $name;
        });
        app()->instance(DockerService::class, $docker);

        app(NodeController::class)->destroy((string) $node->id);

        expect($removed)->toBe(["nukevideo_worker_{$node->id}", "nukevideo_storage_{$node->id}"])
            ->and(Node::find($node->id))->toBeNull()
            ->and(Node::find($other->id))->not->toBeNull();
    });

    it('removes a proxy\'s fallback cache volume with it', function () {
        // The volume is the node's alone and nothing else reclaims it. A typo (`$docker` for
        // `$this->docker`) made this throw into a catch-all for as long as it had existed.
        $node = lifecycleNode(['type' => 'proxy', 'hostname' => 'edge.example.com']);

        $volumes = [];
        $docker = Mockery::mock(DockerService::class);
        $docker->shouldReceive('listContainers')->andReturn([]);
        $docker->shouldReceive('removeVolume')->once()->andReturnUsing(function ($n, $name) use (&$volumes) {
            $volumes[] = $name;
        });
        app()->instance(DockerService::class, $docker);

        app(NodeController::class)->destroy((string) $node->id);

        expect($volumes)->toBe([ProxyCacheService::volumeFor($node)])
            ->and(Node::find($node->id))->toBeNull();
    });

    it('does not take a container whose name it is merely a prefix of', function () {
        // `nukevideo_worker_1` is a prefix of `nukevideo_worker_11`. With a prefix match, deleting
        // node 1 killed node 11's worker whenever the two shared a host.
        $node = lifecycleNode();
        $name = $node->serviceContainerName();

        $removed = [];
        $docker = Mockery::mock(DockerService::class);
        $docker->shouldReceive('listContainers')->andReturn([
            ['Names' => "/{$name}1"],
            ['Names' => "/{$name}_extra"],
        ]);
        $docker->shouldReceive('removeContainer')->andReturnUsing(function ($n, $c) use (&$removed) {
            $removed[] = $c;
        });
        app()->instance(DockerService::class, $docker);

        app(NodeController::class)->destroy((string) $node->id);

        expect($removed)->toBe([]);
    });
});
