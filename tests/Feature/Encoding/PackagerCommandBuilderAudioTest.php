<?php

/**
 * A track added after the video completed is packaged on its own, into a throwaway manifest whose
 * entries are then grafted into the published ones ({@see AddTrackJob}).
 */

use App\Jobs\AddTrackJob;
use App\Services\PackagerCommandBuilder;

beforeEach(function () {
    $this->builder = new PackagerCommandBuilder('packager', 10);
    $this->input = ['path' => '/w/in.mp4', 'type' => 'audio', 'ulid' => 'NEWAUD', 'language' => 'en', 'name' => 'English dub'];
});

it('writes a throwaway HLS master for one audio track', function () {
    $argv = $this->builder->buildAudio([$this->input], '/w', 'hls');

    expect($argv)->toContain('--hls_master_playlist_output')
        ->and($argv[array_search('--hls_master_playlist_output', $argv) + 1])->toBe('/w/'.PackagerCommandBuilder::AUDIO_HLS_MANIFEST)
        ->and($argv)->not->toContain('--mpd_output')
        ->and($argv[1])->toContain('hls_group_id=audio')
        ->and($argv[1])->toContain('playlist_name=NEWAUD/index.m3u8')
        ->and($argv)->toContain('--segment_duration')
        ->and($argv[array_search('--segment_duration', $argv) + 1])->toBe('10');
});

it('writes a throwaway MPD for one audio track', function () {
    $argv = $this->builder->buildAudio([$this->input], '/w', 'dash');

    expect($argv)->toContain('--generate_static_live_mpd')
        ->and($argv[array_search('--mpd_output', $argv) + 1])->toBe('/w/'.PackagerCommandBuilder::AUDIO_DASH_MANIFEST)
        ->and($argv)->not->toContain('--hls_master_playlist_output')
        ->and($argv[1])->not->toContain('hls_group_id');
});

it('carries the audio description role', function () {
    $argv = $this->builder->buildAudio([[...$this->input, 'visual_impaired' => true]], '/w', 'dash');

    expect($argv[1])->toContain('dash_roles=description');
});

it('refuses anything but audio', function () {
    $this->builder->buildAudio([[...$this->input, 'type' => 'video']], '/w', 'dash');
})->throws(InvalidArgumentException::class);
