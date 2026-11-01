<?php

use App\Services\AnalyticsService;
use App\Services\UsageService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Three rollups of `usage_daily`'s delivered bytes, one per dimension the dashboard counts or ranks
 * ({@see AnalyticsService}).
 *
 * `usage_daily` keeps a row per day for every combination of video, address, tracking id, edge and
 * cache status: a viewer is a row, and so is every edge that served them. That grain is what
 * billing and drill-down need, and it is the wrong one for a dashboard — on a production month the
 * project's 585k rows had to be read back six times per load to draw thirty daily points and four
 * top-ten lists, which took 30 seconds. Each rollup keeps a single one of those dimensions, so a
 * day holds as many rows as it had distinct values of it: about 45k a month each, and every read
 * over them is tens of milliseconds.
 *
 * Why three and not one: every distinct count needs its own column — distinct addresses need the
 * address, distinct videos the video — and a table that kept two of them would be back to one row
 * per viewer per video, about the size of `usage_daily` itself.
 *
 * Shape, and why:
 *
 * - The key IS the aggregation, exactly as in `usage_daily`: the writer upserts `value + new`
 *   against it ({@see UsageService::add()}). It is the primary key rather than a unique index on
 *   top of an id, so the rows are stored in key order with `value` beside them, and the range a
 *   tenant reads is one contiguous stretch of the table instead of an index walk with a row
 *   lookup per entry.
 * - It leads with `project_id, date` because that is how a tenant reads; `(metric, date)` serves
 *   the instance-wide reads, which name no project.
 * - Only the delivery metrics are rolled up. Upload volume and encoding seconds are a handful of
 *   rows a day and are read from `usage_daily` directly; the operator's origin egress carries no
 *   project and is only read per node.
 * - The dimension columns copy `usage_daily`'s types and collations, `tracking_id`'s binary one
 *   included: `Viewer1` and `viewer1` are two subscribers here too.
 *
 * The backfill runs in the same transaction as an emptying of the rollup, so it recomputes rather
 * than adds. Migrations run while the ingest may already be on the new code: anything that ingest
 * committed before the backfill read `usage_daily` is in `usage_daily` and is counted once, and
 * anything after waits on the backfill's locks and lands on top of it.
 */
return new class extends Migration
{
    /**
     * Frozen here rather than read from the service: this is what existed when the migration was
     * written, and a later change to the service must not change what an old migration does.
     */
    private const ROLLUPS = [
        'usage_daily_by_video' => 'video_ulid',
        'usage_daily_by_ip' => 'ip',
        'usage_daily_by_tracking_id' => 'tracking_id',
    ];

    private const DELIVERY = ['streaming_bytes', 'download_bytes', 'asset_bytes', 'bandwidth_bytes'];

    public function up(): void
    {
        $exact = Schema::getConnection()->getDriverName() === 'sqlite' ? null : 'utf8mb4_bin';

        foreach (self::ROLLUPS as $name => $column) {
            if (! Schema::hasTable($name)) {
                Schema::create($name, function (Blueprint $table) use ($column, $exact) {
                    $table->date('date');
                    $table->unsignedInteger('project_id')->default(0);
                    $table->string('metric', 32);

                    match ($column) {
                        'video_ulid' => $table->char('video_ulid', 26)->default(''),
                        'ip' => $table->string('ip', 45)->default(''),
                        'tracking_id' => $table->string('tracking_id', 64)->default('')->collation($exact),
                    };

                    $table->double('value')->default(0);

                    $table->primary(['project_id', 'date', 'metric', $column]);
                    $table->index(['metric', 'date']);
                });
            }

            DB::transaction(function () use ($name, $column) {
                DB::table($name)->delete();

                DB::table($name)->insertUsing(
                    ['date', 'project_id', 'metric', $column, 'value'],
                    DB::table('usage_daily')
                        ->select('date', 'project_id', 'metric', $column)
                        ->selectRaw('sum(value)')
                        ->whereIn('metric', self::DELIVERY)
                        ->groupBy('date', 'project_id', 'metric', $column),
                );
            }, 3);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::ROLLUPS) as $name) {
            Schema::dropIfExists($name);
        }
    }
};
