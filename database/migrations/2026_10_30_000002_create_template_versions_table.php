<?php

use App\Http\Controllers\TemplateController;
use App\Jobs\AddTrackJob;
use App\Models\Video;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A template's encoding definition (`query`) is versioned, and every video pins the version it was
 * ingested with. Before this, editing a template changed after the fact what its existing videos
 * were retried, re-probed and extended with: a retry encoded differently from what was published,
 * and a track added later ({@see AddTrackJob}) could be encoded with a codec the video
 * never used. Versions are immutable and never deleted while a video points at one — a template
 * with videos cannot be deleted at all ({@see TemplateController::destroy}).
 *
 * Schema only. Existing templates and videos are backfilled out of band (version 1 = the template's
 * current query, and every video pinned to it): on a large `videos` table that is a long write the
 * operator schedules, not something to hold a deploy on. Until then nothing breaks — a template
 * without a version gets one on its next encoding change, and a video without one encodes with its
 * template ({@see Video::encodingDefinition}).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('template_versions')) {
            Schema::create('template_versions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('template_id')->constrained()->cascadeOnDelete();
                $table->unsignedInteger('version');
                $table->json('query');
                $table->timestamps();

                $table->unique(['template_id', 'version']);
            });
        }

        // No foreign key: it would make templates and their versions reference each other, and the
        // version row always exists before the pointer is set.
        if (! Schema::hasColumn('templates', 'current_version_id')) {
            Schema::table('templates', function (Blueprint $table) {
                $table->unsignedBigInteger('current_version_id')->nullable()->after('query');
            });
        }

        // An index, not a foreign key: on MariaDB adding a foreign key to a populated table copies
        // it with writes blocked, and `videos` is written by every worker all the time. Nothing
        // needs the constraint — a version is never deleted while a video uses it: its template
        // cannot be deleted then, and a project deletes its videos before its templates.
        if (! Schema::hasColumn('videos', 'template_version_id')) {
            Schema::table('videos', function (Blueprint $table) {
                $table->unsignedBigInteger('template_version_id')->nullable()->after('template_id')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('videos', 'template_version_id')) {
            Schema::table('videos', function (Blueprint $table) {
                $table->dropIndex(['template_version_id']);
                $table->dropColumn('template_version_id');
            });
        }

        if (Schema::hasColumn('templates', 'current_version_id')) {
            Schema::table('templates', function (Blueprint $table) {
                $table->dropColumn('current_version_id');
            });
        }

        Schema::dropIfExists('template_versions');
    }
};
