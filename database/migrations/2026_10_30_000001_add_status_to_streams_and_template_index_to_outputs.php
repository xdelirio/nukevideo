<?php

use App\Jobs\AddTrackJob;
use App\Services\CreateVideoStreamsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks can now be added to a video after it completed ({@see AddTrackJob}), which
 * needs two things the schema never had to hold:
 *
 * - `streams.status`: an added track is processed while its video stays COMPLETED and playing, so
 *   its progress and failure cannot ride on the video's status. Null for every stream a video's
 *   own run created — those are ready by definition once the video is.
 * - `outputs.template_index`: the added audio is encoded with the template's audio block for each
 *   output, and nothing linked an output back to its entry in `query.outputs[]`. Outputs are
 *   created in template order ({@see CreateVideoStreamsService}), so the existing
 *   rows are backfilled from their id order within the video.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('streams', 'status')) {
            Schema::table('streams', function (Blueprint $table) {
                $table->string('status', 16)->nullable()->after('type');
            });
        }

        if (! Schema::hasColumn('outputs', 'template_index')) {
            Schema::table('outputs', function (Blueprint $table) {
                $table->unsignedSmallInteger('template_index')->nullable()->after('video_id');
            });
        }

        // A plain loop rather than a window function: it has to run on SQLite in the suite too, and
        // it only touches rows the backfill has not filled yet, so a re-run is a no-op. The video ids
        // are collected up front: paging a query filtered on the very column the loop fills would
        // skip a page for every page it processes.
        $videoIds = DB::table('outputs')->whereNull('template_index')->distinct()->orderBy('video_id')->pluck('video_id');

        foreach ($videoIds as $videoId) {
            $ids = DB::table('outputs')->where('video_id', $videoId)->orderBy('id')->pluck('id');

            foreach ($ids as $index => $id) {
                DB::table('outputs')->where('id', $id)->update(['template_index' => $index]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('streams', 'status')) {
            Schema::table('streams', function (Blueprint $table) {
                $table->dropColumn('status');
            });
        }

        if (Schema::hasColumn('outputs', 'template_index')) {
            Schema::table('outputs', function (Blueprint $table) {
                $table->dropColumn('template_index');
            });
        }
    }
};
