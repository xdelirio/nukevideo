<?php

/**
 * A failed add must leave the published manifests exactly as they were — including a failure in
 * the middle of writing them, after some were already rewritten to reference the new track.
 */

use App\Enums\StreamStatus;
use App\Models\Project;
use App\Models\User;
use App\Services\TrackPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('restores every manifest and removes the uploads when a later graft throws', function () {
    Storage::fake('s3');
    $video = projectVideo(Project::factory()->for(User::factory())->create());
    $stream = $video->streams()->create([
        'path' => 'tmp-videos/'.Str::ulid().'.srt', 'type' => 'subtitle', 'name' => 'Fr', 'language' => 'fr',
        'status' => StreamStatus::PROCESSING, 'meta' => [],
    ]);

    $first = "{$video->playPrefix()}/a.m3u8";
    $second = "{$video->playPrefix()}/b.m3u8";
    Storage::disk('s3')->put($first, 'ORIGINAL-A');
    Storage::disk('s3')->put($second, 'ORIGINAL-B');

    $dir = sys_get_temp_dir().'/publish-'.Str::random(6);
    mkdir("{$dir}/{$stream->ulid}", 0755, true);
    file_put_contents("{$dir}/{$stream->ulid}/1.vtt", 'cue');
    file_put_contents("{$dir}/raw.vtt", 'WEBVTT');

    $publish = fn () => app(TrackPublisher::class)->publish($video, [[
        'stream' => $stream,
        'path' => "{$video->ulid}/subtitle/".Str::ulid().'.vtt',
        'outputs' => [],
        'raw' => "{$dir}/raw.vtt",
    ]], $dir, [
        $first => [fn () => 'EDITED-A'],
        $second => [fn () => throw new RuntimeException('S3 hiccup')],
    ]);

    expect($publish)->toThrow(RuntimeException::class, 'S3 hiccup');

    expect(Storage::disk('s3')->get($first))->toBe('ORIGINAL-A')
        ->and(Storage::disk('s3')->get($second))->toBe('ORIGINAL-B')
        ->and(Storage::disk('s3')->allFiles($stream->segmentsPath($video)))->toBe([])
        ->and(Storage::disk('s3')->allFiles($video->downloadPrefix()))->toBe([])
        ->and($stream->fresh()->status)->toBe(StreamStatus::PROCESSING);
});
