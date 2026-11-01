<?php

/**
 * The per-dimension rollups of delivered bytes ({@see UsageService::ROLLUPS}). The dashboard reads
 * them instead of `usage_daily`, so what these pin is that they never disagree with it: a rollup
 * that drifted would show a total the billing endpoints contradict, and nothing on the dashboard
 * would look wrong.
 */

use App\Services\AnalyticsService;
use App\Services\UsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const ROLLUP_VIDEO_A = '01HZXW3V5N8Q9R2T4Y6B8D0FAA';
const ROLLUP_VIDEO_B = '01HZXW3V5N8Q9R2T4Y6B8D0FBB';

function rolledUp(array $row = []): array
{
    return $row + [
        'date' => '2026-04-10', 'project_id' => 1, 'user_id' => 1, 'metric' => 'streaming_bytes',
        'video_ulid' => ROLLUP_VIDEO_A, 'ip' => '1.1.1.1', 'tracking_id' => 'alice', 'node_id' => 3, 'cache' => 'HIT', 'value' => 100,
    ];
}

/** A varied batch: rows that fold into each other in one rollup and stay apart in another. */
function rollupFixture(): array
{
    return [
        rolledUp(),
        // Same video and address, another edge: one row in every rollup, two in `usage_daily`.
        rolledUp(['node_id' => 4, 'cache' => 'MISS', 'value' => 40]),
        rolledUp(['ip' => '2.2.2.2', 'tracking_id' => 'bob', 'value' => 50]),
        rolledUp(['video_ulid' => ROLLUP_VIDEO_B, 'metric' => 'download_bytes', 'cache' => 'OFF', 'value' => 30]),
        rolledUp(['date' => '2026-04-11', 'tracking_id' => '', 'value' => 5]),
        rolledUp(['date' => '2026-04-11', 'video_ulid' => '', 'metric' => 'asset_bytes', 'value' => 2]),
        // The same viewer label, in two spellings: two subscribers, never folded into one.
        rolledUp(['tracking_id' => 'Alice', 'value' => 9]),
        rolledUp(['project_id' => 2, 'user_id' => 2, 'ip' => '9.9.9.9', 'tracking_id' => 'mallory', 'value' => 1000]),
        // Not delivery: kept out of every rollup.
        ['date' => '2026-04-10', 'project_id' => 1, 'user_id' => 1, 'metric' => 'upload_bytes', 'value' => 4096],
        ['date' => '2026-04-10', 'project_id' => 1, 'user_id' => 1, 'metric' => 'encoding_cpu', 'value' => 12.5],
        ['date' => '2026-04-10', 'metric' => 'origin_bytes', 'video_ulid' => ROLLUP_VIDEO_A, 'ip' => '2.2.2.2', 'node_id' => 3, 'value' => 60],
    ];
}

/** What a rollup has to hold: `usage_daily`'s delivery rows summed down to its grain. */
function expectedRollup(string $column): array
{
    return DB::table(UsageService::TABLE)
        ->whereIn('metric', ['streaming_bytes', 'download_bytes', 'asset_bytes', 'bandwidth_bytes'])
        ->select('date', 'project_id', 'metric', $column)
        ->selectRaw('sum(value) as value')
        ->groupBy('date', 'project_id', 'metric', $column)
        ->get()
        ->map(fn (object $row) => [$row->date, (int) $row->project_id, $row->metric, $row->{$column}, (float) $row->value])
        ->sortBy(fn (array $row) => implode('|', $row))
        ->values()
        ->all();
}

function storedRollup(string $table, string $column): array
{
    return DB::table($table)
        ->get()
        ->map(fn (object $row) => [$row->date, (int) $row->project_id, $row->metric, $row->{$column}, (float) $row->value])
        ->sortBy(fn (array $row) => implode('|', $row))
        ->values()
        ->all();
}

it('keeps every rollup equal to usage_daily summed down to its grain', function () {
    UsageService::add(rollupFixture());
    // Written twice, in another order: the second batch adds to the rows the first one made.
    UsageService::add(array_reverse(rollupFixture()));

    foreach (UsageService::ROLLUPS as $table => $column) {
        expect(storedRollup($table, $column))->toEqual(expectedRollup($column))->not->toBeEmpty();
    }

    expect(DB::table('usage_daily_by_ip')->where('metric', 'like', '%upload%')->orWhere('metric', 'origin_bytes')->exists())->toBeFalse()
        // Two spellings of one label, two rows.
        ->and(DB::table('usage_daily_by_tracking_id')->whereIn('tracking_id', ['alice', 'Alice'])->where('date', '2026-04-10')->where('metric', 'streaming_bytes')->count())->toBe(2);
});

it('writes nothing to a rollup for a batch that holds no delivery', function () {
    UsageService::record(1, 'upload_bytes', 4096, projectId: 1);

    foreach (array_keys(UsageService::ROLLUPS) as $table) {
        expect(DB::table($table)->count())->toBe(0);
    }
});

it('backfills the rollups from what usage_daily already holds', function () {
    // Rows that predate the rollups: in `usage_daily`, and in nothing else.
    UsageService::add(rollupFixture());
    foreach (array_keys(UsageService::ROLLUPS) as $table) {
        DB::table($table)->delete();
    }

    $migration = require database_path('migrations/2026_11_01_000001_create_usage_daily_rollup_tables.php');
    $migration->up();
    // Re-run: it recomputes, so a second run cannot double what the first one wrote.
    $migration->up();

    foreach (UsageService::ROLLUPS as $table => $column) {
        expect(storedRollup($table, $column))->toEqual(expectedRollup($column))->not->toBeEmpty();
    }
});

it('answers the unfiltered dashboard from the rollups, and the filtered one from usage_daily', function () {
    UsageService::add(rollupFixture());
    $service = app(AnalyticsService::class);

    $before = $service->summary('2026-04-01', '2026-04-30', projectId: 1);

    // Emptied behind the service's back: what changes is what was read from them.
    foreach (array_keys(UsageService::ROLLUPS) as $table) {
        DB::table($table)->delete();
    }

    expect($before['total_bytes'])->toBe(236)
        ->and($service->summary('2026-04-01', '2026-04-30', projectId: 1)['total_bytes'])->toBe(0)
        ->and($service->topVideos('2026-04-01', '2026-04-30', projectId: 1))->toBe([])
        // A filter names a column no rollup keeps, so it is answered from `usage_daily` as before.
        ->and($service->summary('2026-04-01', '2026-04-30', video: ROLLUP_VIDEO_A, projectId: 1)['total_bytes'])->toBe(204)
        ->and($service->summary('2026-04-01', '2026-04-30', trackingId: 'bob', projectId: 1)['total_bytes'])->toBe(50);
});

it('answers the dashboard from the rollups with what usage_daily holds', function () {
    UsageService::add(rollupFixture());
    $service = app(AnalyticsService::class);

    expect($service->summary('2026-04-01', '2026-04-30', projectId: 1))->toBe([
        'total_bytes' => 236,
        // A, B and the asset row that carried no video, which counts like any other value.
        'unique_videos' => 3,
        'unique_ips' => 2,
        // alice, Alice, bob and the unattributed traffic.
        'unique_tracking_ids' => 4,
    ])
        ->and(array_column($service->bandwidthOverTime('2026-04-01', '2026-04-30', projectId: 1), 'bytes', 'date'))
        ->toEqual(['2026-04-10' => 229, '2026-04-11' => 7])
        ->and($service->topIps('2026-04-01', '2026-04-30', projectId: 1))
        ->toEqual([
            ['ip' => '1.1.1.1', 'bytes' => 186, 'sessions' => 3],
            ['ip' => '2.2.2.2', 'bytes' => 50, 'sessions' => 1],
        ]);
});
