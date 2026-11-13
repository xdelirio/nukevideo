<?php

/**
 * The batch bandwidth read: delivered bytes for a list of tracking ids the caller names, split by
 * delivery metric. It exists so an integrator can bill a per-subscriber bandwidth quota without
 * one HTTP call and one full `AnalyticsData` per subscriber per period.
 *
 * Two things are pinned here, in two halves.
 *
 * The endpoint half stubs the service and covers who gets through the door, what the request may
 * contain and what comes back.
 *
 * The query half runs the service against the table, for the two properties that would fail
 * silently and expensively if they regressed — that an id is matched as a value whatever
 * characters it carries, and that the metric constraint is still there, without which the sum
 * would add encoding seconds and upload volume to delivered bytes and invoice the result.
 */

use App\Data\Analytics\TrackingIdBytesQueryData;
use App\Models\Project;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Services\ApiTokenService;
use App\Services\UsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

const BATCH_ENDPOINT = '/api/analytics/tracking-ids';

const BATCH_ROWS = [
    ['tracking_id' => 'customer-42', 'metric' => 'streaming_bytes', 'bytes' => 943718400.0],
    ['tracking_id' => 'customer-42', 'metric' => 'download_bytes', 'bytes' => 52428800.0],
    ['tracking_id' => 'reupload-9f1c', 'metric' => 'download_bytes', 'bytes' => 7.0],
];

/**
 * Stubs the service and hands back the arguments it was called with, so a case can assert that the
 * request reached the query intact. An ArrayObject, not an array: a returned array is a copy, and
 * the stub would be writing to one the caller never sees.
 */
function stubBatchService(array $rows = BATCH_ROWS): ArrayObject
{
    $seen = new ArrayObject;

    // `app()->instance`, not the bare `mock()` helper: Mockery ships a global function of that name
    // that builds a mock and never binds it, so the controller would keep resolving the real
    // service.
    app()->instance(AnalyticsService::class, Mockery::mock(AnalyticsService::class, function (MockInterface $mock) use ($seen, $rows) {
        $mock->shouldReceive('bytesByTrackingIds')->andReturnUsing(function (...$args) use ($seen, $rows) {
            $seen['args'] = $args;

            return $rows;
        });
    }));

    return $seen;
}

it('reports bytes for a batch of tracking ids, split by metric', function () {
    stubBatchService();
    Sanctum::actingAs(User::factory()->create(['is_admin' => false]));

    $this->getJson(BATCH_ENDPOINT.'?from=2026-04-01&to=2026-04-30&trackingIds[]=customer-42&trackingIds[]=reupload-9f1c')
        ->assertOk()
        // Two rows for one id, because streaming and the downloads that reupload to a viewer's own
        // file host are not the same line on an invoice. A caller that wants one number adds them.
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.trackingId', 'customer-42')
        ->assertJsonPath('data.0.metric', 'streaming_bytes')
        ->assertJsonPath('data.0.bytes', 943718400)
        ->assertJsonPath('data.2.trackingId', 'reupload-9f1c');
});

it('passes the range, the whole list and the metric into the query', function () {
    $seen = stubBatchService();
    Sanctum::actingAs(User::factory()->create(['is_admin' => false]));

    $this->getJson(BATCH_ENDPOINT.'?from=2026-04-01&to=2026-04-30&trackingIds[]=a&trackingIds[]=b&metric=download_bytes')
        ->assertOk();

    expect(array_slice($seen['args'], 0, 5))->toBe(['2026-04-01', '2026-04-30', ['a', 'b'], 'download_bytes', false]);
});

it('omits ids with no traffic rather than answering with zeros', function () {
    // The service returns nothing for an id that moved no bytes in the range, and the endpoint
    // passes that through: absence is the answer. A caller reading a total for an id it does not
    // find in the response is looking at zero.
    stubBatchService([['tracking_id' => 'customer-42', 'metric' => 'streaming_bytes', 'bytes' => 12.0]]);
    Sanctum::actingAs(User::factory()->create(['is_admin' => false]));

    $this->getJson(BATCH_ENDPOINT.'?from=2026-04-01&to=2026-04-30&trackingIds[]=customer-42&trackingIds[]=quiet-one')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonMissing(['trackingId' => 'quiet-one']);
});

it('accepts the same batch in a POST body', function () {
    // The reason the route takes POST at all: a thousand 64-character ids is roughly 80 KB of
    // query string, past what most proxies will accept in a request line. It still only reads.
    $seen = stubBatchService();
    Sanctum::actingAs(User::factory()->create(['is_admin' => false]));

    $this->postJson(BATCH_ENDPOINT, [
        'from' => '2026-04-01',
        'to' => '2026-04-30',
        'trackingIds' => ['a', 'b'],
    ])->assertOk();

    expect(array_slice($seen['args'], 0, 5))->toBe(['2026-04-01', '2026-04-30', ['a', 'b'], null, false]);
});

it('lets a project API key read a batch', function () {
    // The whole point: this is consumed server to server by the backend that minted the ids, which
    // holds a project key and not a user session.
    stubBatchService();
    $project = Project::factory()->for(User::factory()->create(['is_admin' => false]))->create();
    $key = app(ApiTokenService::class)->regenerateProjectKey($project)->plainTextToken;

    $this->withToken($key)
        ->getJson(BATCH_ENDPOINT.'?from=2026-04-01&to=2026-04-30&trackingIds[]=customer-42')
        ->assertOk();
});

it('reads a batch inside the caller\'s own account', function () {
    // A tracking id is the integrator's own text, usually its subscriber id, so two tenants both
    // have a `42`. Instance-wide, each read the other's bytes under it and billed them as its own.
    $owner = User::factory()->create(['is_admin' => false]);

    $seen = stubBatchService();
    Sanctum::actingAs($owner);
    $this->getJson(BATCH_ENDPOINT.'?from=2026-04-01&to=2026-04-30&trackingIds[]=42')->assertOk();

    // The project argument stays empty, the account one carries the caller.
    expect($seen['args'][5])->toBeNull()->and($seen['args'][6])->toBe($owner->id);
});

it('reads a project key\'s batch inside the account that owns the project', function () {
    // A project key authenticates as a Project, whose own id is from another sequence than a
    // user's: the account is the project's owner.
    User::factory()->count(3)->create();
    $owner = User::factory()->create(['is_admin' => false]);
    $project = Project::factory()->for($owner)->create();
    $key = app(ApiTokenService::class)->regenerateProjectKey($project)->plainTextToken;

    $seen = stubBatchService();
    $this->withToken($key)->getJson(BATCH_ENDPOINT.'?from=2026-04-01&to=2026-04-30&trackingIds[]=42')->assertOk();

    expect($seen['args'][6])->toBe($owner->id)->not->toBe($project->id);
});

it('still refuses a batch to an unauthenticated caller', function () {
    $this->getJson(BATCH_ENDPOINT.'?from=2026-04-01&to=2026-04-30&trackingIds[]=customer-42')
        ->assertUnauthorized();
});

it('rejects a batch it could not answer honestly', function (string|array $query) {
    Sanctum::actingAs(User::factory()->create(['is_admin' => false]));

    is_array($query)
        ? $this->postJson(BATCH_ENDPOINT, $query)->assertStatus(422)
        : $this->getJson(BATCH_ENDPOINT."?from=2026-04-01&to=2026-04-30&{$query}")->assertStatus(422);
})->with([
    'no list at all' => 'metric=download_bytes',
    'an empty list' => 'trackingIds=',
    'an id outside the URL-safe alphabet' => 'trackingIds[]=customer%2042',
    'an id with a dot, which the ingest never kept' => 'trackingIds[]=customer.42',
    'an id past the column width' => 'trackingIds[]='.str_repeat('a', 65),
    // Neither reading of an absent id survives a batch: null would reach the Array(String) binding
    // as `\N`, and '' is the bucket for traffic that carried no id, which nobody named here.
    'a null element' => [['from' => '2026-04-01', 'to' => '2026-04-30', 'trackingIds' => ['ok', null]]],
    'an empty element' => [['from' => '2026-04-01', 'to' => '2026-04-30', 'trackingIds' => ['ok', '']]],
    'more ids than one call may name' => [[
        'from' => '2026-04-01',
        'to' => '2026-04-30',
        'trackingIds' => array_fill(0, TrackingIdBytesQueryData::MAX_BATCH + 1, 'a'),
    ]],
    // The same table stores upload volume and encoding seconds in the same `value` column.
    'a metric that is not a delivery metric' => 'trackingIds[]=a&metric=encoding_cpu',
    'a metric that does not exist' => 'trackingIds[]=a&metric=made_up',
    'a granularity that is not one of the two' => 'trackingIds[]=a&granularity=hourly',
    'a range with no start' => [['to' => '2026-04-30', 'trackingIds' => ['a']]],
    'a start that is not a date' => 'trackingIds[]=a&from=last-tuesday',
]);

it('adds the day to the breakdown when asked for a daily read', function () {
    $seen = stubBatchService([['tracking_id' => 'customer-42', 'metric' => 'streaming_bytes', 'bytes' => 12.0, 'date' => '2026-04-16']]);
    Sanctum::actingAs(User::factory()->create(['is_admin' => false]));

    $this->getJson(BATCH_ENDPOINT.'?from=2026-04-01&to=2026-04-30&trackingIds[]=customer-42&granularity=daily')
        ->assertOk()
        ->assertJsonPath('data.0.date', '2026-04-16');

    expect($seen['args'][4])->toBeTrue();
});

it('leaves the date null on a total read', function () {
    // The property exists either way, so a consumer's type does not change shape with the query;
    // null is what says "this row is the whole range".
    stubBatchService([['tracking_id' => 'customer-42', 'metric' => 'streaming_bytes', 'bytes' => 12.0]]);
    Sanctum::actingAs(User::factory()->create(['is_admin' => false]));

    $this->getJson(BATCH_ENDPOINT.'?from=2026-04-01&to=2026-04-30&trackingIds[]=customer-42')
        ->assertOk()
        ->assertJsonPath('data.0.date', null);
});

it('asks for the unattributed bucket only when the flag says so', function () {
    // The empty id is the column default — traffic whose id did not survive the CDN log. It is
    // rejected as a caller-named id, so the flag is the only way in, and it is how a caller
    // reconciles the sum of its own ids against the total.
    $seen = stubBatchService();
    Sanctum::actingAs(User::factory()->create(['is_admin' => false]));

    $this->getJson(BATCH_ENDPOINT.'?from=2026-04-01&to=2026-04-30&trackingIds[]=a&includeUnattributed=1')->assertOk();
    expect($seen['args'][2])->toBe(['a', '']);

    $this->getJson(BATCH_ENDPOINT.'?from=2026-04-01&to=2026-04-30&trackingIds[]=a')->assertOk();
    expect($seen['args'][2])->toBe(['a']);
});

/** Rows for the query half, which runs against the real table. */
function usageRows(array ...$rows): void
{
    UsageService::add(array_map(fn (array $row) => $row + [
        'date' => '2026-04-10', 'metric' => 'streaming_bytes', 'ip' => '1.2.3.4', 'value' => 100,
    ], $rows));
}

it('matches the ids as values, whatever they contain', function () {
    usageRows(['tracking_id' => "o'brien"], ['tracking_id' => 'customer-42', 'value' => 50], ['tracking_id' => 'someone-else']);

    // These ids are the integrator's own labels, but they arrive over HTTP like anything else: a
    // quote in one is a character to match, never the end of a string.
    $rows = app(AnalyticsService::class)->bytesByTrackingIds('2026-04-01', '2026-04-30', ["o'brien", 'customer-42']);

    expect(array_column($rows, 'bytes', 'tracking_id'))->toEqual(['customer-42' => 50, "o'brien" => 100]);
});

it('constrains the batch to the delivery metrics and splits it by them', function () {
    usageRows(
        ['tracking_id' => 'customer-42'],
        ['tracking_id' => 'customer-42', 'metric' => 'download_bytes', 'value' => 30],
        // Without the constraint the sum would add encoding seconds and upload volume to delivered
        // bytes and return a number that means nothing — one that would then be invoiced.
        ['tracking_id' => 'customer-42', 'metric' => 'encoding_cpu', 'value' => 9],
        ['tracking_id' => 'customer-42', 'metric' => 'upload_bytes', 'value' => 7],
    );

    $rows = app(AnalyticsService::class)->bytesByTrackingIds('2026-04-01', '2026-04-30', ['customer-42']);

    expect(array_column($rows, 'bytes', 'metric'))->toEqual(['download_bytes' => 30, 'streaming_bytes' => 100]);
});

it('narrows the batch to one metric when asked', function () {
    usageRows(['tracking_id' => 'customer-42'], ['tracking_id' => 'customer-42', 'metric' => 'download_bytes', 'value' => 30]);

    $rows = app(AnalyticsService::class)->bytesByTrackingIds('2026-04-01', '2026-04-30', ['customer-42'], 'download_bytes');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['metric'])->toBe('download_bytes');
});

it('folds duplicate ids and never queries for an empty list', function () {
    usageRows(['tracking_id' => 'a'], ['tracking_id' => 'b']);
    $service = app(AnalyticsService::class);

    // Duplicates are the caller's list, not a query concern, so they are dropped rather than
    // turned into a 422 over something harmless — and must not count the id twice.
    expect(array_column($service->bytesByTrackingIds('2026-04-01', '2026-04-30', ['a', 'b', 'a']), 'bytes', 'tracking_id'))
        ->toEqual(['a' => 100, 'b' => 100]);

    DB::enableQueryLog();

    expect($service->bytesByTrackingIds('2026-04-01', '2026-04-30', []))->toBe([])
        ->and(DB::getQueryLog())->toBe([]);
});

it('splits by the day as well on a daily read, and stays inside the range', function () {
    usageRows(
        ['tracking_id' => 'customer-42'],
        ['tracking_id' => 'customer-42', 'date' => '2026-04-11', 'value' => 5],
        ['tracking_id' => 'customer-42', 'date' => '2026-05-01', 'value' => 999],
    );

    $rows = app(AnalyticsService::class)->bytesByTrackingIds('2026-04-01', '2026-04-30', ['customer-42'], daily: true);

    expect(array_column($rows, 'bytes', 'date'))->toEqual(['2026-04-10' => 100, '2026-04-11' => 5]);
});
