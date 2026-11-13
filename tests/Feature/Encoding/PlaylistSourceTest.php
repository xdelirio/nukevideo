<?php

/**
 * A playlist holds no media: it lists other things to read, by path or URL, and ffmpeg follows
 * the list. Uploaded as a video — or as an "audio track" — it would have a worker open local
 * files and request addresses inside the network, and encode what it found into the uploader's
 * own video. ffmpeg picks its reader from the content, so that is what is judged, not the name.
 */

use App\Support\MediaSource;

it('recognises a playlist by its first bytes', function (string $head) {
    expect(MediaSource::isPlaylist($head))->toBeTrue();
})->with([
    'an HLS playlist' => "#EXTM3U\n#EXT-X-VERSION:3\n#EXTINF:10,\nhttp://10.0.0.5:9000/internal\n",
    'HLS behind a BOM and blank lines' => "\xEF\xBB\xBF\n\n#EXTM3U\n",
    'a DASH manifest' => '<?xml version="1.0"?><MPD xmlns="urn:mpeg:dash:schema:mpd:2011" type="static">',
    'a DASH manifest, lowercase' => "<?xml version=\"1.0\"?>\n<mpd>",
    'an ffmpeg concat script' => "ffconcat version 1.0\nfile '/var/www/html/storage/app/other/source.mp4'\n",
    'an IMF composition' => '<?xml version="1.0"?><CompositionPlaylist xmlns="http://www.smpte-ra.org/schemas/2067-3/2016">',
    'a Smooth Streaming manifest' => '<SmoothStreamingMedia MajorVersion="2">',
]);

it('lets media through', function (string $head) {
    expect(MediaSource::isPlaylist($head))->toBeFalse();
})->with([
    'an MP4' => "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41",
    'a Matroska file' => "\x1A\x45\xDF\xA3\x9F\x42\x86\x81\x01",
    'a transport stream' => "\x47\x40\x00\x10\x00",
    'a subtitle that mentions a playlist' => "WEBVTT\n\n00:01.000 --> 00:02.000\n#EXTM3U is a tag\n",
    'an empty file' => '',
]);

it('reads the head of a file, and nothing of one that is not there', function () {
    $path = tempnam(sys_get_temp_dir(), 'head');
    file_put_contents($path, "#EXTM3U\n".str_repeat('x', 10000));

    expect(MediaSource::head($path))->toHaveLength(4096)->toStartWith('#EXTM3U')
        ->and(MediaSource::head($path.'.missing'))->toBe('');

    unlink($path);
});
