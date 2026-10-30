<?php

namespace App\Services;

use App\Jobs\AddTrackJob;
use App\Models\Output;
use App\Models\Stream;
use App\Models\Video;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Surgically edits a completed video's CMAF manifests on primary S3 so they stay consistent when a
 * stream is removed — without re-running the packager. Every stream is packaged into a directory
 * named by its ULID ({@see PackagerCommandBuilder}), so each variant is located by that ULID in the
 * manifests (HLS `URI`, DASH `SegmentTemplate@media`). Manifests are KB, so this runs inline.
 * Subtitles are packaged single-segment in a separate run and grafted into the manifests at package
 * time ({@see importDashSubtitles}, {@see hlsAddSubtitles}); this class also removes them afterwards.
 */
class ManifestEditor
{
    private const DASH_NS = 'urn:mpeg:dash:schema:mpd:2011';

    private const ROLE_SCHEME = 'urn:mpeg:dash:role:2011';

    /** The only `<Role>` values {@see relabelStream} owns; anything else on the set is left alone. */
    private const TEXT_ROLES = ['subtitle', 'caption', 'forced-subtitle'];

    /** Rendered comma-separated into HLS `CHARACTERISTICS`, mirroring what the packager emits from
     *  the semicolon-separated `hls_characteristics` descriptor field. */
    private const CAPTION_CHARACTERISTICS = [
        'public.accessibility.transcribes-spoken-dialog',
        'public.accessibility.describes-music-and-sound',
    ];

    /**
     * Re-uploads must keep the manifest's Content-Type or the VOD edge's `secure_token` module
     * (which only rewrites bodies of these types) stops signing segment URLs → players 403. Flysystem
     * would otherwise sniff `text/xml`/`text/plain`. Mirrors what `s5cmd sync` sets by extension.
     */
    private const CONTENT_TYPES = [
        'dash' => 'application/dash+xml',
        'hls' => 'application/vnd.apple.mpegurl',
    ];

    /** The Content-Type a manifest of `$format` (`hls`|`dash`) must be written with ({@see CONTENT_TYPES}). */
    public static function contentType(string $format): string
    {
        return self::CONTENT_TYPES[$format];
    }

    /**
     * Drop a stream from every manifest that references it and delete its packaged segment tree.
     * Manifests are scoped per output ({@see Output::manifestFile}) and a stream can be
     * attached to more than one output (the same rendition reused across outputs that share a
     * config), so the relevant manifest set is gathered per output the stream actually belongs to —
     * never deleted outright as a file, only edited in place, or a cap still serving another
     * stream/output would vanish. Subtitles aren't pivoted to outputs (they're grafted into every
     * output's manifest at package time), so removal walks every output of the video instead. Call
     * before deleting the DB row so heights resolve.
     */
    public function removeStream(Video $video, Stream $stream): void
    {
        $disk = Storage::disk('s3');
        $ulid = $stream->ulid;
        $segmentsPath = $stream->segmentsPath($video);

        if ($stream->type === 'subtitle') {
            foreach ($video->outputs as $output) {
                foreach ($this->existingManifests($video, $output) as $manifest) {
                    $content = $disk->get($manifest['path']);
                    $edited = $manifest['format'] === 'dash'
                        ? $this->dashRemoveSubtitle($content, $ulid)
                        : $this->hlsRemoveSubtitle($content, $ulid);

                    if ($edited !== null && $edited !== $content) {
                        $disk->put($manifest['path'], $edited, ['ContentType' => self::CONTENT_TYPES[$manifest['format']]]);
                    }
                }
            }

            $disk->deleteDirectory($segmentsPath);

            return;
        }

        $height = $stream->type === 'video' ? (int) $stream->height : null;

        foreach ($stream->outputs as $output) {
            foreach ($this->existingManifests($video, $output) as $manifest) {
                ['cap' => $cap, 'format' => $format, 'path' => $path] = $manifest;

                if ($height !== null && $cap !== null && $cap < $height) {
                    continue; // this manifest caps below the rendition; it never listed it
                }

                $content = $disk->get($path);

                $edited = $format === 'dash'
                    ? $this->dashRemove($content, $ulid)
                    : $this->hlsRemove($content, $ulid);

                if ($edited !== null && $edited !== $content) {
                    $disk->put($path, $edited, ['ContentType' => self::CONTENT_TYPES[$format]]);
                }
            }
        }

        $disk->deleteDirectory($segmentsPath);
    }

    /**
     * Rewrite one stream's presentation — label, language, forced flag — in every manifest that
     * lists it, the counterpart to {@see removeStream}. Only audio and text tracks carry any of
     * this in a manifest; a video rendition has no label, language or role
     * ({@see PackagerCommandBuilder::streamDescriptor}).
     *
     * `$fields` narrows the write to what the user actually edited, because the packager normalizes
     * languages to their shortest form: a source tagged `eng` is emitted as `lang="en"`, so
     * rewriting a language nobody touched would drift the manifest back to the raw container value.
     * Idempotent, so a retry after a partial failure converges. The segments' own `mdhd`/`elng`
     * language is left alone — players read the manifest, so an edited package is simply not
     * byte-identical to a repackaged one.
     *
     * @param  list<string>  $fields  any of `name`, `language`, `forced`, `hearing_impaired`
     */
    public function relabelStream(Video $video, Stream $stream, array $fields): void
    {
        if ($fields === []) {
            return;
        }

        $disk = Storage::disk('s3');

        // Same scoping as removeStream: subtitles aren't pivoted to outputs, they're grafted into
        // every output's manifest.
        $outputs = $stream->type === 'subtitle' ? $video->outputs : $stream->outputs;

        foreach ($outputs as $output) {
            foreach ($this->existingManifests($video, $output) as $manifest) {
                ['format' => $format, 'path' => $path] = $manifest;

                $content = $disk->get($path);

                // Null already means "nothing to write" — a string compare can't stand in for it
                // here, since saveXML() re-indents the whole document.
                $edited = $format === 'dash'
                    ? $this->dashRelabel($content, $stream, $fields)
                    : $this->hlsRelabel($content, $stream, $fields);

                if ($edited !== null) {
                    $disk->put($path, $edited, ['ContentType' => self::CONTENT_TYPES[$format]]);
                }
            }
        }
    }

    /**
     * Graft the packager-generated text AdaptationSet(s) from a throwaway subtitles MPD into a real
     * manifest's `<Period>`. Subtitles are packaged in a separate single-segment run
     * ({@see PackagerCommandBuilder::buildText}), so the imported set is *fragmented*
     * text (`SegmentTemplate`) that dashjs plays — a raw `<BaseURL>` VTT would make it deref null and
     * crash. Renumbers ids so they don't collide with the video/audio sets. Idempotent (skips a track
     * already present). Returns null if nothing changed. Labels come from the DB, not the manifest.
     */
    public function importDashSubtitles(string $masterXml, string $subsXml): ?string
    {
        return $this->importDashSets($masterXml, $subsXml, 'text');
    }

    /**
     * Graft the audio AdaptationSet a single-track run wrote ({@see PackagerCommandBuilder::buildAudio})
     * into a published manifest, for a track added after the video completed. Same renumbering and
     * idempotency as {@see importDashSubtitles}. The Period's duration is the video's already, and
     * the added audio is fitted to it before packaging, so nothing else in the MPD has to move.
     */
    public function importDashAudio(string $masterXml, string $audioXml): ?string
    {
        return $this->importDashSets($masterXml, $audioXml, 'audio');
    }

    /** {@see importDashSubtitles}, for the sets of one `contentType`. */
    private function importDashSets(string $masterXml, string $sourceXml, string $contentType): ?string
    {
        [$doc, $xpath] = $this->loadMpd($masterXml);
        [$sourceDoc, $sourceXpath] = $this->loadMpd($sourceXml);

        if (! $doc || ! $sourceDoc) {
            return null;
        }

        $period = $xpath->query('//m:Period')->item(0);

        if (! $period instanceof DOMElement) {
            return null;
        }

        $nextSetId = $this->maxNumericId($xpath, '//m:AdaptationSet') + 1;
        $nextRepId = $this->maxNumericId($xpath, '//m:Representation') + 1;
        $changed = false;

        foreach ($sourceXpath->query("//m:AdaptationSet[@contentType='{$contentType}']") as $set) {
            $tpl = $sourceXpath->query('.//m:SegmentTemplate', $set)->item(0);

            if (! $tpl instanceof DOMElement || ! preg_match('#^([^/]+)/#', $tpl->getAttribute('media'), $m)) {
                continue;
            }

            // Idempotent: this track's segments are already referenced by a set of this type.
            if ($xpath->query("//m:AdaptationSet[@contentType='{$contentType}']//m:SegmentTemplate[contains(@media, '{$m[1]}/')]")->length) {
                continue;
            }

            $imported = $doc->importNode($set, true);

            if (! $imported instanceof DOMElement) {
                continue;
            }

            $imported->setAttribute('id', (string) $nextSetId++);

            foreach (iterator_to_array($imported->getElementsByTagNameNS(self::DASH_NS, 'Representation')) as $rep) {
                $rep->setAttribute('id', (string) $nextRepId++);
            }

            $period->appendChild($imported);
            $changed = true;
        }

        return $changed ? $doc->saveXML() : null;
    }

    /** Largest numeric `id` attribute among the nodes matched by `$query`, or -1 if none. */
    private function maxNumericId(DOMXPath $xpath, string $query): int
    {
        $max = -1;

        foreach ($xpath->query($query) as $node) {
            if ($node instanceof DOMElement && is_numeric($id = $node->getAttribute('id'))) {
                $max = max($max, (int) $id);
            }
        }

        return $max;
    }

    /**
     * The manifest set actually present on S3 for one output: its own master plus one capped
     * manifest per non-max video height among its own video streams, in each format the packager
     * emitted. Probes S3 so DASH-only outputs (no `.m3u8`) and absent caps are skipped without
     * knowing the output's codecs.
     *
     * @return list<array{cap:?int,format:string,path:string}>
     */
    public function existingManifests(Video $video, Output $output): array
    {
        $output->loadMissing('streams');
        $disk = Storage::disk('s3');

        $heights = $output->streams
            ->where('type', 'video')
            ->pluck('height')
            ->filter()
            ->map(fn ($h) => (int) $h)
            ->unique()
            ->sort()
            ->values();

        $max = $heights->last();
        $caps = collect([null])->merge($heights->reject(fn ($h) => $h === $max)->values());

        $files = [];

        foreach ($caps as $cap) {
            foreach (['hls', 'dash'] as $format) {
                $path = "{$video->playPrefix()}/".$output->manifestFile($format, $cap);

                if ($disk->exists($path)) {
                    $files[] = ['cap' => $cap, 'format' => $format, 'path' => $path];
                }
            }
        }

        return $files;
    }

    // --- Subtitle removal --------------------------------------------------

    /** Drop the text `<AdaptationSet>` whose segments live under `$ulid/`. Null if not present. */
    private function dashRemoveSubtitle(string $xml, string $ulid): ?string
    {
        [$doc, $xpath] = $this->loadMpd($xml);

        if (! $doc) {
            return null;
        }

        $tpl = $xpath->query(
            "//m:AdaptationSet[@contentType='text']//m:SegmentTemplate[contains(@media, '{$ulid}/')]"
        )->item(0);

        if (! $tpl instanceof DOMElement) {
            return null;
        }

        $set = $tpl->parentNode->parentNode; // SegmentTemplate → Representation → AdaptationSet

        if (! $set instanceof DOMElement) {
            return null;
        }

        $set->parentNode->removeChild($set);

        return $doc->saveXML();
    }

    /**
     * Drop the `#EXT-X-MEDIA:TYPE=SUBTITLES` line for `$ulid`'s playlist. If no other subtitle
     * groups remain, also strips the `SUBTITLES=` attribute from every `#EXT-X-STREAM-INF` line.
     * Null if nothing was removed.
     */
    private function hlsRemoveSubtitle(string $content, string $ulid): ?string
    {
        $playlist = "{$ulid}/index.m3u8";
        $lines = preg_split('/\R/', $content);
        $removedGroup = null;
        $out = [];

        foreach ($lines as $line) {
            if (str_starts_with($line, '#EXT-X-MEDIA')
                && str_contains($line, 'TYPE=SUBTITLES')
                && str_contains($line, "URI=\"{$playlist}\"")) {
                if (preg_match('/GROUP-ID="([^"]+)"/', $line, $g)) {
                    $removedGroup = $g[1];
                }

                continue; // drop this EXT-X-MEDIA line
            }

            $out[] = $line;
        }

        if ($removedGroup === null) {
            return null;
        }

        $hasOtherSubs = collect($out)->contains(
            fn ($l) => str_starts_with($l, '#EXT-X-MEDIA') && str_contains($l, 'TYPE=SUBTITLES')
        );

        if (! $hasOtherSubs) {
            $out = array_map(
                fn ($l) => str_starts_with($l, '#EXT-X-STREAM-INF')
                    ? preg_replace('/,SUBTITLES="[^"]*"/', '', $l)
                    : $l,
                $out,
            );
        }

        return implode("\n", $out);
    }

    // --- DASH (.mpd) -------------------------------------------------------

    /** Remove the Representation packaged under `$ulid`, dropping its empty AdaptationSet or
     *  recomputing the video set's max dimensions. Returns null if the ULID isn't present. */
    private function dashRemove(string $xml, string $ulid): ?string
    {
        [$doc, $xpath] = $this->loadMpd($xml);

        if (! $doc) {
            return null;
        }

        $tpl = $this->segmentTemplate($xpath, $ulid);

        if (! $tpl) {
            return null;
        }

        $rep = $tpl->parentNode;
        $set = $rep->parentNode;
        $set->removeChild($rep);

        if ($xpath->query('m:Representation', $set)->length === 0) {
            $set->parentNode->removeChild($set);
        } elseif ($set instanceof DOMElement && $set->hasAttribute('maxWidth')) {
            $this->recomputeVideoMaxes($xpath, $set);
        }

        return $doc->saveXML();
    }

    // --- DASH relabel ------------------------------------------------------

    /**
     * Rewrite the `AdaptationSet` packaged under this stream's ulid. Returns null when the manifest
     * doesn't list the stream or when nothing would change.
     *
     * @param  list<string>  $fields
     */
    private function dashRelabel(string $xml, Stream $stream, array $fields): ?string
    {
        [$doc, $xpath] = $this->loadMpd($xml);

        if (! $doc) {
            return null;
        }

        $set = $this->segmentTemplate($xpath, $stream->ulid)?->parentNode?->parentNode;

        if (! $set instanceof DOMElement || $set->localName !== 'AdaptationSet') {
            return null;
        }

        // A set holding several renditions was merged by the packager (same language and label —
        // what uniqueName() exists to prevent); its lang/label describe all of them, so editing one
        // stream through it would silently relabel the siblings too.
        if ($xpath->query('m:Representation', $set)->length > 1) {
            Log::warning('Skipping relabel of a shared AdaptationSet', ['stream' => $stream->id]);

            return null;
        }

        $changed = false;

        if (in_array('language', $fields, true)) {
            $changed = $this->dashSetLanguage($set, $stream->language) || $changed;
        }

        if (in_array('name', $fields, true)) {
            $changed = $this->dashSetLabel($doc, $xpath, $set, (string) $stream->name) || $changed;
        }

        // Both flags resolve into the same single Role ({@see textRole}), so either edit rewrites it.
        if ($stream->type === 'subtitle' && array_intersect(['forced', 'hearing_impaired'], $fields) !== []) {
            $changed = $this->dashSetTextRole($doc, $xpath, $set, $stream) || $changed;
        }

        return $changed ? $doc->saveXML() : null;
    }

    private function dashSetLanguage(DOMElement $set, ?string $language): bool
    {
        $next = (string) $language;

        if ($set->getAttribute('lang') === $next) {
            return false;
        }

        if ($next === '') {
            $set->removeAttribute('lang');
        } else {
            $set->setAttribute('lang', $next);
        }

        return true;
    }

    /** The packager writes the label as a `<Label>` child, right before the first `<Representation>`. */
    private function dashSetLabel(DOMDocument $doc, DOMXPath $xpath, DOMElement $set, string $label): bool
    {
        $existing = $xpath->query('m:Label', $set)->item(0);

        if ($existing instanceof DOMElement) {
            if ($existing->textContent === $label) {
                return false;
            }

            $existing->textContent = $label;

            return true;
        }

        $node = $doc->createElementNS(self::DASH_NS, 'Label');
        $node->appendChild($doc->createTextNode($label));

        $this->insertBeforeRepresentations($xpath, $set, $node);

        return true;
    }

    /**
     * Keep exactly one managed `<Role>` on a text set, mirroring the single role
     * {@see PackagerCommandBuilder::textDescriptor} emits for the same flags. The
     * first managed role is edited in place so it keeps its document position; roles outside the
     * managed set aren't ours and stay.
     */
    private function dashSetTextRole(DOMDocument $doc, DOMXPath $xpath, DOMElement $set, Stream $stream): bool
    {
        $managed = [];

        foreach ($xpath->query("m:Role[@schemeIdUri='".self::ROLE_SCHEME."']", $set) as $role) {
            if ($role instanceof DOMElement && in_array($role->getAttribute('value'), self::TEXT_ROLES, true)) {
                $managed[] = $role;
            }
        }

        $first = array_shift($managed);
        $changed = $managed !== [];

        foreach ($managed as $extra) {
            $set->removeChild($extra);
        }

        $value = $this->textRole($stream);

        if ($first instanceof DOMElement) {
            if ($first->getAttribute('value') === $value) {
                return $changed;
            }

            $first->setAttribute('value', $value);

            return true;
        }

        $role = $doc->createElementNS(self::DASH_NS, 'Role');
        $role->setAttribute('schemeIdUri', self::ROLE_SCHEME);
        $role->setAttribute('value', $value);

        $this->insertBeforeRepresentations($xpath, $set, $role);

        return true;
    }

    private function insertBeforeRepresentations(DOMXPath $xpath, DOMElement $set, DOMElement $node): void
    {
        $first = $xpath->query('m:Representation', $set)->item(0);

        if ($first instanceof DOMElement) {
            $set->insertBefore($node, $first);

            return;
        }

        $set->appendChild($node);
    }

    /** @see PackagerCommandBuilder::textDescriptor — a forced track wins over SDH. */
    private function textRole(Stream $stream): string
    {
        if ($stream->forced) {
            return 'forced-subtitle';
        }

        return data_get($stream->meta, 'hearing_impaired', false) ? 'caption' : 'subtitle';
    }

    /** @return array{0:?DOMDocument,1:?DOMXPath} */
    private function loadMpd(string $xml): array
    {
        $doc = new DOMDocument;
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;

        $prev = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (! $ok) {
            return [null, null];
        }

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('m', self::DASH_NS);

        return [$doc, $xpath];
    }

    private function segmentTemplate(DOMXPath $xpath, string $ulid): ?DOMElement
    {
        $node = $xpath->query("//m:SegmentTemplate[starts-with(@media, '{$ulid}/')]")->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    private function recomputeVideoMaxes(DOMXPath $xpath, DOMElement $set): void
    {
        $maxW = 0;
        $maxH = 0;

        foreach ($xpath->query('m:Representation', $set) as $rep) {
            if (! $rep instanceof DOMElement) {
                continue;
            }

            $maxW = max($maxW, (int) $rep->getAttribute('width'));
            $maxH = max($maxH, (int) $rep->getAttribute('height'));
        }

        if ($maxW) {
            $set->setAttribute('maxWidth', (string) $maxW);
        }

        if ($maxH && $set->hasAttribute('maxHeight')) {
            $set->setAttribute('maxHeight', (string) $maxH);
        }
    }

    // --- HLS (.m3u8) -------------------------------------------------------

    /** Drop the variant for `$ulid`: a `#EXT-X-STREAM-INF` + its URI line (video) or the
     *  `#EXT-X-MEDIA` audio line. Returns null if nothing referenced the ULID. */
    private function hlsRemove(string $content, string $ulid): ?string
    {
        $lines = preg_split('/\R/', $content);
        $out = [];
        $hit = false;

        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];
            $next = $lines[$i + 1] ?? '';

            if (str_starts_with($line, '#EXT-X-STREAM-INF') && str_starts_with(trim($next), "{$ulid}/")) {
                $i++; // also drop the following URI line
                $hit = true;

                continue;
            }

            if (str_starts_with($line, '#EXT-X-MEDIA') && str_contains($line, "URI=\"{$ulid}/")) {
                $hit = true;

                continue;
            }

            $out[] = $line;
        }

        if (! $hit) {
            return null;
        }

        // Dropping the last audio track would otherwise leave every variant pointing at an
        // AUDIO group with no members, which demuxed CMAF variants cannot fall back from.
        $hasAudio = collect($out)->contains(
            fn ($l) => str_starts_with($l, '#EXT-X-MEDIA') && str_contains($l, 'TYPE=AUDIO')
        );

        if (! $hasAudio) {
            $out = array_map(
                fn ($l) => str_starts_with($l, '#EXT-X-STREAM-INF')
                    ? $this->hlsDropAudioFromVariant($l)
                    : $l,
                $out,
            );
        }

        return implode("\n", $out);
    }

    /**
     * Strips every trace of audio from one `#EXT-X-STREAM-INF` line: the group reference AND the
     * audio entry in CODECS. The packager writes `CODECS="avc1.64001f,mp4a.40.2"` whenever the
     * output had an audio track, and RFC 8216 §4.3.4.2 requires CODECS to list what is actually
     * present — leaving `mp4a.40.2` on a package with no audio left makes AVFoundation (Safari,
     * iOS, tvOS) stall or refuse the variant outright. The video codec is always the first entry
     * shaka emits, so keeping it and dropping the rest restores a truthful list.
     */
    private function hlsDropAudioFromVariant(string $line): string
    {
        $line = (string) preg_replace('/,AUDIO="[^"]*"/', '', $line);

        return (string) preg_replace('/(CODECS=")([^",]+)[^"]*(")/', '$1$2$3', $line);
    }

    /**
     * Rewrite this stream's `#EXT-X-MEDIA` line in a master playlist. Only masters carry these
     * attributes — a media playlist (`{ulid}/index.m3u8`) holds nothing but segments. Null when the
     * stream isn't listed or nothing changed.
     *
     * @param  list<string>  $fields
     */
    private function hlsRelabel(string $content, Stream $stream, array $fields): ?string
    {
        $needle = "URI=\"{$stream->ulid}/";
        $lines = preg_split('/\R/', $content);
        $attributes = $this->hlsAttributes($stream, $fields);
        $changed = false;

        foreach ($lines as $i => $line) {
            if (! str_starts_with($line, '#EXT-X-MEDIA') || ! str_contains($line, $needle)) {
                continue;
            }

            $edited = $this->hlsSetMediaAttributes($line, $attributes);

            if ($edited !== $line) {
                $lines[$i] = $edited;
                $changed = true;
            }
        }

        return $changed ? implode("\n", $lines) : null;
    }

    /**
     * The `#EXT-X-MEDIA` attributes this edit owns, null meaning "remove". `DEFAULT` and `AUTOSELECT`
     * are deliberately absent: the packager emits `DEFAULT=NO,AUTOSELECT=YES` on every audio and text
     * track whether or not it is forced, so they aren't ours to touch. `FORCED` and `CHARACTERISTICS`
     * only ever exist in their positive form (RFC 8216 §4.3.4.1 — an absent `FORCED` means NO).
     *
     * @param  list<string>  $fields
     * @return array<string, ?string>
     */
    private function hlsAttributes(Stream $stream, array $fields): array
    {
        $attributes = [];

        if (in_array('name', $fields, true)) {
            $attributes['NAME'] = (string) $stream->name;
        }

        if (in_array('language', $fields, true)) {
            $attributes['LANGUAGE'] = $stream->language ?: null;
        }

        if ($stream->type === 'subtitle' && in_array('forced', $fields, true)) {
            $attributes['FORCED'] = $stream->forced ? 'YES' : null;
        }

        // CHARACTERISTICS follows the resolved role, which either flag can move ({@see textRole}).
        if ($stream->type === 'subtitle' && array_intersect(['forced', 'hearing_impaired'], $fields) !== []) {
            $attributes['CHARACTERISTICS'] = $this->textRole($stream) === 'caption'
                ? implode(',', self::CAPTION_CHARACTERISTICS)
                : null;
        }

        return $attributes;
    }

    /**
     * Insert/replace/remove attributes on one `#EXT-X-MEDIA` line — in place where already present,
     * appended otherwise, since RFC 8216 §4.2 makes attribute order insignificant.
     *
     * @param  array<string, ?string>  $attributes
     */
    private function hlsSetMediaAttributes(string $line, array $attributes): string
    {
        foreach ($attributes as $key => $value) {
            // Comma-anchored so LANGUAGE can't match inside ASSOC-LANGUAGE; TYPE is always the first
            // attribute, so none of ours is ever line-initial.
            $pattern = '/,'.$key.'=(?:"[^"]*"|[^,]*)/';

            if ($value === null) {
                $line = preg_replace($pattern, '', $line, 1);

                continue;
            }

            // FORCED is an enumerated-string; the rest are quoted-strings. Values can't contain a
            // quote, comma or newline ({@see \App\Data\Stream\UpdateStreamData}).
            $rendered = ",{$key}=".($key === 'FORCED' ? $value : "\"{$value}\"");

            // Callback, not a replacement string: a `$1` in a user-chosen name is not a backreference.
            $line = preg_match($pattern, $line)
                ? preg_replace_callback($pattern, fn () => $rendered, $line, 1)
                : $line.$rendered;
        }

        return $line;
    }

    /**
     * Merge the `#EXT-X-MEDIA:TYPE=SUBTITLES` lines from a throwaway subtitles master (the separate
     * subtitle run, {@see PackagerCommandBuilder::buildText}) into the real master, and
     * tag every variant with the subtitle group. The lines are shaka's own (URI points at the kept
     * `{ulid}/index.m3u8`). Idempotent per track: a line whose URI the master already references is
     * skipped, which is what lets a subtitle be added to a master that already carries some
     * ({@see AddTrackJob}). Null if nothing merged.
     */
    public function hlsAddSubtitles(string $masterContent, string $subsMaster): ?string
    {
        $media = [];
        $group = null;

        foreach (preg_split('/\R/', $subsMaster) as $line) {
            if (! str_starts_with($line, '#EXT-X-MEDIA') || ! str_contains($line, 'TYPE=SUBTITLES')) {
                continue;
            }

            if (preg_match('/URI="([^"]+)"/', $line, $uri) && str_contains($masterContent, "URI=\"{$uri[1]}\"")) {
                continue;
            }

            $media[] = $line;

            if (preg_match('/GROUP-ID="([^"]+)"/', $line, $g)) {
                $group = $g[1];
            }
        }

        if (empty($media) || $group === null) {
            return null;
        }

        return $this->hlsInsertMedia($masterContent, $media, 'SUBTITLES', function (string $line) use ($group) {
            return str_contains($line, 'SUBTITLES=') ? $line : $line.",SUBTITLES=\"{$group}\"";
        });
    }

    /**
     * Graft the audio rendition a single-track run wrote ({@see PackagerCommandBuilder::buildAudio})
     * into a published master, for a track added after the video completed. The throwaway master
     * carries the track's `#EXT-X-MEDIA` line and one audio-only variant, whose CODECS and BANDWIDTH
     * are the track's own.
     *
     * Every variant must declare every codec it may play (RFC 8216 §4.3.4.2), so the audio codec is
     * appended where missing. A master with no audio at all — a silent source — also has to gain the
     * group on every variant, and its declared peak the track's rate: those variants never counted
     * any audio. Where a group already exists the peak already counts its heaviest rendition
     * (`$groupPeak`, bits/s) and a player plays one at a time, so it only grows by what the added
     * track exceeds that by; null leaves it alone. Only one rendition of a group may be DEFAULT, so
     * the added one never is when the group has members. Idempotent by the track's URI. Null if
     * nothing changed.
     */
    public function hlsAddAudio(string $masterContent, string $audioMaster, ?int $groupPeak = null): ?string
    {
        $media = null;
        $codecs = null;
        $bandwidth = 0;

        foreach (preg_split('/\R/', $audioMaster) as $line) {
            if (str_starts_with($line, '#EXT-X-MEDIA') && str_contains($line, 'TYPE=AUDIO')) {
                $media = $line;
            } elseif (str_starts_with($line, '#EXT-X-STREAM-INF')) {
                $codecs = preg_match('/CODECS="([^"]+)"/', $line, $c) ? $c[1] : null;
                $bandwidth = preg_match('/(?<![-A-Z])BANDWIDTH=(\d+)/', $line, $b) ? (int) $b[1] : 0;
            }
        }

        if ($media === null || ! preg_match('/URI="([^"]+)"/', $media, $uri) || str_contains($masterContent, "URI=\"{$uri[1]}\"")) {
            return null;
        }

        $hasAudio = str_contains($masterContent, 'TYPE=AUDIO');

        if ($hasAudio) {
            // An enumerated-string, so not through hlsSetMediaAttributes, which quotes.
            $media = str_replace(',DEFAULT=YES', ',DEFAULT=NO', $media);
        }

        $raise = match (true) {
            ! $hasAudio => $bandwidth,
            $groupPeak !== null => max(0, $bandwidth - $groupPeak),
            default => 0,
        };

        return $this->hlsInsertMedia($masterContent, [$media], 'AUDIO', function (string $line) use ($hasAudio, $codecs, $raise) {
            if ($codecs !== null && preg_match('/CODECS="([^"]*)"/', $line, $c)) {
                $listed = array_filter(explode(',', $c[1]));
                $missing = array_diff(explode(',', $codecs), $listed);

                if ($missing !== []) {
                    $line = str_replace($c[0], 'CODECS="'.implode(',', [...$listed, ...$missing]).'"', $line);
                }
            }

            if ($raise > 0) {
                $line = preg_replace_callback(
                    '/((?<![-A-Z])BANDWIDTH|AVERAGE-BANDWIDTH)=(\d+)/',
                    fn ($m) => $m[1].'='.((int) $m[2] + $raise),
                    $line,
                );
            }

            if (! $hasAudio && ! str_contains($line, 'AUDIO=')) {
                $line .= ',AUDIO="audio"';
            }

            return $line;
        });
    }

    /**
     * Insert `$media` right after the master's last `#EXT-X-MEDIA` line of `$type` — keeping a
     * group's renditions together, in the order players list them — or before the first variant
     * when the group is new, and pass every `#EXT-X-STREAM-INF` line through `$variant`.
     *
     * @param  list<string>  $media
     * @param  callable(string):string  $variant
     */
    private function hlsInsertMedia(string $masterContent, array $media, string $type, callable $variant): ?string
    {
        $lines = preg_split('/\R/', $masterContent);
        $anchor = null;

        foreach ($lines as $i => $line) {
            if (str_starts_with($line, '#EXT-X-MEDIA') && str_contains($line, "TYPE={$type}")) {
                $anchor = $i + 1;
            }
        }

        if ($anchor === null) {
            foreach ($lines as $i => $line) {
                if (str_starts_with($line, '#EXT-X-STREAM-INF')) {
                    $anchor = $i;
                    break;
                }
            }
        }

        if ($anchor === null) {
            return null;
        }

        array_splice($lines, $anchor, 0, $media);

        return implode("\n", array_map(
            fn (string $line) => str_starts_with($line, '#EXT-X-STREAM-INF') ? $variant($line) : $line,
            $lines,
        ));
    }
}
