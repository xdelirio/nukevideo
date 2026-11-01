<?php

namespace App\Services;

use App\Enums\UsageMetric;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UsageService
{
    public const TABLE = 'usage_daily';

    /**
     * Every column of the table's unique key, with the value that means "does not apply". A row is
     * identified by all of them, so a writer that leaves one out gets the empty value, never NULL —
     * NULLs do not collide in a unique index and the row would stop being added to.
     */
    private const DIMENSIONS = [
        'date' => null,
        'project_id' => 0,
        'user_id' => 0,
        'metric' => null,
        'external_user_id' => '',
        'video_ulid' => '',
        'ip' => '',
        'tracking_id' => '',
        'node_id' => 0,
        'cache' => '',
    ];

    /**
     * The rollups of delivered bytes, by table, with the one `usage_daily` dimension each keeps.
     * Written beside `usage_daily` on every {@see add()} and read by the dashboard
     * ({@see AnalyticsService}); the migration that creates them says why there are three.
     */
    public const ROLLUPS = [
        'usage_daily_by_video' => 'video_ulid',
        'usage_daily_by_ip' => 'ip',
        'usage_daily_by_tracking_id' => 'tracking_id',
    ];

    // Thirteen bindings a row: well inside what either database accepts in one statement.
    private const CHUNK = 500;

    /**
     * `$projectId` is not optional in practice, only in signature: upload volume and encoding
     * seconds written without a `video_ulid` (every row before it was passed) have nothing else to
     * tell two projects of the same account apart, so without it they land on one row and the
     * split is lost for good. 0 means "no project".
     *
     * `$videoUlid` is the video the upload or the encoding was for, so both can be reported per
     * title. Rows written before it was passed carry none, and that history cannot be split after
     * the fact: it was folded into one row per account and day.
     *
     * Never throws. Both callers are recording a side effect of work that already happened — an
     * upload received, a chunk encoded — and losing the number is better than failing the work.
     */
    public static function record(int $userId, string $metric, float $value, string $externalUserId = '', int $projectId = 0, string $videoUlid = ''): void
    {
        try {
            self::add([[
                'date' => now()->format('Y-m-d'),
                'project_id' => $projectId,
                'user_id' => $userId,
                'metric' => $metric,
                'external_user_id' => $externalUserId,
                'video_ulid' => $videoUlid,
                'value' => $value,
            ]]);
        } catch (\Throwable $e) {
            Log::warning("Failed to record usage ({$metric}) for user {$userId}: {$e->getMessage()}");
        }
    }

    /**
     * Adds rows to the daily totals: a row whose dimensions already exist is summed into it.
     *
     * The aggregation happens here, at write time, rather than in a background merge. Three
     * things it takes care of so that no caller has to:
     *
     * - Rows sharing their dimensions are folded here first. One statement must not name the same
     *   key twice, and a session flushed every minute names it often.
     * - The folded rows are written in a fixed order. Two ingest jobs upserting the same rows in
     *   different orders is the textbook InnoDB deadlock; in one order they simply queue.
     * - Everything goes in one transaction, retried on a deadlock. A batch is therefore applied
     *   whole or not at all, which is what makes retrying a failed job safe — a retry after half a
     *   batch would count that half twice, and a sum has no way to take it back.
     *
     * `country` rides along without being part of the key: it is a function of `ip`, so the rows
     * folded together agree on it, and the first one that knows it wins.
     *
     * @param  list<array<string, mixed>>  $rows  dimensions by column name, plus `value` and
     *                                            optionally `requests` (1 when absent) and `country`
     */
    public static function add(array $rows): void
    {
        $folded = [];

        foreach ($rows as $row) {
            $dimensions = [];

            foreach (self::DIMENSIONS as $column => $empty) {
                $dimensions[$column] = $row[$column] ?? $empty;
            }

            // The unit separator cannot appear in any dimension: every text one is validated or
            // clamped before it gets here.
            $key = implode("\x1f", $dimensions);

            $folded[$key] ??= $dimensions + ['country' => '', 'value' => 0.0, 'requests' => 0];
            $folded[$key]['country'] = $folded[$key]['country'] ?: (string) ($row['country'] ?? '');
            $folded[$key]['value'] += (float) $row['value'];
            $folded[$key]['requests'] += (int) ($row['requests'] ?? 1);
        }

        if ($folded === []) {
            return;
        }

        ksort($folded, SORT_STRING);

        $rollups = self::rollups($folded);

        DB::transaction(function () use ($folded, $rollups) {
            foreach (array_chunk(array_values($folded), self::CHUNK) as $chunk) {
                DB::table(self::TABLE)->upsert($chunk, array_keys(self::DIMENSIONS), self::updates());
            }

            // In the same transaction as the rows they summarise, so a rollup can never hold a
            // batch that `usage_daily` does not, or miss one it does — a retry after a failure
            // would otherwise count the half that landed twice. Always after `usage_daily` and in
            // the order of the constant, so two concurrent batches take their locks in the same
            // order and queue instead of deadlocking.
            $sum = ['value' => self::updates()['value']];

            foreach ($rollups as $table => $rows) {
                foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                    DB::table($table)->upsert($chunk, ['project_id', 'date', 'metric', self::ROLLUPS[$table]], $sum);
                }
            }
        }, 3);
    }

    /**
     * The folded batch summed down to each rollup's grain: the delivery rows only, keyed by date,
     * project, metric and the rollup's one dimension, in key order for the same reason the batch
     * itself is.
     *
     * @param  array<string, array<string, mixed>>  $folded
     * @return array<string, list<array<string, mixed>>>
     */
    private static function rollups(array $folded): array
    {
        $delivery = array_flip(UsageMetric::delivery());
        $rollups = [];

        foreach (self::ROLLUPS as $table => $column) {
            $rows = [];

            foreach ($folded as $row) {
                if (! isset($delivery[$row['metric']])) {
                    continue;
                }

                $key = implode("\x1f", [$row['project_id'], $row['date'], $row['metric'], $row[$column]]);

                $rows[$key] ??= [
                    'date' => $row['date'],
                    'project_id' => $row['project_id'],
                    'metric' => $row['metric'],
                    $column => $row[$column],
                    'value' => 0.0,
                ];
                $rows[$key]['value'] += $row['value'];
            }

            if ($rows !== []) {
                ksort($rows, SORT_STRING);
                $rollups[$table] = array_values($rows);
            }
        }

        return $rollups;
    }

    /**
     * The upsert's update clause: add the incoming numbers to the stored ones, where the framework's
     * default would replace them. The two databases this runs on name the incoming row differently.
     *
     * `country` is only ever filled in, never replaced: a stored row that has none — written while
     * the country database was missing — takes the incoming one, and one that has it keeps it.
     *
     * @return array<string, Expression>
     */
    private static function updates(): array
    {
        $connection = DB::connection();
        $grammar = $connection->getQueryGrammar();
        $mysql = in_array($connection->getDriverName(), ['mysql', 'mariadb'], true);
        $incoming = fn (string $wrapped) => $mysql ? "values({$wrapped})" : "excluded.{$wrapped}";

        $updates = [];

        foreach (['value', 'requests'] as $column) {
            $wrapped = $grammar->wrap($column);

            $updates[$column] = DB::raw("{$wrapped} + {$incoming($wrapped)}");
        }

        $country = $grammar->wrap('country');
        $updates['country'] = DB::raw("case when {$country} = '' then {$incoming($country)} else {$country} end");

        return $updates;
    }
}
