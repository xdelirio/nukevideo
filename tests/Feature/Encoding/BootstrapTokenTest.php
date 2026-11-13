<?php

/**
 * The bootstrap script carries the instance's whole credential set in cleartext and is delivered
 * as a `curl … | bash` one-liner, so its URL predictably survives in shell history and access logs.
 * Single use is what makes reading it there worthless.
 */

use App\Models\Node;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

    // The first worker claims the chunk store, without which the deploy script refuses to build.
    $this->node = Node::create([
        'name' => 'worker-01',
        'ip_address' => '10.0.0.20',
        'type' => 'worker',
    ]);
});

function bootstrapUrl(Node $node): string
{
    $command = test()->postJson("/api/nodes/{$node->id}/bootstrap-token")
        ->assertOk()
        ->json('command');

    expect($command)->toStartWith('curl -fsSL "');

    return str($command)->after('curl -fsSL "')->before('" | bash')->toString();
}

it('serves the script once and refuses every replay', function () {
    $url = bootstrapUrl($this->node);

    $this->get($url)
        ->assertOk()
        ->assertHeader('content-type', 'text/x-sh; charset=UTF-8')
        ->assertHeader('cache-control', 'max-age=0, no-store, private');

    // Same URL, still inside the signature's window — the nonce is what stops it.
    $this->get($url)->assertStatus(410);
    $this->get($url)->assertStatus(410);
});

it('gives each generated command its own nonce', function () {
    $first = bootstrapUrl($this->node);
    $second = bootstrapUrl($this->node);

    expect($first)->not->toBe($second);

    // Regenerating must not invalidate a command the admin already pasted, and spending one
    // must not spend the other.
    $this->get($second)->assertOk();
    $this->get($first)->assertOk();
});

it('refuses a signed url carrying no nonce at all', function () {
    $url = URL::temporarySignedRoute('nodes.bootstrap', now()->addMinutes(15), ['node' => $this->node->id]);

    $this->get($url)->assertStatus(410);
});

it('still rejects an unsigned or tampered url before the nonce is even considered', function () {
    $this->get("/api/nodes/{$this->node->id}/bootstrap?nonce=made-up")->assertForbidden();
});

it('offers bootstrap commands for worker nodes only', function () {
    $proxy = Node::create(['name' => 'edge-01', 'ip_address' => '10.0.0.30', 'type' => 'proxy']);

    $this->postJson("/api/nodes/{$proxy->id}/bootstrap-token")->assertStatus(422);
});

it('serves a bootstrap script for a worker only', function () {
    // A proxy's script built here would carry no disk list, which the deploy reads as "format
    // every spare disk". Nothing mints such a URL, and a hand-signed one gets nothing either.
    $proxy = Node::create(['name' => 'edge-01', 'ip_address' => '10.0.0.30', 'hostname' => 'edge.example.com', 'type' => 'proxy']);

    $url = URL::temporarySignedRoute('nodes.bootstrap', now()->addMinutes(5), ['node' => $proxy->id, 'nonce' => 'abc']);

    $this->get($url)->assertNotFound();
});
