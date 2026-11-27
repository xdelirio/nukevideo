<?php

use App\Jobs\RunNodeOperationJob;
use App\Models\Node;
use App\Models\Project;
use App\Models\User;
use App\Settings\NodeSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Sanctum::actingAs(User::factory()->create(['is_admin' => true]));
});

function apiNode(array $attributes = []): Node
{
    return Node::create(['ip_address' => '10.0.0.9', 'user' => 'deploy', 'name' => 'n-'.uniqid(), 'type' => 'worker', 'is_active' => true, ...$attributes]);
}

it('queues an operation and answers at once', function (string $action) {
    $node = apiNode();

    $this->postJson("/api/nodes/{$node->id}/{$action}")->assertStatus(202)
        ->assertJsonPath('data.properties.action', $action)
        ->assertJsonPath('data.properties.status', 'queued');

    Bus::assertDispatched(RunNodeOperationJob::class);
})->with(['deploy', 'start', 'stop']);

it('refuses a node that is already busy', function () {
    $node = apiNode();
    $this->postJson("/api/nodes/{$node->id}/deploy")->assertStatus(202);

    $this->postJson("/api/nodes/{$node->id}/stop", ['force' => true])->assertStatus(409);
});

it('deploys a fleet, skipping busy nodes, and never formats a proxy disk', function () {
    $busy = apiNode();
    $this->postJson("/api/nodes/{$busy->id}/start")->assertStatus(202);
    $proxy = apiNode(['type' => 'proxy', 'hostname' => 'edge.example.com']);
    $worker = apiNode();

    $response = $this->postJson('/api/nodes/deploy', ['nodes' => [$busy->id, $proxy->id, $worker->id], 'force' => true])
        ->assertStatus(202)
        ->assertJsonPath('skipped', [$busy->id]);

    $ops = collect($response->json('data'));
    expect($ops)->toHaveCount(2)
        ->and($ops->pluck('properties.batch')->unique())->toHaveCount(1)
        ->and($ops->firstWhere('subjectId', $proxy->id)['properties']['disks'])->toBe([]);
});

it('serves the lines after a cursor', function () {
    $node = apiNode();
    $id = $this->postJson("/api/nodes/{$node->id}/deploy")->json('data.id');
    Redis::shouldReceive('lrange')->with("node-ops:{$id}", 2, -1)->andReturn(['c', 'd']);

    $this->getJson("/api/node-operations/{$id}/lines?after=2")
        ->assertOk()
        ->assertExactJson(['lines' => ['c', 'd'], 'next' => 4, 'status' => 'queued']);
});

/** Marks the operation finished, as the job does when it ends. */
function finishOperation(int $id, string $status): void
{
    $op = Activity::find($id);
    $op->properties = $op->properties->merge(['status' => $status]);
    $op->save();
}

it('streams a finished operation\'s output and closes with its status', function () {
    $node = apiNode();
    $id = $this->postJson("/api/nodes/{$node->id}/deploy")->json('data.id');
    finishOperation($id, 'succeeded');
    Redis::shouldReceive('lrange')->with("node-ops:{$id}", 0, -1)->andReturn(['a', 'b']);

    $response = $this->get("/api/node-operations/{$id}/stream")->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('text/event-stream')
        ->and($response->headers->get('X-Accel-Buffering'))->toBe('no')
        ->and($response->streamedContent())
        ->toContain("id: 2\nevent: lines\ndata: {\"lines\":[\"a\",\"b\"],\"status\":\"succeeded\"}\n\n")
        ->toContain("event: done\ndata: {\"status\":\"succeeded\"}\n\n");
});

it('resumes a stream from the browser\'s Last-Event-ID', function () {
    $node = apiNode();
    $id = $this->postJson("/api/nodes/{$node->id}/deploy")->json('data.id');
    finishOperation($id, 'failed');
    Redis::shouldReceive('lrange')->with("node-ops:{$id}", 5, -1)->andReturn(['f']);

    $content = $this->get("/api/node-operations/{$id}/stream", ['Last-Event-ID' => '5'])->streamedContent();

    expect($content)->toContain('id: 6')->toContain('"lines":["f"]');
});

it('only streams node operations, and only to admins', function () {
    $node = apiNode();
    $id = $this->postJson("/api/nodes/{$node->id}/deploy")->json('data.id');
    $other = activity('default')->log('not a node operation');

    $this->get("/api/node-operations/{$other->id}/stream")->assertNotFound();

    Sanctum::actingAs(User::factory()->create(['is_admin' => false]));
    $this->get("/api/node-operations/{$id}/stream")->assertForbidden();
});

it('lists operations filtered by node', function () {
    $a = apiNode();
    $b = apiNode();
    $this->postJson("/api/nodes/{$a->id}/start");
    $this->postJson("/api/nodes/{$b->id}/start");

    $this->getJson("/api/node-operations?node={$a->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.subjectId', $a->id);
});

it('no longer lets the edit form flip is_active', function () {
    $node = apiNode();
    $this->putJson("/api/nodes/{$node->id}", ['isActive' => false])->assertOk();

    expect($node->fresh()->is_active)->toBeTrue();
});

describe('the activity log', function () {
    it('shows node operations to an admin', function () {
        $user = User::factory()->create(['is_admin' => true]);
        $project = Project::factory()->for($user)->create();
        Sanctum::actingAs($user);
        $node = apiNode();
        $this->postJson("/api/nodes/{$node->id}/stop")->assertStatus(202);

        $this->withHeader('X-Project-Ulid', $project->ulid)->getJson('/api/activity-log')
            ->assertOk()
            ->assertJsonPath('data.0.event', 'node_stop')
            ->assertJsonPath('data.0.subjectId', $node->id);
    });

    it('never shows them to anyone else', function () {
        // Nodes are the operator's, not a tenant's: their names, addresses and deploy output
        // are nothing a project owner should see.
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));
        $this->postJson('/api/nodes/'.apiNode()->id.'/stop')->assertStatus(202);

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->withHeader('X-Project-Ulid', $project->ulid)->getJson('/api/activity-log')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });
});

it('leaves stopped nodes out of a fleet deploy', function () {
    // A node stopped for maintenance must not come back into rotation because someone updated
    // the fleet — and a stopped proxy whose host is down would fail and cancel every proxy after it.
    $stopped = apiNode(['is_active' => false]);
    $active = apiNode();

    $response = $this->postJson('/api/nodes/deploy', ['nodes' => [$stopped->id, $active->id]])
        ->assertStatus(202)
        ->assertJsonPath('skipped', [$stopped->id]);

    expect($response->json('data'))->toHaveCount(1);
});

it('will not delete a node in the middle of an operation', function () {
    // The deploy would recreate the container after the delete removed it: a worker draining the
    // queues under a node id that no longer exists, invisible from the panel.
    $node = apiNode();
    $this->postJson("/api/nodes/{$node->id}/deploy")->assertStatus(202);

    $this->deleteJson("/api/nodes/{$node->id}")->assertStatus(409);
    expect(Node::find($node->id))->not->toBeNull();
});

it('keeps the lock token to itself', function () {
    $node = apiNode();

    $this->postJson("/api/nodes/{$node->id}/start")->assertStatus(202)
        ->assertJsonMissingPath('data.properties.lock');
});

it('still shows an admin only their own project\'s videos', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $mine = Project::factory()->for($admin)->create();
    $theirs = Project::factory()->for(User::factory())->create();
    $video = projectVideo($theirs);
    activity('video')->performedOn($video)->log('theirs');
    Sanctum::actingAs($admin);

    $this->withHeader('X-Project-Ulid', $mine->ulid)->getJson('/api/activity-log')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

describe('a deploy with no chunk store', function () {
    beforeEach(fn () => NodeSettings::fake(['environment' => '', 'chunk_store_address' => '']));

    it('is refused before it is queued, for a worker', function () {
        // Queued, it would only fail later in the operation's log; the request can say why now.
        $worker = apiNode();
        NodeSettings::fake(['environment' => '', 'chunk_store_address' => '']);

        $this->postJson("/api/nodes/{$worker->id}/deploy")
            ->assertStatus(422)
            ->assertJsonValidationErrors('node');

        Bus::assertNotDispatched(RunNodeOperationJob::class);
    });

    it('is refused for a fleet deploy that includes a worker', function () {
        $worker = apiNode();
        NodeSettings::fake(['environment' => '', 'chunk_store_address' => '']);

        $this->postJson('/api/nodes/deploy', ['nodes' => [$worker->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('nodes');
    });

    it('does not concern a proxy, which uses no chunk store', function () {
        $proxy = apiNode(['type' => 'proxy', 'hostname' => 'edge.example.com']);

        $this->postJson("/api/nodes/{$proxy->id}/deploy", ['disks' => []])->assertStatus(202);
    });
});
