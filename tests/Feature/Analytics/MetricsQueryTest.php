<?php

/**
 * The general read over `usage_daily`: any breakdown the allowlist permits, rather than a new endpoint
 * per question.
 *
 * A query endpoint over a shared table is only defensible because of what these cases pin. The
 * dimensions of `usage_daily` are not equally shareable, so there are two ways to be allowed to break
 * down by one that NAMES something — a video, a viewer, a viewer's address: either the query is
 * narrowed to one project, in which case those identifiers are the caller's own, or the caller
 * names exactly what it is asking about, which bounds the question to values it already had. The
 * customer-label column pins the query to the caller's account on top of that, and the two fleet
 * columns take only the first route, because there is no list of edges a tenant could name.
 *
 * The service is stubbed here — {@see AnalyticsServiceTest} runs the queries — so what is under
 * test is the authorization and the shaping, which is where this endpoint can go wrong.
 */

use App\Enums\MetricMeasure;
use App\Models\Project;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Services\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

const METRICS_ENDPOINT = '/api/metrics';

/** Stubs the service and hands back the arguments the query was built with. */
function stubMetricsQuery(array $rows = []): ArrayObject
{
    $seen = new ArrayObject;

    app()->instance(AnalyticsService::class, Mockery::mock(AnalyticsService::class, function (MockInterface $mock) use ($seen, $rows) {
        $mock->shouldReceive('query')->andReturnUsing(function (...$args) use ($seen, $rows) {
            $seen['args'] = $args;

            return $rows;
        });
    }));

    return $seen;
}

beforeEach(function () {
    $this->owner = User::factory()->create(['is_admin' => false]);
    $this->project = Project::factory()->for($this->owner)->create();

    Sanctum::actingAs($this->owner);
    $this->withHeader('X-Project-Ulid', $this->project->ulid);
});

it('breaks delivery down by the dimensions asked for', function () {
    $seen = stubMetricsQuery([
        ['date' => '2026-04-16', 'metric' => 'streaming_bytes', 'value' => 1024.0],
    ]);

    $this->postJson(METRICS_ENDPOINT, [
        'from' => '2026-04-01',
        'to' => '2026-04-30',
        'dimensions' => ['date', 'metric'],
        'trackingIds' => ['customer-42'],
    ])->assertOk()
        ->assertJsonPath('data.0.date', '2026-04-16')
        ->assertJsonPath('data.0.value', 1024);

    // from, to, dimensions, filters, metrics, accountId — delivery only, so no account pin.
    expect($seen['args'][3]['tracking_id'])->toBe(['customer-42'])
        ->and($seen['args'][5])->toBeNull();
});

it('pins the query to the caller account as soon as it touches customer labels', function () {
    // `external_user_id` IS the integrator's own customer identifier. Instance-wide it would hand
    // one tenant another's customers, which is the leak this whole allowlist exists to prevent.
    $seen = stubMetricsQuery();

    $this->postJson(METRICS_ENDPOINT, [
        'from' => '2026-04-01',
        'to' => '2026-04-30',
        'dimensions' => ['external_user_id'],
    ])->assertOk();

    expect($seen['args'][5])->toBe($this->owner->id);
});

it('pins the query to the account for metrics that are booked per account', function () {
    // Upload volume and encoding seconds are the account's, not a delivery's.
    $seen = stubMetricsQuery();

    $this->postJson(METRICS_ENDPOINT, [
        'from' => '2026-04-01',
        'to' => '2026-04-30',
        'dimensions' => ['date'],
        'metrics' => ['upload_bytes', 'encoding_cpu'],
    ])->assertOk();

    expect($seen['args'][5])->toBe($this->owner->id)
        ->and($seen['args'][4])->toBe(['upload_bytes', 'encoding_cpu']);
});

it('resolves a project key to its owning account, not to the project id', function () {
    $key = app(ApiTokenService::class)->regenerateProjectKey($this->project)->plainTextToken;
    auth()->forgetGuards();

    $seen = stubMetricsQuery();

    $this->withToken($key)->postJson(METRICS_ENDPOINT, [
        'from' => '2026-04-01',
        'to' => '2026-04-30',
        'dimensions' => ['external_user_id'],
    ])->assertOk();

    expect($seen['args'][5])->toBe($this->owner->id)
        ->and($seen['args'][5])->not->toBe($this->project->id);
});

it('scopes the query to the caller project rather than pre-filtering the list', function () {
    // The list goes to the query as given; `project_id` in the WHERE is what makes another
    // tenant's ULID match nothing. One clause instead of a second lookup and a narrowing that
    // could be got wrong.
    $mine = projectVideo($this->project);
    $theirs = projectVideo(Project::factory()->for(User::factory()->create())->create(), 'theirs');

    $seen = stubMetricsQuery();

    $this->postJson(METRICS_ENDPOINT, [
        'from' => '2026-04-01',
        'to' => '2026-04-30',
        'dimensions' => ['video'],
        'videos' => [$mine->ulid, $theirs->ulid],
    ])->assertOk();

    expect($seen['args'][3]['video_ulid'])->toBe([$mine->ulid, $theirs->ulid])
        ->and($seen['args'][6])->toBe($this->project->id);
});

it('lets project context stand in for naming what you ask about', function () {
    // Scoped to a project, the identifiers ARE the caller's, so no list is needed — which is the
    // whole reason `usage_daily` grew a project column.
    $seen = stubMetricsQuery();

    $this->postJson(METRICS_ENDPOINT, [
        'from' => '2026-04-01',
        'to' => '2026-04-30',
        'dimensions' => ['tracking_id', 'ip', 'video'],
    ])->assertOk();

    expect($seen['args'][6])->toBe($this->project->id);
});

it('refuses the identifier dimensions when neither scoped nor named', function (array $payload, string $field) {
    // No project header: nothing narrows the query, so the caller has to say what it is asking
    // about or it would enumerate other tenants'.
    $this->withHeader('X-Project-Ulid', '')
        ->postJson(METRICS_ENDPOINT, $payload + ['from' => '2026-04-01', 'to' => '2026-04-30'])
        ->assertStatus(422)
        ->assertJsonValidationErrors($field);
})->with([
    // Each of these, unbounded, enumerates something belonging to other tenants.
    'videos, which would list every title on the installation' => [['dimensions' => ['video']], 'videos'],
    'viewer addresses, which are personal data' => [['dimensions' => ['ip']], 'videos'],
    'viewer labels, which nothing can attach an owner to' => [['dimensions' => ['tracking_id']], 'trackingIds'],
    'the edges, which no list can stand in for' => [['dimensions' => ['node_id']], 'dimensions'],
]);

it('answers the fleet dimensions inside a project, where they describe its own traffic', function (string $dimension) {
    // Which edge served THIS project's bytes, and whether its cache had them, is the project's own
    // business. What is not on offer is the same question across the installation.
    $seen = stubMetricsQuery();

    $this->postJson(METRICS_ENDPOINT, [
        'from' => '2026-04-01',
        'to' => '2026-04-30',
        'dimensions' => [$dimension],
    ])->assertOk();

    expect($seen['args'][6])->toBe($this->project->id);
})->with(['node_id', 'cache']);

it('refuses the fleet dimensions with no project, and offers no list instead', function (string $dimension) {
    // A caller can name its own videos or its own tracking ids; it cannot name an edge it owns,
    // because it owns none. So for these two the project is the only way in.
    $this->withHeader('X-Project-Ulid', '')->postJson(METRICS_ENDPOINT, [
        'from' => '2026-04-01',
        'to' => '2026-04-30',
        'dimensions' => [$dimension],
        'videos' => ['01HZXW3V5N8Q9R2T4Y6B8D0F1G'],
        'trackingIds' => ['customer-42'],
    ])->assertStatus(422)->assertJsonValidationErrors('dimensions');
})->with(['node_id', 'cache']);

it('gives an operator no more than anyone else', function () {
    // There is no operator branch left in this controller. An administrator that names no project
    // gets the same refusal a tenant would; the fleet-wide view lives on the admin-only per-node
    // report, not here.
    Sanctum::actingAs(User::factory()->create(['is_admin' => true]));
    stubMetricsQuery();

    $this->withHeader('X-Project-Ulid', '')->postJson(METRICS_ENDPOINT, [
        'from' => '2026-04-01',
        'to' => '2026-04-30',
        'dimensions' => ['node_id'],
    ])->assertStatus(422);
});

it('answers an unscoped query that names its own videos', function () {
    // The other half of the rule: no project, but the caller named the titles, so the question is
    // bounded to things it can only have learned from its own account.
    $video = projectVideo($this->project);
    $seen = stubMetricsQuery();

    $this->withHeader('X-Project-Ulid', '')->postJson(METRICS_ENDPOINT, [
        'from' => '2026-04-01',
        'to' => '2026-04-30',
        'dimensions' => ['video'],
        'videos' => [$video->ulid],
    ])->assertOk();

    expect($seen['args'][6])->toBeNull();
});

it('pins an unscoped query that names videos or viewer labels to the caller\'s own account', function (array $payload) {
    // Naming a video proves nothing about owning it: its ULID is in every playback URL it was
    // ever given. Unpinned, another tenant's ULIDs broken down by `ip` answered with that
    // tenant's viewers' addresses. The same goes for tracking ids, which are free text two
    // tenants can both use.
    $seen = stubMetricsQuery();

    $this->withHeader('X-Project-Ulid', '')->postJson(METRICS_ENDPOINT, $payload + [
        'from' => '2026-04-01',
        'to' => '2026-04-30',
    ])->assertOk();

    expect($seen['args'][5])->toBe($this->owner->id)
        ->and($seen['args'][6])->toBeNull();
})->with([
    'a foreign video by address' => [['dimensions' => ['video', 'ip', 'country', 'date'], 'videos' => ['01HZXW3V5N8Q9R2T4Y6B8D0F1G']]],
    'a video filter under a plain total' => [['dimensions' => ['date'], 'videos' => ['01HZXW3V5N8Q9R2T4Y6B8D0F1G']]],
    'tracking ids' => [['dimensions' => ['tracking_id'], 'trackingIds' => ['42']]],
    'a tracking id filter under a plain total' => [['dimensions' => ['date', 'metric'], 'trackingIds' => ['42']]],
]);

it('leaves a project-scoped query to its project, which is already narrower than the account', function () {
    $seen = stubMetricsQuery();

    $this->postJson(METRICS_ENDPOINT, [
        'from' => '2026-04-01',
        'to' => '2026-04-30',
        'dimensions' => ['video', 'ip'],
        'videos' => ['01HZXW3V5N8Q9R2T4Y6B8D0F1G'],
    ])->assertOk();

    expect($seen['args'][5])->toBeNull()
        ->and($seen['args'][6])->toBe($this->project->id);
});

it('needs no project context for a query that names no project data', function () {
    stubMetricsQuery();

    $this->withHeader('X-Project-Ulid', '')->postJson(METRICS_ENDPOINT, [
        'from' => '2026-04-01',
        'to' => '2026-04-30',
        'dimensions' => ['date', 'metric'],
        'trackingIds' => ['customer-42'],
    ])->assertOk();
});

it('rejects a query it could not answer honestly', function (array $payload) {
    $this->postJson(METRICS_ENDPOINT, $payload + ['from' => '2026-04-01', 'to' => '2026-04-30'])->assertStatus(422);
})->with([
    'no dimensions' => [['dimensions' => []]],
    'a dimension that is not a column' => [['dimensions' => ['user_agent']]],
    'a metric that does not exist' => [['dimensions' => ['date'], 'metrics' => ['made_up']]],
    'a video that is not a ULID' => [['dimensions' => ['date'], 'videos' => ['not-a-ulid']]],
    'a tracking id outside the alphabet' => [['dimensions' => ['date'], 'trackingIds' => ['bad id']]],
    'a shape that is not one of the two' => [['dimensions' => ['date'], 'shape' => 'pivoted']],
]);

it('still refuses a query to an unauthenticated caller', function () {
    auth()->forgetGuards();

    $this->postJson(METRICS_ENDPOINT, [
        'from' => '2026-04-01',
        'to' => '2026-04-30',
        'dimensions' => ['date'],
    ])->assertUnauthorized();
});

it('hands the measures asked for to the query, as enum cases', function () {
    $seen = stubMetricsQuery();

    $this->postJson(METRICS_ENDPOINT, [
        'from' => '2026-04-01',
        'to' => '2026-04-30',
        'dimensions' => ['video'],
        'measures' => ['requests', 'unique_ips', 'unique_tracking_ids'],
    ])->assertOk();

    expect($seen['args'][7])->toBe([MetricMeasure::REQUESTS, MetricMeasure::UNIQUE_IPS, MetricMeasure::UNIQUE_TRACKING_IDS]);
});

it('asks for the sum alone when no measure is named', function () {
    $seen = stubMetricsQuery();

    $this->postJson(METRICS_ENDPOINT, ['from' => '2026-04-01', 'to' => '2026-04-30', 'dimensions' => ['date']])->assertOk();

    expect($seen['args'][7])->toBe([]);
});

it('refuses a measure it cannot answer', function (array $payload, string $field) {
    stubMetricsQuery();

    $this->postJson(METRICS_ENDPOINT, ['from' => '2026-04-01', 'to' => '2026-04-30', 'dimensions' => ['date'], ...$payload])
        ->assertStatus(422)
        ->assertJsonValidationErrors($field);
})->with([
    // The wide shape pivots the sum into a field per metric; a count there would be read as bytes.
    'a count in the wide shape' => [['shape' => 'wide', 'measures' => ['requests']], 'measures'],
    'a measure that does not exist' => [['measures' => ['median_bytes']], 'measures.0'],
]);

it('refuses a range no honest report spans', function (array $range) {
    // Two valid ISO dates and three and a half million days: a read with one row per day built
    // all of them in memory before finding the answer too large, for anyone holding a token.
    stubMetricsQuery();

    $this->postJson(METRICS_ENDPOINT, $range + ['dimensions' => ['date']])
        ->assertStatus(422)->assertJsonValidationErrors('to');
})->with([
    'all of the calendar' => [['from' => '0001-01-01', 'to' => '9999-12-31']],
    'eleven years' => [['from' => '2015-01-01', 'to' => '2026-01-01']],
    'backwards' => [['from' => '2026-04-30', 'to' => '2026-04-01']],
]);

it('answers a range of several years', function () {
    stubMetricsQuery();

    $this->postJson(METRICS_ENDPOINT, ['from' => '2020-01-01', 'to' => '2026-11-13', 'dimensions' => ['date']])->assertOk();
});

it('applies the same bound to the dashboard reads', function (string $path) {
    $this->getJson("/api/{$path}?from=0001-01-01&to=9999-12-31")->assertStatus(422)->assertJsonValidationErrors('to');
})->with(['analytics', 'analytics/summary', 'analytics/bandwidth', 'analytics/top-ips', 'usage']);
