<?php

use App\Console\Commands\ReapStuckVideos;
use App\Jobs\AddTrackJob;
use App\Services\CreateVideoStreamsService;
use App\Services\TrackParamsResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks can now be added to a video after it completed ({@see AddTrackJob}), which needs two
 * things the schema never had to hold:
 *
 * - `streams.status`: an added track is processed while its video stays COMPLETED and playing, so
 *   its progress and failure cannot ride on the video's status. Null for every stream a video's
 *   own run created — those are ready by definition once the video is. Indexed: the reaper looks
 *   for tracks stuck processing on every run ({@see ReapStuckVideos}).
 * - `outputs.template_index`: the added audio is encoded with each output's own audio block, and
 *   nothing linked an output back to its entry in `query.outputs[]`. New outputs record it
 *   ({@see CreateVideoStreamsService}).
 *
 * Schema only: existing outputs are backfilled out of band (their id order within the video, which
 * is the order ingest creates them in) — on a large `outputs` table that is a long write the
 * operator schedules, not something to hold a deploy on. Until then an output without an index
 * takes its audio settings from the audio it already serves ({@see TrackParamsResolver}).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('streams', 'status')) {
            Schema::table('streams', function (Blueprint $table) {
                $table->string('status', 16)->nullable()->after('type')->index();
            });
        }

        if (! Schema::hasColumn('outputs', 'template_index')) {
            Schema::table('outputs', function (Blueprint $table) {
                $table->unsignedSmallInteger('template_index')->nullable()->after('video_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('streams', 'status')) {
            Schema::table('streams', function (Blueprint $table) {
                $table->dropIndex(['status']);
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
