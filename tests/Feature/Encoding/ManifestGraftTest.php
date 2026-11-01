<?php

/**
 * Grafting a track packaged on its own into manifests that are already published
 * ({@see AddTrackJob}). The throwaway masters below are what shaka-packager v3.7.2
 * writes for a single audio input and a single text input; the real masters are the shared
 * fixtures from tests/Pest.php.
 */

use App\Jobs\AddTrackJob;
use App\Services\ManifestEditor;

beforeEach(function () {
    $this->editor = new ManifestEditor;
    $this->ulids = ['{video}' => 'VIDEO01', '{audio}' => 'AUDIO01', '{sub}' => 'SUB01'];
});

function audioThrowawayHls(string $ulid = 'NEWAUD', string $codecs = 'mp4a.40.2', int $bandwidth = 134000): string
{
    return <<<M3U8
        #EXTM3U
        ## Generated with https://github.com/shaka-project/shaka-packager version v3.7.2-64a6a6c-release

        #EXT-X-INDEPENDENT-SEGMENTS

        #EXT-X-MEDIA:TYPE=AUDIO,URI="{$ulid}/index.m3u8",GROUP-ID="audio",LANGUAGE="en",NAME="English dub",DEFAULT=YES,AUTOSELECT=YES,CHANNELS="2"

        #EXT-X-STREAM-INF:BANDWIDTH={$bandwidth},AVERAGE-BANDWIDTH=130000,CODECS="{$codecs}",AUDIO="audio",CLOSED-CAPTIONS=NONE
        {$ulid}/index.m3u8
        M3U8;
}

function subsThrowawayHls(string $ulid): string
{
    return <<<M3U8
        #EXTM3U
        #EXT-X-MEDIA:TYPE=SUBTITLES,URI="{$ulid}/index.m3u8",GROUP-ID="subs",LANGUAGE="fr",NAME="Francais",DEFAULT=NO,AUTOSELECT=YES
        M3U8;
}

function audioThrowawayMpd(string $ulid = 'NEWAUD'): string
{
    return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <MPD xmlns="urn:mpeg:dash:schema:mpd:2011" profiles="urn:mpeg:dash:profile:isoff-live:2011" minBufferTime="PT2S" type="static" mediaPresentationDuration="PT10S">
          <Period id="0">
            <AdaptationSet id="0" contentType="audio" lang="en" segmentAlignment="true">
              <Label>English dub</Label>
              <Representation id="0" bandwidth="134000" codecs="opus" mimeType="audio/mp4" audioSamplingRate="48000">
                <AudioChannelConfiguration schemeIdUri="urn:mpeg:dash:23003:3:audio_channel_configuration:2011" value="2"/>
                <SegmentTemplate timescale="48000" initialization="{$ulid}/init.mp4" media="{$ulid}/\$Number\$.m4s" startNumber="1">
                  <SegmentTimeline><S t="0" d="480000"/></SegmentTimeline>
                </SegmentTemplate>
              </Representation>
            </AdaptationSet>
          </Period>
        </MPD>
        XML;
}

/** The shared fixture with its audio track removed, as a silent source packages. */
function silentHlsMaster(array $ulids): string
{
    $lines = array_filter(
        preg_split('/\R/', hlsFixture($ulids)),
        fn ($line) => ! str_contains($line, 'TYPE=AUDIO'),
    );

    return str_replace(',AUDIO="audio"', '', implode("\n", $lines));
}

it('adds a second subtitle to a master that already has subtitles', function () {
    $master = hlsFixture($this->ulids);

    $result = $this->editor->hlsAddSubtitles($master, subsThrowawayHls('NEWSUB'));

    expect($result)->toContain('URI="SUB01/index.m3u8"')
        ->and($result)->toContain('URI="NEWSUB/index.m3u8"')
        ->and(substr_count($result, 'SUBTITLES="subs"'))->toBe(1)
        ->and($this->editor->hlsAddSubtitles($result, subsThrowawayHls('NEWSUB')))->toBeNull();
});

it('adds an audio rendition to a master that has an audio group', function () {
    $master = str_replace('CODECS="av01.0.08M.08"', 'CODECS="av01.0.08M.08,mp4a.40.2"', hlsFixture($this->ulids));

    $result = $this->editor->hlsAddAudio($master, audioThrowawayHls());
    $lines = preg_split('/\R/', $result);
    $audioLines = array_keys(array_filter($lines, fn ($l) => str_contains($l, 'TYPE=AUDIO')));

    expect($audioLines)->toHaveCount(2)
        ->and($lines[$audioLines[1]])->toContain('URI="NEWAUD/index.m3u8"')
        // The existing track stays the default; a second DEFAULT=YES in one group is invalid.
        ->and($lines[$audioLines[1]])->toContain('DEFAULT=NO')
        ->and($audioLines[1])->toBe($audioLines[0] + 1)
        ->and($result)->toContain('CODECS="av01.0.08M.08,mp4a.40.2"')
        ->and($result)->toContain('BANDWIDTH=3050279,')
        ->and($this->editor->hlsAddAudio($result, audioThrowawayHls()))->toBeNull();
});

it('declares a codec the variants did not list yet', function () {
    // The fixture's variant lists only the video codec even though it has an audio group.
    $result = $this->editor->hlsAddAudio(hlsFixture($this->ulids), audioThrowawayHls());

    expect($result)->toContain('CODECS="av01.0.08M.08,mp4a.40.2"');
});

it('adds the audio group to a master without audio', function () {
    $result = $this->editor->hlsAddAudio(silentHlsMaster($this->ulids), audioThrowawayHls());

    expect($result)->toContain('#EXT-X-MEDIA:TYPE=AUDIO,URI="NEWAUD/index.m3u8"')
        ->and($result)->toContain('DEFAULT=YES')
        ->and($result)->toContain('AUDIO="audio"')
        ->and($result)->toContain('CODECS="av01.0.08M.08,mp4a.40.2"')
        ->and($result)->toContain('BANDWIDTH='.(3050279 + 134000).',')
        ->and($result)->toContain('AVERAGE-BANDWIDTH='.(3050279 + 134000).',');

    // The media line lands before the first variant, or players never see the group.
    expect(strpos($result, 'TYPE=AUDIO'))->toBeLessThan(strpos($result, '#EXT-X-STREAM-INF'));
});

it('imports a DASH audio set with fresh ids', function () {
    $result = $this->editor->importDashAudio(dashFixture($this->ulids), audioThrowawayMpd());

    $doc = new DOMDocument;
    $doc->loadXML($result);
    $xpath = new DOMXPath($doc);
    $xpath->registerNamespace('m', 'urn:mpeg:dash:schema:mpd:2011');

    $sets = $xpath->query('//m:AdaptationSet');
    $new = $xpath->query("//m:AdaptationSet[.//m:SegmentTemplate[contains(@media, 'NEWAUD/')]]")->item(0);

    expect($sets->length)->toBe(4)
        ->and($new->getAttribute('id'))->toBe('3')
        ->and($xpath->query('.//m:Representation', $new)->item(0)->getAttribute('id'))->toBe('3')
        ->and($this->editor->importDashAudio($result, audioThrowawayMpd()))->toBeNull();
});

it('keeps the period untouched when the audio MPD has no audio set', function () {
    $empty = str_replace('contentType="audio"', 'contentType="text"', audioThrowawayMpd());

    expect($this->editor->importDashAudio(dashFixture($this->ulids), $empty))->toBeNull();
});

it('raises the declared peak when the added audio outweighs the group', function () {
    $master = str_replace('CODECS="av01.0.08M.08"', 'CODECS="av01.0.08M.08,mp4a.40.2"', hlsFixture($this->ulids));

    // The group's heaviest rendition is 128k; the added 5.1 track peaks at 384k.
    $result = $this->editor->hlsAddAudio($master, audioThrowawayHls(bandwidth: 384000), 128000);

    expect($result)->toContain('BANDWIDTH='.(3050279 + 384000 - 128000).',');

    // A lighter rendition changes nothing.
    expect($this->editor->hlsAddAudio($master, audioThrowawayHls(bandwidth: 96000), 128000))->toContain('BANDWIDTH=3050279,');
});
