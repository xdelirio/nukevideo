<?php

/**
 * The reads over `usage_daily`, run for real. Every other analytics test stubs this service to pin
 * what the endpoints let through; these pin what the queries answer.
 *
 * The properties that matter are the ones that fail silently: a sum that crosses from delivered
 * bytes into encoding seconds, a read that leaks past its project, a distinct count that is really
 * a row count.
 */

use App\Data\Analytics\MetricsQueryData;
use App\Enums\MetricDimension;
use App\Enums\MetricMeasure;
use App\Jobs\IngestBandwidthJob;
use App\Services\AnalyticsService;
use App\Services\UsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

const VIDEO_A = '01HZXW3V5N8Q9R2T4Y6B8D0FAA';
const VIDEO_B = '01HZXW3V5N8Q9R2T4Y6B8D0FBB';
const VIDEO_C = '01HZXW3V5N8Q9R2T4Y6B8D0FCC';

/** One delivery row, every field overridable. */
function delivered(array $row = []): array
{
    return $row + [
        'date' => '2026-04-10', 'project_id' => 1, 'user_id' => 1, 'metric' => 'streaming_bytes',
        'video_ulid' => VIDEO_A, 'ip' => '1.1.1.1', 'tracking_id' => 'alice', 'node_id' => 3, 'cache' => 'HIT', 'value' => 100,
    ];
}

beforeEach(function () {
    $this->service = app(AnalyticsService::class);

    UsageService::add([
        delivered(),
        delivered(['ip' => '2.2.2.2', 'tracking_id' => 'bob', 'value' => 50, 'cache' => 'MISS']),
        delivered(['date' => '2026-04-11', 'video_ulid' => VIDEO_B, 'value' => 30, 'metric' => 'download_bytes', 'cache' => 'OFF']),
        delivered(['date' => '2026-04-11', 'video_ulid' => VIDEO_C, 'tracking_id' => '', 'value' => 5]),
        // Another tenant, and a day outside the range every case below asks for.
        delivered(['project_id' => 2, 'user_id' => 2, 'video_ulid' => VIDEO_C, 'ip' => '9.9.9.9', 'tracking_id' => 'mallory', 'value' => 1000]),
        delivered(['date' => '2026-05-01', 'value' => 7000]),
        // Not delivery at all: the same `value` column, in other units.
        ['date' => '2026-04-10', 'project_id' => 1, 'user_id' => 1, 'metric' => 'encoding_cpu', 'value' => 12.5],
        ['date' => '2026-04-10', 'project_id' => 1, 'user_id' => 1, 'metric' => 'upload_bytes', 'external_user_id' => 'cliente-77', 'value' => 4096],
        ['date' => '2026-04-10', 'project_id' => 2, 'user_id' => 2, 'metric' => 'upload_bytes', 'external_user_id' => 'otro', 'value' => 1],
        ['date' => '2026-04-10', 'metric' => IngestBandwidthJob::ORIGIN_METRIC, 'video_ulid' => VIDEO_A, 'ip' => '2.2.2.2', 'node_id' => 3, 'value' => 60],
    ]);
});

it('sums delivered bytes only, and counts distinct viewers rather than rows', function () {
    expect($this->service->summary('2026-04-01', '2026-04-30', projectId: 1))->toBe([
        'total_bytes' => 185,
        'unique_videos' => 3,
        // 1.1.1.1 appears on three rows.
        'unique_ips' => 2,
        // alice, bob and the unattributed traffic, which is real bandwidth and counted as such.
        'unique_tracking_ids' => 3,
    ]);
});

it('reads the whole instance when no project is named', function () {
    expect($this->service->summary('2026-04-01', '2026-04-30')['total_bytes'])->toBe(1185);
});

it('narrows by video, by tracking id and by metric', function () {
    expect($this->service->summary('2026-04-01', '2026-04-30', video: VIDEO_A, projectId: 1)['total_bytes'])->toBe(150)
        ->and($this->service->summary('2026-04-01', '2026-04-30', trackingId: 'bob', projectId: 1)['total_bytes'])->toBe(50)
        // The empty string is a value — traffic that carried no id — not "no filter".
        ->and($this->service->summary('2026-04-01', '2026-04-30', trackingId: '', projectId: 1)['total_bytes'])->toBe(5)
        ->and($this->service->summary('2026-04-01', '2026-04-30', metric: 'download_bytes', projectId: 1)['total_bytes'])->toBe(30)
        // A metric outside the delivery set matches nothing instead of reaching across.
        ->and($this->service->summary('2026-04-01', '2026-04-30', metric: 'encoding_cpu', projectId: 1)['total_bytes'])->toBe(0);
});

it('draws bandwidth over time, a row per day that had traffic', function () {
    $rows = $this->service->bandwidthOverTime('2026-04-01', '2026-04-30', projectId: 1);

    expect(array_column($rows, 'bytes', 'date'))->toEqual(['2026-04-10' => 150, '2026-04-11' => 35])
        ->and(array_column($rows, 'sessions', 'date'))->toEqual(['2026-04-10' => 2, '2026-04-11' => 1]);
});

it('ranks addresses, videos and tracking ids by bytes, up to the limit', function () {
    expect(array_column($this->service->topIps('2026-04-01', '2026-04-30', projectId: 1), 'bytes', 'ip'))
        ->toEqual(['1.1.1.1' => 135, '2.2.2.2' => 50])
        ->and(array_column($this->service->topVideos('2026-04-01', '2026-04-30', limit: 2, projectId: 1), 'bytes', 'video'))
        ->toEqual([VIDEO_A => 150, VIDEO_B => 30])
        ->and(array_column($this->service->bandwidthByTrackingId('2026-04-01', '2026-04-30', projectId: 1), 'bytes', 'tracking_id'))
        ->toEqual(['alice' => 130, 'bob' => 50, '' => 5]);
});

it('counts distinct viewers, videos and addresses for each ranked row', function () {
    // Counted after the ranking, for its winners only (`countDistinctFor()`); these pin that the
    // second query reads the same rows the first one summed.
    expect(array_column($this->service->topIps('2026-04-01', '2026-04-30', projectId: 1), 'sessions', 'ip'))
        // 1.1.1.1 watched all three videos; 2.2.2.2 only the first.
        ->toBe(['1.1.1.1' => 3, '2.2.2.2' => 1])
        ->and(array_column($this->service->topVideos('2026-04-01', '2026-04-30', projectId: 1), 'unique_ips', 'video'))
        ->toBe([VIDEO_A => 2, VIDEO_B => 1, VIDEO_C => 1])
        ->and(array_map(
            fn (array $row) => [$row['tracking_id'], $row['videos'], $row['unique_ips']],
            $this->service->bandwidthByTrackingId('2026-04-01', '2026-04-30', projectId: 1),
        ))->toBe([['alice', 2, 1], ['bob', 1, 1], ['', 1, 1]]);
});

it('counts within the filters and the project, not across them', function () {
    // Narrowed to one video, 1.1.1.1 watched one; the other tenant's viewer of VIDEO_C is not
    // counted towards this project's VIDEO_C.
    expect(array_column($this->service->topIps('2026-04-01', '2026-04-30', video: VIDEO_A, projectId: 1), 'sessions', 'ip'))
        ->toBe(['1.1.1.1' => 1, '2.2.2.2' => 1])
        ->and(array_column($this->service->topVideos('2026-04-01', '2026-04-30', projectId: 1), 'sessions', 'video')[VIDEO_C])
        ->toBe(1)
        // A ranking cut by its limit still counts its winners correctly.
        ->and($this->service->topIps('2026-04-01', '2026-04-30', limit: 1, projectId: 1))
        ->toEqual([['ip' => '1.1.1.1', 'bytes' => 135, 'sessions' => 3]]);
});

it('draws the series of the top videos only', function () {
    $rows = $this->service->bandwidthByVideo('2026-04-01', '2026-04-30', limit: 2, projectId: 1);

    expect(array_map(fn (array $row) => [$row['date'], $row['video'], (int) $row['bytes']], $rows))->toBe([
        ['2026-04-10', VIDEO_A, 150],
        ['2026-04-11', VIDEO_B, 30],
    ]);
});

it('reports each edge with what it served, what it fetched and how often its cache had it', function () {
    expect($this->service->edgeDelivery('2026-04-01', '2026-04-30'))->toEqual([[
        'node_id' => 3,
        'delivered_bytes' => 1185.0,
        'origin_bytes' => 60.0,
        // HIT over HIT + MISS. The download served with the cache OFF is in neither.
        'hit_ratio' => round(1105 / 1155, 4),
    ]]);
});

it('counts a revalidated lookup as a hit: the bytes came off the pool, S3 only answered a 304', function () {
    // What every cached manifest turns into each 30 seconds, and a segment past its 30 days.
    UsageService::add([delivered(['date' => '2026-04-12', 'cache' => 'REVALIDATED', 'value' => 45])]);

    expect($this->service->edgeDelivery('2026-04-01', '2026-04-30')[0]['hit_ratio'])
        ->toBe(round((1105 + 45) / (1155 + 45), 4));
});

it('counts a stale answer as a hit: the bytes came off the pool while S3 was being asked or was down', function (string $status) {
    // What the edge answers with during an S3 outage, or while another viewer refreshes a manifest.
    UsageService::add([delivered(['date' => '2026-04-12', 'cache' => $status, 'value' => 45])]);

    expect($this->service->edgeDelivery('2026-04-01', '2026-04-30')[0]['hit_ratio'])
        ->toBe(round((1105 + 45) / (1155 + 45), 4));
})->with(['STALE', 'UPDATING']);

it('keeps encoding seconds and upload volume out of the bytes, and reads them on their own', function () {
    expect($this->service->encodingUsage('2026-04-01', '2026-04-30'))->toBe(['cpu' => 12.5])
        ->and($this->service->encodingUsageOverTime('2026-04-01', '2026-04-30'))->toEqual([
            ['date' => '2026-04-10', 'device' => 'cpu', 'seconds' => 12.5],
        ])
        ->and($this->service->usageSummary('2026-04-01', '2026-04-30', 1))->toEqual(['upload_bytes' => 4096, 'encoding_cpu' => 12.5])
        ->and($this->service->topExternalUsers('2026-04-01', '2026-04-30', 1))->toEqual([
            ['external_user_id' => 'cliente-77', 'bytes' => 4096],
        ]);
});

it('answers an account with its own usage and nobody else\'s', function () {
    $rows = $this->service->accountUsage(1, '2026-04-01', '2026-04-30', 'upload_bytes');

    expect($rows)->toEqual([
        ['metric' => 'upload_bytes', 'external_user_id' => 'cliente-77', 'value' => 4096, 'date' => '2026-04-10'],
    ])->and($this->service->accountUsage(1, '2026-04-01', '2026-04-30', externalUserId: 'otro'))->toBe([]);
});

it('breaks the general query down by whatever dimensions it is given', function () {
    $rows = $this->service->query('2026-04-01', '2026-04-30', [MetricDimension::VIDEO, MetricDimension::METRIC], projectId: 1);

    expect(array_map(fn (array $row) => [$row['video'], $row['metric'], (int) $row['value']], $rows))->toBe([
        [VIDEO_A, 'streaming_bytes', 150],
        [VIDEO_B, 'download_bytes', 30],
        [VIDEO_C, 'streaming_bytes', 5],
    ]);
});

it('filters the general query by the lists it is given, and pins it to an account', function () {
    $byIp = $this->service->query('2026-04-01', '2026-04-30', [MetricDimension::IP], ['video_ulid' => [VIDEO_C]]);

    // Unscoped, so both tenants' rows for the video the caller named.
    expect(array_column($byIp, 'value', 'ip'))->toEqual(['1.1.1.1' => 5, '9.9.9.9' => 1000]);

    $uploads = $this->service->query('2026-04-01', '2026-04-30', [MetricDimension::EXTERNAL_USER_ID], metrics: ['upload_bytes'], accountId: 1);

    expect(array_column($uploads, 'value', 'external_user_id'))->toEqual(['cliente-77' => 4096]);
});

it('measures requests and distinct viewers per group, beside the sum', function () {
    $rows = $this->service->query('2026-04-01', '2026-04-30', [MetricDimension::VIDEO], projectId: 1, measures: [
        MetricMeasure::REQUESTS, MetricMeasure::UNIQUE_IPS, MetricMeasure::UNIQUE_TRACKING_IDS,
    ]);

    expect(array_map(fn (array $row) => [$row['video'], (int) $row['value'], (int) $row['requests'], (int) $row['unique_ips'], (int) $row['unique_tracking_ids']], $rows))->toBe([
        // Two viewers, two tracking ids, one request each.
        [VIDEO_A, 150, 2, 2, 2],
        [VIDEO_B, 30, 1, 1, 1],
        // Unattributed traffic is real bytes and a real request, but not a tracked viewer.
        [VIDEO_C, 5, 1, 1, 0],
    ]);
});

it('books upload volume and encoding time against their video, so both read per title', function () {
    UsageService::record(1, 'encoding_cpu', 30, projectId: 1, videoUlid: VIDEO_B);
    UsageService::record(1, 'encoding_cpu', 12, projectId: 1, videoUlid: VIDEO_B);

    $rows = $this->service->query(now()->format('Y-m-d'), now()->format('Y-m-d'), [MetricDimension::VIDEO], metrics: ['encoding_cpu'], accountId: 1, projectId: 1);

    expect(array_column($rows, 'value', 'video'))->toEqual([VIDEO_B => 42]);
});

it('refuses a result past the row cap instead of truncating it', function () {
    $rows = [];
    for ($i = 0; $i <= MetricsQueryData::MAX_ROWS; $i++) {
        $rows[] = delivered(['tracking_id' => "viewer-{$i}"]);
    }
    UsageService::add($rows);

    $this->service->query('2026-04-01', '2026-04-30', [MetricDimension::TRACKING_ID], projectId: 1);
})->throws(ValidationException::class);
