<?php

/**
 * A failure reason must read as a cause wherever it lands: the activity log, a stream's
 * `error_log`, the webhook. A process exception opens with the whole command line, and keeping its
 * first thousand characters kept five kilobytes of ffmpeg flags and a presigned URL, and cut the
 * "exceeded the timeout" that said what happened.
 */

use App\Models\Video;

it('keeps what happened to a timed-out command, not the command', function () {
    $flags = str_repeat('-c:a libopus -ac 2 -b:a 128k -map 0:1 -f mp4 "/tmp/out.mp4" ', 80);
    $message = 'The process "ffmpeg -hide_banner -y -i "http://10.10.0.1:9009/nukevideo/x/source/original.mkv'
        .'?X-Amz-Credential=AKIAEXAMPLE%2F20261124&X-Amz-Signature=abc" '.$flags.'" exceeded the timeout of 1679 seconds.';

    expect(Video::trimReason($message))->toBe('The process "ffmpeg" exceeded the timeout of 1679 seconds.');
});

it('keeps the error output of a failed command', function () {
    $message = "The command \"ffprobe -v error -i 'http://store/x.mkv?sig=1'\" failed.\n\nExit Code: 1(General error)\n\n"
        ."Error Output:\n================\nInvalid data found when processing input";

    expect(Video::trimReason($message))
        ->toStartWith('The command "ffprobe" failed.')
        ->toContain('Invalid data found when processing input');
});

it('replaces any URL, wherever it is', function () {
    expect(Video::trimReason('Preparation failed: could not open https://s3.example.com/b/k?X-Amz-Signature=abc for reading'))
        ->toBe('Preparation failed: could not open <url> for reading');
});

it('is null when there is nothing to say', function () {
    expect(Video::trimReason('  '))->toBeNull()
        ->and(Video::trimReason(null))->toBeNull();
});
