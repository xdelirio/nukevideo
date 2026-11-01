<?php

/**
 * Signals for one person behind many accounts ({@see AnalyticsController::sharedIps()},
 * {@see AnalyticsController::trackingIdIps()}): which addresses several tracking ids share, and
 * where one id watches from. Lists of viewer addresses and labels, so the boundary is the project.
 */

use App\Http\Controllers\Api\AnalyticsController;
use App\Models\Project;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Services\UsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $user = User::factory()->create();
    $this->project = Project::factory()->for($user)->create();
    $other = Project::factory()->for($user)->create();
    Sanctum::actingAs($user);
    $this->withHeader('X-Project-Ulid', $this->project->ulid);

    $row = fn (array $r) => array_merge([
        'date' => '2026-04-10', 'project_id' => $this->project->id, 'user_id' => $user->id, 'metric' => 'streaming_bytes',
        'video_ulid' => '01HZXW3V5N8Q9R2T4Y6B8D0FAA', 'country' => 'MX', 'node_id' => 1, 'value' => 100,
    ], $r);

    UsageService::add([
        // The farm: three accounts, one address, two days.
        $row(['ip' => '10.0.0.1', 'tracking_id' => 'acct-1', 'value' => 500]),
        $row(['ip' => '10.0.0.1', 'tracking_id' => 'acct-2']),
        $row(['ip' => '10.0.0.1', 'tracking_id' => 'acct-3', 'date' => '2026-04-11']),
        $row(['ip' => '10.0.0.1', 'tracking_id' => 'acct-1', 'date' => '2026-04-11', 'metric' => 'download_bytes', 'value' => 50]),
        // A couple sharing a flat: below a minimum of three.
        $row(['ip' => '10.0.0.2', 'tracking_id' => 'acct-4']),
        $row(['ip' => '10.0.0.2', 'tracking_id' => 'acct-5']),
        // Unattributed traffic at the farm's address is no account.
        $row(['ip' => '10.0.0.1', 'tracking_id' => '', 'value' => 9000]),
        // acct-1 travelling.
        $row(['ip' => '10.0.0.9', 'tracking_id' => 'acct-1', 'date' => '2026-04-12', 'country' => 'US', 'value' => 30]),
        // The same address and ids in another project are someone else's traffic.
        $row(['project_id' => $other->id, 'ip' => '10.0.0.1', 'tracking_id' => 'acct-9']),
        $row(['project_id' => $other->id, 'ip' => '10.0.0.2', 'tracking_id' => 'acct-8']),
    ]);
});

it('reports the addresses that enough accounts share, with how often and how much', function () {
    $this->getJson('/api/analytics/shared-ips?from=2026-04-01&to=2026-04-30')
        ->assertOk()
        ->assertExactJson(['data' => [[
            'ip' => '10.0.0.1',
            'country' => 'MX',
            'trackingIdCount' => 3,
            'bytes' => 750,
            'days' => 2,
            'trackingIds' => ['acct-1', 'acct-2', 'acct-3'],
        ]]]);
});

it('lowers the bar on request, ranking by how many accounts share an address', function () {
    $ips = collect($this->getJson('/api/analytics/shared-ips?from=2026-04-01&to=2026-04-30&minTrackingIds=2')->json('data'));

    expect($ips->pluck('ip')->all())->toBe(['10.0.0.1', '10.0.0.2'])
        ->and($ips[1]['trackingIdCount'])->toBe(2);
});

it('leaves out addresses shared past a ceiling, before the limit takes the page', function () {
    // A carrier gateway: more accounts than any farm, so ranked first it would take the only row.
    UsageService::add(array_map(fn (int $i) => [
        'date' => '2026-04-10', 'project_id' => $this->project->id, 'user_id' => $this->project->user_id, 'metric' => 'streaming_bytes',
        'ip' => '100.64.0.1', 'tracking_id' => "phone-{$i}", 'value' => 10,
    ], range(1, 12)));

    expect(collect($this->getJson('/api/analytics/shared-ips?from=2026-04-01&to=2026-04-30&limit=1')->json('data'))->pluck('ip')->all())
        ->toBe(['100.64.0.1'])
        ->and(collect($this->getJson('/api/analytics/shared-ips?from=2026-04-01&to=2026-04-30&limit=1&maxTrackingIds=10')->json('data'))->pluck('ip')->all())
        ->toBe(['10.0.0.1'])
        // The bounds are inclusive.
        ->and(collect($this->getJson('/api/analytics/shared-ips?from=2026-04-01&to=2026-04-30&minTrackingIds=2&maxTrackingIds=2')->json('data'))->pluck('ip')->all())
        ->toBe(['10.0.0.2']);
});

it('pages through the report in a total order, the address breaking ties', function () {
    // Two addresses tied on count and bytes: only the address can order them.
    UsageService::add(array_merge(...array_map(fn (string $ip) => array_map(fn (int $i) => [
        'date' => '2026-04-10', 'project_id' => $this->project->id, 'user_id' => $this->project->user_id, 'metric' => 'streaming_bytes',
        'ip' => $ip, 'tracking_id' => "{$ip}-{$i}", 'value' => 10,
    ], [1, 2]), ['10.0.0.20', '10.0.0.3'])));

    $page = fn (int $offset) => collect($this->getJson("/api/analytics/shared-ips?from=2026-04-01&to=2026-04-30&minTrackingIds=2&limit=2&offset={$offset}")->json('data'))->pluck('ip')->all();

    expect($page(0))->toBe(['10.0.0.1', '10.0.0.2'])
        ->and($page(2))->toBe(['10.0.0.20', '10.0.0.3'])
        ->and($page(4))->toBe([]);

    $this->getJson('/api/analytics/shared-ips?from=2026-04-01&to=2026-04-30&offset=-1')->assertStatus(422)->assertJsonValidationErrors('offset');
});

it('refuses a ceiling below the floor, which could only answer empty', function (string $query) {
    $this->getJson("/api/analytics/shared-ips?from=2026-04-01&to=2026-04-30&{$query}")->assertStatus(422)->assertJsonValidationErrors('maxTrackingIds');
})->with([
    'below the default floor of three' => 'maxTrackingIds=2',
    'below an explicit floor' => 'minTrackingIds=5&maxTrackingIds=4',
]);

it('caps the ids listed per address while keeping the count exact', function () {
    $rows = app(AnalyticsService::class)->sharedIps('2026-04-01', '2026-04-30', $this->project->id, 3, 10, idsPerIp: 2);

    expect($rows[0]['tracking_id_count'])->toBe(3)
        // Heaviest first: acct-1 moved the most bytes there.
        ->and($rows[0]['tracking_ids'])->toBe(['acct-1', 'acct-2']);
});

it('lists where one account watches from', function () {
    $this->getJson('/api/analytics/tracking-ids/acct-1/ips?from=2026-04-01&to=2026-04-30')
        ->assertOk()
        ->assertExactJson(['data' => [
            ['ip' => '10.0.0.1', 'country' => 'MX', 'bytes' => 550, 'requests' => 2, 'days' => 2, 'firstSeen' => '2026-04-10', 'lastSeen' => '2026-04-11'],
            ['ip' => '10.0.0.9', 'country' => 'US', 'bytes' => 30, 'requests' => 1, 'days' => 1, 'firstSeen' => '2026-04-12', 'lastSeen' => '2026-04-12'],
        ]]);
});

it('answers nothing for an id the project never served', function () {
    // acct-9 exists, in another project.
    $this->getJson('/api/analytics/tracking-ids/acct-9/ips?from=2026-04-01&to=2026-04-30')->assertOk()->assertExactJson(['data' => []]);
});

it('needs project context for both, and refuses a malformed id', function () {
    $this->withHeaders(['X-Project-Ulid' => ''])->getJson('/api/analytics/shared-ips?from=2026-04-01&to=2026-04-30')->assertStatus(400);
    $this->withHeaders(['X-Project-Ulid' => ''])->getJson('/api/analytics/tracking-ids/acct-1/ips?from=2026-04-01&to=2026-04-30')->assertStatus(400);

    $this->withHeaders(['X-Project-Ulid' => $this->project->ulid])
        ->getJson('/api/analytics/tracking-ids/'.rawurlencode('a b').'/ips?from=2026-04-01&to=2026-04-30')
        ->assertStatus(422)
        ->assertJsonValidationErrors('trackingId');
});

it('refuses a bar of one, which is every viewer', function () {
    $this->getJson('/api/analytics/shared-ips?from=2026-04-01&to=2026-04-30&minTrackingIds=1')->assertStatus(422)->assertJsonValidationErrors('minTrackingIds');
});
