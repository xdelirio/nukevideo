<?php

use App\Jobs\EncodeSidecarTracksJob;
use App\Models\Video;
use App\Services\EncodeCommandBuilder;
use App\Support\MediaDuration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;

describe('reconnect flags', function () {
    it('makes ffmpeg reconnect when the source is a presigned URL', function () {
        $command = EncodeCommandBuilder::build(
            new Collection([matrixStream(['video_codec' => 'libx264'])]),
            'https://mirror.test/source.mkv?sig=x',
            [1 => '/tmp/out.mp4'],
            0.0,
            6.0,
        );

        expect($command)->toContain('-reconnect 1')
            ->and($command)->toContain('-reconnect_on_network_error 1')
            // A connection the peer never closes (a store gone behind a tunnel) blocked ffmpeg
            // forever, past the job's timeout and through its SIGTERM; bounded, it reconnects.
            ->and($command)->toContain('-rw_timeout 30000000')
            ->and(strpos($command, '-rw_timeout'))->toBeLessThan(strpos($command, '-i '))
            // Before -i, or ffmpeg applies them to nothing.
            ->and(strpos($command, '-reconnect 1'))->toBeLessThan(strpos($command, '-i '));
    });

    it('leaves a local source alone', function () {
        $command = EncodeCommandBuilder::build(
            new Collection([matrixStream(['video_codec' => 'libx264'])]),
            '/scratch/source.mkv',
            [1 => '/tmp/out.mp4'],
        );

        expect($command)->not->toContain('-reconnect');
    });
});

describe('truncation guard', function () {
    it('flags an output that stops short of the expected duration', function () {
        Process::fake(['*ffprobe*' => Process::result("106.853000\n")]);

        expect(MediaDuration::truncated('/tmp/audio.mp4', 6831.0))->toBe(106.853);
    });

    it('accepts the rounding a real encode drifts by', function () {
        Process::fake(['*ffprobe*' => Process::result("6830.1\n")]);

        expect(MediaDuration::truncated('/tmp/audio.mp4', 6831.0))->toBeNull();
    });

    it('stays out of the way when the duration is unknown', function () {
        Process::fake(['*ffprobe*' => Process::result(output: '', errorOutput: 'boom', exitCode: 1)]);

        expect(MediaDuration::truncated('/tmp/audio.mp4', 6831.0))->toBeNull()
            ->and(MediaDuration::truncated('/tmp/audio.mp4', 0.0))->toBeNull();
    });
});

describe('audio reference duration', function () {
    $video = fn (float $duration) => (new Video)->forceFill(['duration' => $duration]);

    it('measures a track against its own end, not the container', function () use ($video) {
        // The container runs to the longest track: subtitles or a second audio track outliving
        // this one would otherwise make a complete encode look 55s short.
        $stream = matrixStream([], 'audio', ['index' => 1, 'source_end' => 6684.6]);

        expect(EncodeSidecarTracksJob::expectedSeconds($stream, $video(6740.4)))->toBe(6684.6);
    });

    it('falls back to the container for a source probed before per-track ends existed', function () use ($video) {
        expect(EncodeSidecarTracksJob::expectedSeconds(matrixStream([], 'audio', ['index' => 1]), $video(6740.4)))
            ->toBe(6740.4)
            ->and(EncodeSidecarTracksJob::expectedSeconds(matrixStream([], 'audio', ['index' => 1, 'source_end' => null]), $video(6740.4)))
            ->toBe(6740.4);
    });
});
