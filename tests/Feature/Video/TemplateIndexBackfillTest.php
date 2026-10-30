<?php

/**
 * The backfill fills the column it filters on, so offset pagination over that filter skips a page
 * for every page it processes — on a real catalogue, half the videos.
 */

use App\Models\Output;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('backfills every video past the first chunk', function () {
    $project = Project::factory()->for(User::factory())->create();
    $video = projectVideo($project);

    $rows = [];
    foreach (range(1, 1200) as $i) {
        $template = DB::table('videos')->insertGetId([
            'ulid' => (string) Str::ulid(), 'user_id' => $video->user_id, 'project_id' => $video->project_id,
            'template_id' => $video->template_id, 'name' => "v{$i}", 'duration' => 1, 'aspect_ratio' => '16:9',
            'status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $rows[] = ['ulid' => (string) Str::ulid(), 'video_id' => $template, 'status' => 'completed', 'created_at' => now(), 'updated_at' => now()];
        $rows[] = ['ulid' => (string) Str::ulid(), 'video_id' => $template, 'status' => 'completed', 'created_at' => now(), 'updated_at' => now()];
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('outputs')->insert($chunk);
    }

    $migration = require database_path('migrations/2026_10_30_000001_add_status_to_streams_and_template_index_to_outputs.php');
    $migration->up();

    expect(Output::whereNull('template_index')->count())->toBe(0)
        ->and(Output::where('template_index', 1)->count())->toBe(1200);
});
