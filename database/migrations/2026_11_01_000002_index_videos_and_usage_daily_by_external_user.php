<?php

use App\Services\ExternalUserService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for reading a project's customers by id ({@see ExternalUserService}).
 *
 * - `videos (project_id, external_user_id)`: the video listing has filtered on the customer since
 *   it existed, and the customer endpoints group and count by it, all with no index — every one of
 *   those read the whole project's catalogue to find one customer's videos.
 * - `usage_daily (external_user_id, project_id, date)`: a customer's usage over a range. The unique
 *   key leads with `project_id, date`, so without this the read walked every row of the project in
 *   the range — every viewer of every video — to keep the few that carry this customer.
 *
 * The customer leads that second index, not the project, on purpose. Led by `project_id` it was a
 * prefix of every tenant read, and MySQL picked it for reads that never name a customer — where the
 * date, stuck behind `external_user_id`, cannot narrow anything — and walked the project's whole
 * history row by row: the shared-address report went from 1.9 to 4.3 seconds on production data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasIndex('videos', 'videos_project_external_user_index')) {
            Schema::table('videos', function (Blueprint $table) {
                $table->index(['project_id', 'external_user_id'], 'videos_project_external_user_index');
            });
        }

        if (! Schema::hasIndex('usage_daily', 'usage_daily_external_user_index')) {
            Schema::table('usage_daily', function (Blueprint $table) {
                $table->index(['external_user_id', 'project_id', 'date'], 'usage_daily_external_user_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('videos', 'videos_project_external_user_index')) {
            Schema::table('videos', function (Blueprint $table) {
                $table->dropIndex('videos_project_external_user_index');
            });
        }

        if (Schema::hasIndex('usage_daily', 'usage_daily_external_user_index')) {
            Schema::table('usage_daily', function (Blueprint $table) {
                $table->dropIndex('usage_daily_external_user_index');
            });
        }
    }
};
