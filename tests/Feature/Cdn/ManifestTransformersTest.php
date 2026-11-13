<?php

/**
 * What a manifest becomes when a link asks for something other than what the packager wrote.
 *
 * The ladders below are real shaka-packager v3.7.2 output, trimmed: where the dimensions sit —
 * on each Representation of a ladder, on the AdaptationSet of a single rendition — is what the
 * cap has to read, and it is the packager's choice, not ours.
 */

use App\Data\Video\ManifestOptions;
use App\Enums\SubtitleFormat;
use App\Services\Manifest\MaxResolutionTransformer;
use App\Services\Manifest\SubtitleFormatTransformer;

const SUB_EN = '01M6ABN3Y5AAAAAAAAAAAAAAEN';
const SUB_ES = '01M6ABN3Y5AAAAAAAAAAAAAAES';

/** @param  list<array{0:int,1:int}>  $sizes  width × height of each rung */
function ladderMpd(array $sizes, string $extraSets = ''): string
{
    $representations = '';

    foreach ($sizes as $i => [$width, $height]) {
        $representations .= <<<XML
              <Representation id="{$i}" bandwidth="1000" codecs="avc1.64001f" mimeType="video/mp4" sar="1:1" width="{$width}" height="{$height}">
                <SegmentTemplate timescale="12288" initialization="V{$height}x{$width}/init.mp4" media="V{$height}x{$width}/\$Number\$.m4s" startNumber="1"/>
              </Representation>

        XML;
    }

    $maxWidth = max(array_column($sizes, 0));
    $maxHeight = max(array_column($sizes, 1));

    return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <MPD xmlns="urn:mpeg:dash:schema:mpd:2011" profiles="urn:mpeg:dash:profile:isoff-live:2011" minBufferTime="PT2S" type="static" mediaPresentationDuration="PT30S">
          <Period id="0">
            <AdaptationSet id="0" contentType="video" maxWidth="{$maxWidth}" maxHeight="{$maxHeight}" segmentAlignment="true" par="16:9">
        {$representations}    </AdaptationSet>
            <AdaptationSet id="1" contentType="audio" startWithSAP="1" segmentAlignment="true">
              <Label>Audio</Label>
              <Representation id="9" bandwidth="97714" codecs="mp4a.40.2" mimeType="audio/mp4" audioSamplingRate="44100">
                <SegmentTemplate timescale="44100" initialization="AUDIO/init.mp4" media="AUDIO/\$Number\$.m4s" startNumber="1"/>
              </Representation>
            </AdaptationSet>
        {$extraSets}
          </Period>
        </MPD>
        XML;
}

function textSet(string $ulid, string $language, int $id): string
{
    return <<<XML
            <AdaptationSet id="{$id}" contentType="text" lang="{$language}" segmentAlignment="true">
              <Role schemeIdUri="urn:mpeg:dash:role:2011" value="subtitle"/>
              <Label>Track {$language}</Label>
              <Representation id="{$id}" bandwidth="82" codecs="wvtt" mimeType="application/mp4">
                <SegmentTemplate timescale="1000" initialization="{$ulid}/init.mp4" media="{$ulid}/\$Number\$.m4s" startNumber="1">
                  <SegmentTimeline><S t="0" d="7490000"/></SegmentTimeline>
                </SegmentTemplate>
              </Representation>
            </AdaptationSet>
        XML;
}

/** @param  list<array{0:int,1:int}>  $sizes */
function ladderMaster(array $sizes, string $extra = ''): string
{
    $variants = '';

    foreach ($sizes as [$width, $height]) {
        $variants .= "#EXT-X-STREAM-INF:BANDWIDTH=1000,CODECS=\"avc1.64001f,mp4a.40.2\",RESOLUTION={$width}x{$height},FRAME-RATE=24.000,AUDIO=\"audio\",CLOSED-CAPTIONS=NONE\nV{$height}x{$width}/index.m3u8\n";
    }

    return "#EXTM3U\n#EXT-X-INDEPENDENT-SEGMENTS\n\n"
        ."#EXT-X-MEDIA:TYPE=AUDIO,URI=\"AUDIO/index.m3u8\",GROUP-ID=\"audio\",NAME=\"Audio\",DEFAULT=NO,AUTOSELECT=YES,CHANNELS=\"2\"\n\n"
        .$variants.$extra;
}

function capped(string $body, string $format, int $resolution): string
{
    return (new MaxResolutionTransformer)->transform($body, $format, new ManifestOptions(resolution: $resolution));
}

function asVtt(string $body, string $format = 'dash'): string
{
    return (new SubtitleFormatTransformer)->transform($body, $format, new ManifestOptions(subtitlesFormat: SubtitleFormat::VTT));
}

/** The video heights an MPD still lists, in document order. */
function mpdHeights(string $xml): array
{
    preg_match_all('/<Representation[^>]*mimeType="video\/mp4"[^>]*height="(\d+)"/', $xml, $matches);

    return array_map('intval', $matches[1]);
}

/** The heights a master playlist still lists, in order. */
function masterHeights(string $content): array
{
    preg_match_all('/^#EXT-X-STREAM-INF.*RESOLUTION=\d+x(\d+)/m', $content, $matches);

    return array_map('intval', $matches[1]);
}

const LANDSCAPE = [[640, 360], [1280, 720], [1920, 1080]];
const PORTRAIT = [[480, 854], [720, 1280], [1080, 1920]];

// --- Resolution cap ---------------------------------------------------------------------------

it('keeps the renditions at or below the cap', function (int $resolution, array $kept) {
    expect(mpdHeights(capped(ladderMpd(LANDSCAPE), 'dash', $resolution)))->toBe($kept)
        ->and(masterHeights(capped(ladderMaster(LANDSCAPE), 'hls', $resolution)))->toBe($kept);
})->with([
    'a real rung' => [720, [360, 720]],
    'between two rungs' => [900, [360, 720]],
    'the bottom rung' => [360, [360]],
]);

it('leaves a manifest alone when the cap is at or above its top rung', function (int $resolution) {
    $mpd = ladderMpd(LANDSCAPE);
    $master = ladderMaster(LANDSCAPE);

    expect(capped($mpd, 'dash', $resolution))->toBe($mpd)
        ->and(capped($master, 'hls', $resolution))->toBe($master);
})->with([1080, 2160]);

it('keeps the smallest rendition when every one is above the cap', function () {
    expect(mpdHeights(capped(ladderMpd(LANDSCAPE), 'dash', 144)))->toBe([360])
        ->and(masterHeights(capped(ladderMaster(LANDSCAPE), 'hls', 144)))->toBe([360]);
});

it('measures a portrait ladder on its short edge', function () {
    // 1080 means the 1080-wide rung in a vertical video. On height it would have kept only 854.
    expect(mpdHeights(capped(ladderMpd(PORTRAIT), 'dash', 1080)))->toBe([854, 1280, 1920])
        ->and(mpdHeights(capped(ladderMpd(PORTRAIT), 'dash', 720)))->toBe([854, 1280])
        ->and(masterHeights(capped(ladderMaster(PORTRAIT), 'hls', 720)))->toBe([854, 1280]);
});

it('restates the set maxima for the renditions that are left', function () {
    $capped = capped(ladderMpd(LANDSCAPE), 'dash', 720);

    expect($capped)->toContain('maxWidth="1280"')->toContain('maxHeight="720"')
        ->not->toContain('maxWidth="1920"');
});

it('never touches audio or text when capping', function () {
    $capped = capped(ladderMpd(LANDSCAPE, textSet(SUB_EN, 'en', 2)), 'dash', 360);

    expect($capped)->toContain('contentType="audio"')->toContain('AUDIO/init.mp4')
        ->toContain('contentType="text"')->toContain(SUB_EN.'/init.mp4');

    $master = capped(ladderMaster(LANDSCAPE), 'hls', 360);

    expect($master)->toContain('#EXT-X-MEDIA:TYPE=AUDIO')
        ->and(substr_count($master, 'index.m3u8'))->toBe(2); // the audio rendition and one variant
});

it('survives a single rendition whose dimensions sit on the set', function () {
    // What the packager writes for a one-rung ladder: no width or height on the Representation.
    $mpd = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <MPD xmlns="urn:mpeg:dash:schema:mpd:2011" type="static">
          <Period id="0">
            <AdaptationSet id="1" contentType="video" width="1920" height="1080" segmentAlignment="true" par="16:9">
              <Representation id="0" bandwidth="428880" codecs="avc1.64001e" mimeType="video/mp4" sar="1:1">
                <SegmentTemplate timescale="12288" initialization="ONLY/init.mp4" media="ONLY/$Number$.m4s" startNumber="1"/>
              </Representation>
            </AdaptationSet>
          </Period>
        </MPD>
        XML;

    expect(capped($mpd, 'dash', 360))->toBe($mpd);
});

it('drops the I-frame playlist of a dropped rung and keeps an audio-only variant', function () {
    $master = ladderMaster(LANDSCAPE,
        "#EXT-X-STREAM-INF:BANDWIDTH=97714,CODECS=\"mp4a.40.2\",AUDIO=\"audio\"\nAUDIO/index.m3u8\n"
        ."#EXT-X-I-FRAME-STREAM-INF:BANDWIDTH=100,RESOLUTION=640x360,URI=\"V360x640/iframe.m3u8\"\n"
        ."#EXT-X-I-FRAME-STREAM-INF:BANDWIDTH=300,RESOLUTION=1920x1080,URI=\"V1080x1920/iframe.m3u8\"\n");

    $capped = capped($master, 'hls', 360);

    expect($capped)->toContain('V360x640/iframe.m3u8')
        ->not->toContain('V1080x1920')
        ->toContain("CODECS=\"mp4a.40.2\",AUDIO=\"audio\"\nAUDIO/index.m3u8");
});

it('hands back what it cannot parse', function (string $body) {
    expect(capped($body, 'dash', 720))->toBe($body)
        ->and(asVtt($body))->toBe($body);
})->with(['', 'not xml at all', '<MPD><unclosed></MPD>']);

it('leaves a master playlist with no video variants alone', function () {
    $master = "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=97714,CODECS=\"mp4a.40.2\"\nAUDIO/index.m3u8\n";

    expect(capped($master, 'hls', 360))->toBe($master);
});

// --- Subtitles as sidecar WebVTT ----------------------------------------------------------------

it('points a text track at its sidecar file and keeps what a menu shows', function () {
    $mpd = asVtt(ladderMpd(LANDSCAPE, textSet(SUB_EN, 'en', 2)));

    expect($mpd)->toContain('<BaseURL>'.SUB_EN.'/sub.vtt</BaseURL>')
        ->toContain('mimeType="text/vtt"')
        ->not->toContain('wvtt')
        ->not->toContain(SUB_EN.'/init.mp4')
        // The track itself is the same track.
        ->toContain('contentType="text" lang="en"')
        ->toContain('<Label>Track en</Label>')
        ->toContain('value="subtitle"')
        // And nothing else moved.
        ->toContain('AUDIO/init.mp4')
        ->and(mpdHeights($mpd))->toBe([360, 720, 1080]);

    // Well-formed, with the BaseURL inside the Representation, where a player resolves it.
    $doc = new DOMDocument;
    expect($doc->loadXML($mpd))->toBeTrue();

    $xpath = new DOMXPath($doc);
    $xpath->registerNamespace('m', 'urn:mpeg:dash:schema:mpd:2011');

    expect($xpath->query('//m:AdaptationSet[@contentType="text"]/m:Representation/m:BaseURL')->length)->toBe(1)
        ->and($xpath->query('//m:AdaptationSet[@contentType="text"]//m:SegmentTemplate')->length)->toBe(0);
});

it('converts every text track, without asking the bucket whether its file is there', function () {
    // Nothing was put in the bucket: the sidecar is taken to exist, so a playback start pays no
    // request per track to find out.
    $mpd = asVtt(ladderMpd(LANDSCAPE, textSet(SUB_EN, 'en', 2).textSet(SUB_ES, 'es', 3)));

    expect($mpd)->toContain('<BaseURL>'.SUB_EN.'/sub.vtt</BaseURL>')
        ->toContain('<BaseURL>'.SUB_ES.'/sub.vtt</BaseURL>')
        ->not->toContain('wvtt')
        ->not->toContain(SUB_EN.'/init.mp4');
});

it('leaves a text track it cannot place alone', function () {
    // A segment path that does not start with a stream ULID names no directory to point into.
    $original = ladderMpd(LANDSCAPE, str_replace(SUB_EN, 'subs', textSet(SUB_EN, 'en', 2)));

    expect(asVtt($original))->toBe($original);
});

it('leaves a manifest with no text tracks alone', function () {
    $original = ladderMpd(LANDSCAPE);

    expect(asVtt($original))->toBe($original);
});

it('does not apply to HLS, which already carries raw WebVTT', function () {
    $options = new ManifestOptions(subtitlesFormat: SubtitleFormat::VTT);
    $master = ladderMaster(LANDSCAPE);

    expect((new SubtitleFormatTransformer)->applies($options, 'hls'))->toBeFalse()
        ->and((new SubtitleFormatTransformer)->applies($options, 'dash'))->toBeTrue()
        // The default: a link that asked for nothing gets plain WebVTT.
        ->and((new SubtitleFormatTransformer)->applies(new ManifestOptions, 'dash'))->toBeTrue()
        ->and((new SubtitleFormatTransformer)->applies(new ManifestOptions(subtitlesFormat: SubtitleFormat::SEGMENTS), 'dash'))->toBeFalse()
        ->and(asVtt($master, 'hls'))->toBe($master);
});

it('caps and converts independently of each other', function () {
    $mpd = asVtt(capped(ladderMpd(LANDSCAPE, textSet(SUB_EN, 'en', 2)), 'dash', 720));

    expect(mpdHeights($mpd))->toBe([360, 720])
        ->and($mpd)->toContain('<BaseURL>'.SUB_EN.'/sub.vtt</BaseURL>');
});
