<?php

/**
 * A track title comes out of the uploaded container, so it is attacker-controlled text — and it
 * ends up interpolated into a shaka stream descriptor and from there into the HLS master playlist,
 * which is assembled and edited as plain text. A comma ends a descriptor field, a quote closes the
 * `NAME="…"` attribute, and a newline ends the `#EXT-X-MEDIA` line — so everything after it becomes
 * a playlist tag of its own, for every viewer of that video.
 *
 * The edit endpoint has always refused all three ({@see UpdateStreamData}); these
 * cover the ingest side, which is reachable with nothing but an upload.
 */

use App\Data\Stream\UpdateStreamData;
use App\Services\CreateVideoStreamsService;
use App\Services\PackagerCommandBuilder;
use FFMpeg\FFProbe\DataMapping\Stream as FFStream;

function ingestedName(string $title): string
{
    $stream = new FFStream(['index' => 0, 'codec_type' => 'audio', 'tags' => ['title' => $title]]);

    return (fn () => $this->baseName($stream, 'audio'))->call(new CreateVideoStreamsService);
}

it('never lets a track title carry a character that breaks the master playlist', function (string $title) {
    expect(ingestedName($title))
        ->not->toContain('"')
        ->not->toContain(',')
        ->not->toContain("\n")
        ->not->toContain("\r");
})->with([
    'a quote' => 'Comment "Director"',
    'an injected tag' => "Bad\n#EXT-X-KEY:METHOD=NONE",
    'a carriage return' => "Bad\r#EXT-X-KEY:METHOD=NONE",
    'a descriptor separator' => 'Spanish,forced',
    'all of them at once' => "a\",b\r\nc",
]);

it('keeps an honest title intact', function () {
    expect(ingestedName('Director’s commentary (Español)'))->toBe('Director’s commentary (Español)');
});

it('falls back to the media type when stripping empties the title', function () {
    expect(ingestedName('",,"'))->toBe('Audio');
});

it('strips the same characters again on the way into the packager', function () {
    // Belt and braces: the single point every packaging path funnels through, so a future writer
    // of `streams.name` cannot reopen the hole quietly.
    $sanitize = fn (string $name) => (fn () => self::descriptorLabel($name))
        ->call(new PackagerCommandBuilder('packager', 10));

    expect($sanitize("Bad\n#EXT-X-KEY:METHOD=NONE"))->toBe('Bad #EXT-X-KEY:METHOD=NONE')
        ->and($sanitize('Comment "Director"'))->toBe('Comment  Director');
});

/**
 * The language tag is the same kind of text as the title — written by whoever made the file — and
 * it travels the same road: into the comma-separated stream descriptor (`lang=…`) and on into the
 * `LANGUAGE="…"` attribute of the master. Only its first subtag used to be looked at, so
 * `en-US,init_segment=/some/path` passed as English and handed the packager fields of the
 * uploader's choosing: where to read its input from, where to write its segments.
 */
function ingestedLanguage(string $tag): ?string
{
    $stream = new FFStream(['index' => 0, 'codec_type' => 'audio', 'tags' => ['language' => $tag]]);

    return (fn () => $this->sourceLanguage($stream))->call(new CreateVideoStreamsService);
}

it('keeps the language tags a file can honestly carry', function (string $tag, ?string $kept) {
    expect(ingestedLanguage($tag))->toBe($kept);
})->with([
    ['en', 'en'],
    ['spa', 'spa'],
    ['es-419', 'es-419'],
    ['zh-Hant-TW', 'zh-Hant-TW'],
    [' pt-BR ', 'pt-BR'],
    // Surrounding whitespace is trimmed off, so nothing of it is stored.
    ["en\n", 'en'],
    ['und', null],
    ['xx', null],
    ['', null],
]);

it('drops a language tag that carries anything but a language', function (string $tag) {
    expect(ingestedLanguage($tag))->toBeNull();
})->with([
    'descriptor fields' => 'en-US,init_segment=/tmp/x.mp4,segment_template=/tmp/$Number$.m4s',
    'another input' => 'en-x,in=/var/www/html/storage/app/other/source.mp4',
    'a quote' => 'en"',
    'a playlist tag' => "en\n#EXT-X-KEY:METHOD=NONE",
    'a space inside' => 'en US',
    'an equals sign' => 'en-a=b',
    'an overlong subtag' => 'en-abcdefghi',
]);

it('refuses the same on the way into the packager, whoever wrote the column', function () {
    $language = fn (?string $value) => (fn () => self::descriptorLanguage($value))
        ->call(new PackagerCommandBuilder('packager', 10));

    expect($language('es-419'))->toBe('es-419')
        ->and($language(null))->toBe('')
        ->and($language('en-US,hls_name=PWN'))->toBe('')
        ->and($language("en\n"))->toBe('')
        ->and($language('en"'))->toBe('');
});
