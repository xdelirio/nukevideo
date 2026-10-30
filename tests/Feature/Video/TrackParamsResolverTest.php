<?php

/**
 * An audio track added after completion is encoded the way the template would have encoded it at
 * ingest, per output ({@see TrackParamsResolver}): same channel ladder, same bitrate cap, and never
 * a codec the output's existing audio group does not already use.
 */

use App\Models\Output;
use App\Models\Project;
use App\Models\Stream;
use App\Models\Template;
use App\Models\User;
use App\Services\TrackParamsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

const AAC_LADDER = ['audio_codec' => 'aac', 'channels' => [
    ['channels' => '2', 'audio_bitrate' => '128k'],
    ['channels' => '6', 'audio_bitrate' => '384k'],
]];

beforeEach(function () {
    $this->project = Project::factory()->for(User::factory())->create();
    $this->video = projectVideo($this->project);

    /** One output per template entry, each with a 720p H.264 rendition and its packaged formats. */
    $this->withOutputs = function (array $entries, array $formats = ['hls', 'dash']) {
        $this->video->template->update(['query' => ['outputs' => array_map(
            fn ($audio) => array_filter(['variants' => [['width' => 1280, 'height' => 720]], 'audio' => $audio]),
            $entries,
        )]]);

        foreach (array_keys($entries) as $index) {
            $output = Output::create([
                'video_id' => $this->video->id,
                'template_index' => $index,
                'status' => 'completed',
            ]);
            $output->recordFormats($formats);
            $output->streams()->attach(($this->stream)('video', ['video_codec' => 'libx264'])->id);
        }

        return $this->video->fresh()->outputs()->orderBy('id')->get();
    };

    $this->stream = fn (string $type, array $params, array $extra = []) => Stream::create([
        'video_id' => $this->video->id,
        'path' => "{$this->video->ulid}/{$type}/".Str::random(8).'.mp4',
        'type' => $type,
        'input_params' => $params,
        'meta' => [],
        ...$extra,
    ]);
});

function resolveAudio($video, int $channels = 2, ?int $rate = null, ?string $codec = 'pcm_s16le'): array
{
    return app(TrackParamsResolver::class)->forAudio($video->fresh(), $channels, $codec, null, $rate);
}

it('builds the template ladder for a 5.1 source', function () {
    ($this->withOutputs)([AAC_LADDER]);

    $entries = resolveAudio($this->video, 6);

    expect(array_column($entries, 'channels'))->toBe([2, 6])
        ->and($entries[0]['params'])->toMatchArray(['audio_codec' => 'aac', 'audio_bitrate' => '128k', 'channels' => '2'])
        ->and($entries[1]['params'])->toMatchArray(['audio_bitrate' => '384k', 'channels' => '6'])
        ->and($entries[0]['formats'])->toBe(['hls', 'dash']);
});

it('never upmixes', function () {
    ($this->withOutputs)([AAC_LADDER]);

    expect(array_column(resolveAudio($this->video, 2), 'channels'))->toBe([2]);
});

it('caps bitrate to the source', function () {
    ($this->withOutputs)([AAC_LADDER]);

    expect(resolveAudio($this->video, 2, 96_000, 'mp3')[0]['params']['audio_bitrate'])->toBe('96k');
});

it('keeps the existing codec when the template changed codec since encoding', function () {
    $outputs = ($this->withOutputs)([['audio_codec' => 'libopus', 'channels' => [['channels' => '2', 'audio_bitrate' => '96k']]]]);
    $existing = ($this->stream)('audio', ['audio_codec' => 'aac', 'audio_bitrate' => '128k', 'channels' => '2']);
    $outputs[0]->streams()->attach($existing->id);

    $entries = resolveAudio($this->video, 2);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]['params'])->toMatchArray(['audio_codec' => 'aac', 'audio_bitrate' => '128k', 'channels' => '2']);
});

it('skips an output whose template entry has no audio block', function () {
    $outputs = ($this->withOutputs)([AAC_LADDER, null]);

    $entries = resolveAudio($this->video, 2);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]['output']->id)->toBe($outputs[0]->id);
});

it('falls back to existing streams when the template is gone', function () {
    $outputs = ($this->withOutputs)([AAC_LADDER]);
    $existing = ($this->stream)('audio', ['audio_codec' => 'aac', 'audio_bitrate' => '160k', 'channels' => '2']);
    $outputs[0]->streams()->attach($existing->id);
    Template::whereKey($this->video->template_id)->delete();

    $entries = resolveAudio($this->video, 2);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]['params'])->toMatchArray(['audio_codec' => 'aac', 'audio_bitrate' => '160k', 'channels' => '2']);
});

it('drops HLS for an opus track on an HLS+DASH output', function () {
    ($this->withOutputs)([['audio_codec' => 'libopus', 'channels' => [['channels' => '2', 'audio_bitrate' => '96k']]]]);

    expect(resolveAudio($this->video, 2)[0]['formats'])->toBe(['dash']);
});

it('drops an output whose formats would be empty', function () {
    ($this->withOutputs)([['audio_codec' => 'libopus', 'channels' => [['channels' => '2']]]], ['hls']);

    expect(resolveAudio($this->video, 2))->toBe([])
        ->and(app(TrackParamsResolver::class)->canTakeAudio($this->video->fresh()))->toBeFalse();
});

it('says a video can take audio when one output resolves', function () {
    ($this->withOutputs)([AAC_LADDER]);

    expect(app(TrackParamsResolver::class)->canTakeAudio($this->video->fresh()))->toBeTrue();
});

it('reads the template version the video was encoded with, not the template as edited since', function () {
    ($this->withOutputs)([AAC_LADDER]);
    $this->video->update(['template_version_id' => $this->video->template->fresh()->current_version_id]);

    // Edited after the video was encoded: a silent master, so nothing else pins the codec.
    $this->video->template->update(['query' => ['outputs' => [[
        'variants' => [['width' => 1280, 'height' => 720]],
        'audio' => ['audio_codec' => 'libopus', 'channels' => [['channels' => '2', 'audio_bitrate' => '96k']]],
    ]]]]);

    $entries = resolveAudio($this->video, 2);

    expect($entries[0]['params']['audio_codec'])->toBe('aac')
        ->and($entries[0]['formats'])->toBe(['hls', 'dash']);
});
