<?php

/**
 * The dashboard a panel at a time ({@see AnalyticsController::summary()} and its siblings), which
 * is how the admin page loads it: every panel in parallel, each drawn as it lands.
 *
 * What these pin is that a panel is exactly its piece of `/api/analytics` — the full payload stays
 * for integrators, and the two answering differently would leave the page and the API disagreeing
 * about the same traffic.
 */

use App\Http\Controllers\Api\AnalyticsController;
use App\Models\Project;
use App\Models\User;
use App\Services\UsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $user = User::factory()->create(['is_admin' => false]);
    $this->project = Project::factory()->for($user)->create();
    Sanctum::actingAs($user);

    $row = ['date' => '2026-04-10', 'project_id' => $this->project->id, 'user_id' => $user->id, 'metric' => 'streaming_bytes', 'node_id' => 1];
    UsageService::add([
        $row + ['video_ulid' => '01HZXW3V5N8Q9R2T4Y6B8D0FAA', 'ip' => '1.1.1.1', 'tracking_id' => 'alice', 'value' => 100],
        $row + ['video_ulid' => '01HZXW3V5N8Q9R2T4Y6B8D0FBB', 'ip' => '2.2.2.2', 'tracking_id' => 'bob', 'value' => 40],
        $row + ['date' => '2026-04-11', 'video_ulid' => '01HZXW3V5N8Q9R2T4Y6B8D0FAA', 'ip' => '2.2.2.2', 'tracking_id' => '', 'value' => 7],
        ['date' => '2026-04-10', 'project_id' => $this->project->id, 'user_id' => $user->id, 'metric' => 'upload_bytes', 'external_user_id' => 'customer-1', 'value' => 4096],
    ]);
});

it('answers each panel with exactly its piece of the whole dashboard', function (string $panel, string $piece, string $query) {
    $whole = $this->withHeader('X-Project-Ulid', $this->project->ulid)
        ->getJson("/api/analytics?from=2026-04-01&to=2026-04-30{$query}")
        ->assertOk()
        ->json("data.{$piece}");

    $this->withHeader('X-Project-Ulid', $this->project->ulid)
        ->getJson("/api/analytics/{$panel}?from=2026-04-01&to=2026-04-30{$query}")
        ->assertOk()
        ->assertExactJson(['data' => $whole]);

    // Something to compare: an empty piece would make the equality above prove nothing.
    expect($whole)->not->toBeEmpty();
})->with([
    'summary' => ['summary', 'cards', ''],
    'bandwidth' => ['bandwidth', 'bandwidthOverTime', ''],
    'top addresses' => ['top-ips', 'topIps', ''],
    'top videos' => ['top-videos', 'topVideos', ''],
    'top tracking ids' => ['top-tracking-ids', 'topTrackingIds', ''],
    'top customers' => ['top-external-users', 'topExternalUsers', ''],
    // The query reaches the panels the same way it reaches the whole payload.
    'a limit' => ['top-ips', 'topIps', '&limit=1'],
    'unattributed traffic only' => ['summary', 'cards', '&trackingId='],
    'one video' => ['bandwidth', 'bandwidthOverTime', '&video=01HZXW3V5N8Q9R2T4Y6B8D0FAA'],
    // Empty input arrives as null: the defaults, not a type error.
    'empty limits' => ['top-videos', 'topVideos', '&limit=&videoSeriesLimit='],
]);

it('withholds the panels that name things when no project was named', function (string $panel) {
    $this->getJson("/api/analytics/{$panel}?from=2026-04-01&to=2026-04-30")
        ->assertOk()
        ->assertExactJson(['data' => []]);
})->with(['top-ips', 'top-videos', 'top-tracking-ids', 'top-external-users']);

it('validates a panel query like the whole dashboard', function (string $query) {
    $this->getJson("/api/analytics/summary?from=2026-04-01&to=2026-04-30&{$query}")->assertStatus(422);
})->with([
    'a video that is not a ULID' => 'video=not-a-ulid',
    'a metric that is not a delivery metric' => 'metric=encoding_cpu',
    'a limit past the cap' => 'limit=100000',
]);
