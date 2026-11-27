<?php

/**
 * A video names its thumbnail only once one can exist. The thumbnail is cut from the source's
 * mirror, so a video still waiting for a slot or still downloading has none, and a URL for it
 * was a broken image in every listing — and a miss at the edge for every row on every load.
 */

use App\Data\VideoData;
use App\Enums\VideoStatus;
use App\Models\Project;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function videoAtStatus(VideoStatus $status): Video
{
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    return Video::create([
        'name' => $status->value,
        'duration' => 10,
        'aspect_ratio' => '16:9',
        'status' => $status->value,
        'user_id' => $user->id,
        'project_id' => $project->id,
    ]);
}

it('names no thumbnail before the source has been fetched', function (VideoStatus $status) {
    expect(VideoData::fromModel(videoAtStatus($status))->thumbnailUrl)->toBeNull();
})->with([VideoStatus::PENDING, VideoStatus::DOWNLOADING]);

it('names the thumbnail from then on', function (VideoStatus $status) {
    $video = videoAtStatus($status);

    expect(VideoData::fromModel($video)->thumbnailUrl)->toContain("{$video->ulid}/")->toContain('thumbnail.jpg');
})->with([VideoStatus::RUNNING, VideoStatus::UPLOADING, VideoStatus::COMPLETED, VideoStatus::FAILED]);
