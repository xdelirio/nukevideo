<?php

/**
 * The bandwidth series can be narrowed to one video and/or one download tracking id, which is how
 * an external integrator reads back the tracking id it minted its links with
 * ({@see DownloadStreamData}). Both values are matched against
 * columns written from CDN access logs, so their shape is validated here and bound — never
 * interpolated — in the service.
 *
 * The service is stubbed: what these cases pin is the plumbing from request to query, not the
 * query itself ({@see AnalyticsServiceTest}).
 */

use App\Data\Stream\DownloadStreamData;
use App\Models\Project;
use App\Models\User;
use App\Services\AnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

const VIDEO_ULID = '01HZXW3V5N8Q9R2T4Y6B8D0F1G';

// Every query that reads delivery metrics out of `usage_daily`. A series that quietly dropped the
// filter would put one
// customer's chart next to everyone else's totals.
const BANDWIDTH_QUERIES = ['summary', 'bandwidthOverTime', 'topIps', 'topVideos', 'bandwidthByTrackingId', 'bandwidthByVideo'];

beforeEach(function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    Sanctum::actingAs($user);

    // With no project named, the four breakdowns that name things are withheld and never reach the
    // service at all — so a case about what the filters do to them has to name one, which is also
    // how the panel calls this.
    $this->withHeader('X-Project-Ulid', $project->ulid);
});

/**
 * Stubs the service and hands back a collector that fills with `method => [video, tracking_id, metric]` as
 * the controller calls it. An ArrayObject, not an array: a returned array is a copy, so the stub would
 * be writing to one the caller never sees.
 */
function recordAnalyticsFilters(): ArrayObject
{
    $seen = new ArrayObject;

    // `app()->instance`, not the bare `mock()` helper: Mockery ships a global function of that
    // name that builds a mock and never binds it, so the controller kept resolving the real
    // service and the recorded calls stayed empty.
    $mock = Mockery::mock(AnalyticsService::class, function (MockInterface $mock) use ($seen) {
        foreach (BANDWIDTH_QUERIES as $method) {
            $mock->shouldReceive($method)->once()->andReturnUsing(function (...$args) use ($seen, $method) {
                // The three filters sit immediately before the project scope, which is last in
                // every one of these signatures. Mockery binds named arguments back to their
                // positions, so this stays positional however the controller spells the call.
                $seen[$method] = array_slice($args, -4, 3);

                return $method === 'summary'
                    ? ['total_bytes' => 0, 'unique_videos' => 0, 'unique_ips' => 0, 'unique_tracking_ids' => 0]
                    : [];
            });
        }

        $mock->shouldReceive('encodingUsage')->andReturn(['cpu' => 0]);
        $mock->shouldReceive('usageSummary')->andReturn(['upload_bytes' => 0, 'encoding_cpu' => 0]);
        $mock->shouldReceive('topExternalUsers')->andReturn([]);
        $mock->shouldReceive('encodingUsageOverTime')->andReturn([]);
    });

    app()->instance(AnalyticsService::class, $mock);

    return $seen;
}

it('passes the video and tracking id filters into every bandwidth query', function () {
    $seen = recordAnalyticsFilters();

    $this->getJson('/api/analytics?from=2026-01-01&to=2026-01-31&video='.VIDEO_ULID.'&trackingId=customer-42&metric=download_bytes')
        ->assertOk()
        ->assertJsonPath('data.topTrackingIds', []);

    // Every bandwidth query has to carry all three; a series that quietly ignored one would put
    // one customer's chart next to the instance's totals.
    expect($seen->getArrayCopy())->toHaveCount(count(BANDWIDTH_QUERIES))->each->toBe([VIDEO_ULID, 'customer-42', 'download_bytes']);
});

it('treats an absent trackingId as no filter at all', function () {
    $seen = recordAnalyticsFilters();

    $this->getJson('/api/analytics?from=2026-01-01&to=2026-01-31')->assertOk();

    expect($seen->getArrayCopy())->toHaveCount(count(BANDWIDTH_QUERIES))->each->toBe([null, null, null]);
});

it('treats an empty trackingId as a filter for unattributed traffic', function () {
    // `?trackingId=` is a real question — "what was never attributed to a customer" — and the empty
    // string is exactly what those rows carry. Flattening it into "no filter" would answer a
    // different one, with everyone's bytes in it.
    $seen = recordAnalyticsFilters();

    $this->getJson('/api/analytics?from=2026-01-01&to=2026-01-31&trackingId=')->assertOk();

    expect($seen->getArrayCopy())->toHaveCount(count(BANDWIDTH_QUERIES))->each->toBe([null, '', null]);
});

it('refuses a video or tracking id filter with no project to read it inside', function (string $path, string $filter, string $field) {
    // Without a project the totals are instance-wide and name nobody. With a filter they become
    // one video's bytes, viewer count and daily curve — and a video's ULID is public, so any
    // account could read another's.
    $this->withHeader('X-Project-Ulid', '')
        ->getJson("/api/{$path}?from=2026-01-01&to=2026-01-31&{$filter}")
        ->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    ['analytics', 'video='.VIDEO_ULID, 'video'],
    ['analytics/summary', 'video='.VIDEO_ULID, 'video'],
    ['analytics/bandwidth', 'video='.VIDEO_ULID, 'video'],
    ['analytics/summary', 'trackingId=customer-42', 'trackingId'],
    ['analytics/bandwidth', 'trackingId=', 'trackingId'],
]);

it('still answers the unfiltered totals with no project', function (string $path) {
    $this->withHeader('X-Project-Ulid', '')->getJson("/api/{$path}?from=2026-01-01&to=2026-01-31")->assertOk();
})->with(['analytics', 'analytics/summary', 'analytics/bandwidth']);

it('rejects a filter that could not have come out of the log columns', function (string $query) {
    $this->getJson("/api/analytics?from=2026-01-01&to=2026-01-31&{$query}")->assertStatus(422);
})->with([
    'a video that is not a ULID' => 'video=not-a-ulid',
    'a ULID with the letters Crockford excludes' => 'video=01HZXW3V5N8Q9R2T4Y6B8D0FIL',
    'a tracking id outside the URL-safe alphabet' => 'trackingId=customer%2042',
    'a tracking id past the column width' => 'trackingId=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    'a metric that is not a delivery metric' => 'metric=encoding_cpu',
    'a metric that does not exist' => 'metric=made_up',
    // The name the endpoint reads: a rule on `video_series_limit` checked a key nobody sends, and
    // let any series length through to the query.
    'a video series longer than the cap' => 'videoSeriesLimit=500',
    'a video series of nothing' => 'videoSeriesLimit=0',
]);
