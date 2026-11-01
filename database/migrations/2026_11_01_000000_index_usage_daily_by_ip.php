<?php

use App\Services\AnalyticsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The address was the one dimension the dashboard rankings name that `usage_daily` had no index
 * for. The rankings count distinct values for their top N only ({@see AnalyticsService::topIps()}):
 * the videos and tracking ids look theirs up through `usage_daily_video_index` and
 * `usage_daily_tracking_index`, while ten addresses had to walk the project's whole range to find
 * their own rows — five seconds on a production month, against ten milliseconds with this index.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasIndex('usage_daily', 'usage_daily_ip_index')) {
            return;
        }

        Schema::table('usage_daily', function (Blueprint $table) {
            $table->index(['ip', 'date'], 'usage_daily_ip_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasIndex('usage_daily', 'usage_daily_ip_index')) {
            return;
        }

        Schema::table('usage_daily', function (Blueprint $table) {
            $table->dropIndex('usage_daily_ip_index');
        });
    }
};
