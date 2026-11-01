<?php

/**
 * Analytics is readable by ANY authenticated token, a project API key included. It is the read side
 * of the CDN access logs, and reading those back is precisely what an integrating backend holds a
 * project key for — gating it behind an admin account meant the numbers were stored
 * and nobody downstream could ever see them.
 *
 * What that accepts is stated plainly rather than assumed: the figures span the whole instance, so
 * a project key reads totals that include other projects' traffic. NukeVideo sits behind other
 * backends, server to server, so that aggregate is not the boundary being defended here. The
 * boundaries that ARE still hold, and the cases below pin them: no unauthenticated access, and none
 * of the admin surfaces moved along with it.
 */

use App\Models\Project;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Services\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

/**
 * These cases are about who gets through the door, so the service behind it is stubbed empty.
 */
function stubAnalyticsService(): void
{
    app()->instance(AnalyticsService::class, Mockery::mock(AnalyticsService::class, function (MockInterface $mock) {
        $mock->shouldReceive('summary')->andReturn(['total_bytes' => 0, 'unique_videos' => 0, 'unique_ips' => 0, 'unique_tracking_ids' => 0]);
        $mock->shouldReceive('encodingUsage')->andReturn(['cpu' => 0]);
        $mock->shouldReceive('usageSummary')->andReturn(['upload_bytes' => 0, 'encoding_cpu' => 0]);
        $mock->shouldReceive('bandwidthOverTime', 'topIps', 'topVideos', 'topExternalUsers', 'bandwidthByTrackingId', 'bandwidthByVideo', 'encodingUsageOverTime')->andReturn([]);
        $mock->shouldReceive('edgeDelivery')->andReturn([
            ['node_id' => 3, 'delivered_bytes' => 1000.0, 'origin_bytes' => 100.0, 'hit_ratio' => 0.9],
        ]);
    }));
}

// The whole dashboard, each of its panels, and the queue: one door, the same for all of them.
dataset('analytics reads', [
    'the dashboard' => '/api/analytics?from=2026-01-01&to=2026-01-31',
    'the summary panel' => '/api/analytics/summary?from=2026-01-01&to=2026-01-31',
    'the bandwidth panel' => '/api/analytics/bandwidth?from=2026-01-01&to=2026-01-31',
    'the top addresses panel' => '/api/analytics/top-ips?from=2026-01-01&to=2026-01-31',
    'the top videos panel' => '/api/analytics/top-videos?from=2026-01-01&to=2026-01-31',
    'the top tracking ids panel' => '/api/analytics/top-tracking-ids?from=2026-01-01&to=2026-01-31',
    'the top customers panel' => '/api/analytics/top-external-users?from=2026-01-01&to=2026-01-31',
    'the queue' => '/api/analytics/queue',
]);

it('lets a non-admin user read analytics', function (string $endpoint) {
    stubAnalyticsService();
    Sanctum::actingAs(User::factory()->create(['is_admin' => false]));

    $this->getJson($endpoint)->assertOk();
})->with('analytics reads');

it('lets a project API key read analytics', function (string $endpoint) {
    stubAnalyticsService();

    // A project key authenticates AS the project, so `$request->user()` is a Project and not a
    // User. Neither endpoint touches it — the day one does, it has to move back behind
    // `no-project-key` or read the project explicitly.
    $project = Project::factory()->for(User::factory()->create(['is_admin' => false]))->create();
    $key = app(ApiTokenService::class)->regenerateProjectKey($project)->plainTextToken;

    $this->withToken($key)->getJson($endpoint)->assertOk();
})->with('analytics reads');

it('still refuses analytics to an unauthenticated caller', function (string $endpoint) {
    $this->getJson($endpoint)->assertUnauthorized();
})->with('analytics reads');

it('did not open the admin surfaces alongside it', function (string $endpoint) {
    // Opening the metrics is a decision about metrics. Everything that operates the instance —
    // nodes, users, keys, CDN settings — is a separate question and was not part of it.
    $project = Project::factory()->for(User::factory()->create(['is_admin' => true]))->create();
    $key = app(ApiTokenService::class)->regenerateProjectKey($project)->plainTextToken;

    $this->withToken($key)->getJson($endpoint)->assertForbidden();

    Sanctum::actingAs(User::factory()->create(['is_admin' => false]));
    $this->getJson($endpoint)->assertForbidden();
})->with([
    'nodes' => '/api/nodes',
    // Names the operator's edges and their origin egress: infrastructure, not metrics.
    'per-node delivery' => '/api/analytics/edges?from=2026-01-01&to=2026-01-31',
    'users' => '/api/users',
    'CDN settings' => '/api/cdn-settings',
    'the node environment' => '/api/node-environment',
]);

it('reports per-node delivery to an admin', function () {
    stubAnalyticsService();
    Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

    $this->getJson('/api/analytics/edges?from=2026-01-01&to=2026-01-31')
        ->assertOk()
        ->assertJsonPath('data.0.nodeId', 3)
        ->assertJsonPath('data.0.hitRatio', 0.9)
        ->assertJsonPath('data.0.originBytes', 100);
});
