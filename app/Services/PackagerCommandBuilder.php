<?php

namespace App\Services;

use App\Jobs\AddTrackJob;
use App\Jobs\PackageVideoJob;
use App\Models\Output;
use App\Models\Stream;
use InvalidArgumentException;

/**
 * Builds the shaka-packager argv for one output: every rendition becomes a CMAF stream sharing
 * one segment tree, and a manifest is emitted per requested format over those same segments.
 * Returned as an argv list (never a shell string) so `$Number$` and paths need no quoting.
 */
class PackagerCommandBuilder
{
    /** Throwaway subtitle-only manifest names ({@see buildText}); never a real output's filename
     *  ({@see Output::manifestFile} always names a real one after the output's own ulid),
     *  so {@see PackageVideoJob::packageSubtitles} can glob for "every manifest but these". */
    public const SUBS_DASH_MANIFEST = '_subs.mpd';

    public const SUBS_HLS_MANIFEST = '_subs.m3u8';

    /** Throwaway manifests of a single-track audio run ({@see buildAudio}); underscored for the same
     *  reason as the subtitle ones. */
    public const AUDIO_DASH_MANIFEST = '_audio.mpd';

    public const AUDIO_HLS_MANIFEST = '_audio.m3u8';

    public function __construct(
        private string $bin,
        private int $segmentDuration,
    ) {}

    /** The builder every packager run uses: the configured binary and segment duration. */
    public static function fromConfig(): self
    {
        return new self((string) config('packager.bin'), (int) config('packager.segment_duration'));
    }

    /**
     * Build one packager run. `$cap` names the manifest set: null is the full master, a height
     * (e.g. 720) yields a capped manifest listing only the renditions passed in. Every run writes
     * the same per-stream segment tree, so all manifests of this output share the segments. The
     * manifest filename is keyed by `$output`'s own ulid ({@see Output::manifestFile})
     * so it never collides with another output of the same video.
     *
     * @param  list<array{path:string,type:string,ulid:string,language?:?string,forced?:bool,hearing_impaired?:bool,visual_impaired?:bool,name?:?string}>  $inputs
     * @param  list<string>  $formats  any of 'hls', 'dash'
     * @return list<string>
     */
    public function build(array $inputs, string $outputDir, array $formats, Output $output, ?int $cap = null): array
    {
        $args = [$this->bin];

        foreach ($inputs as $input) {
            $args[] = $this->streamDescriptor($input, $outputDir, $formats);
        }

        $args[] = '--segment_duration';
        $args[] = (string) $this->segmentDuration;

        if (in_array('hls', $formats, true)) {
            $args[] = '--hls_master_playlist_output';
            $args[] = "{$outputDir}/".$output->manifestFile('hls', $cap);
            $args[] = '--hls_playlist_type';
            $args[] = 'VOD';
        }

        if (in_array('dash', $formats, true)) {
            $args[] = '--generate_static_live_mpd';
            $args[] = '--mpd_output';
            $args[] = "{$outputDir}/".$output->manifestFile('dash', $cap);
        }

        return $args;
    }

    /**
     * Package audio tracks on their own, in ONE format, for a video whose manifests are already
     * published ({@see AddTrackJob}). The segments land exactly where the main run puts
     * them (`{ulid}/init.mp4`, `{ulid}/$Number$.m4s`, `{ulid}/index.m3u8`), with the same segment
     * duration; only the manifest is a throwaway, whose entries are grafted into the real ones
     * ({@see ManifestEditor::hlsAddAudio}, {@see ManifestEditor::importDashAudio}). One format per
     * run because each published output has its own set: a DASH-only output must not be handed an
     * HLS rendition list it never serves.
     *
     * @param  list<array{path:string,type:string,ulid:string,language?:?string,hearing_impaired?:bool,visual_impaired?:bool,name?:?string}>  $inputs
     * @return list<string>
     */
    public function buildAudio(array $inputs, string $outputDir, string $format): array
    {
        $args = [$this->bin];

        foreach ($inputs as $input) {
            if ($input['type'] !== 'audio') {
                throw new InvalidArgumentException("buildAudio packages audio only, got {$input['type']}");
            }

            $args[] = $this->streamDescriptor($input, $outputDir, [$format]);
        }

        $args[] = '--segment_duration';
        $args[] = (string) $this->segmentDuration;

        if ($format === 'hls') {
            $args[] = '--hls_master_playlist_output';
            $args[] = "{$outputDir}/".self::AUDIO_HLS_MANIFEST;
            $args[] = '--hls_playlist_type';
            $args[] = 'VOD';
        } else {
            $args[] = '--generate_static_live_mpd';
            $args[] = '--mpd_output';
            $args[] = "{$outputDir}/".self::AUDIO_DASH_MANIFEST;
        }

        return $args;
    }

    /** Where one stream's CMAF segments are written, relative to the run's output dir — matches
     *  {@see Stream::segmentsPath} (which builds the same shape for deletion). */
    private function segmentDir(string $outputDir, string $ulid): string
    {
        return "{$outputDir}/{$ulid}";
    }

    /**
     * @param  array{path:string,type:string,ulid:string,language?:?string,forced?:bool,hearing_impaired?:bool,visual_impaired?:bool,name?:?string}  $input
     * @param  list<string>  $formats
     */
    private function streamDescriptor(array $input, string $outputDir, array $formats): string
    {
        $segmentDir = $this->segmentDir($outputDir, $input['ulid']);

        $parts = [
            "in={$input['path']}",
            "stream={$input['type']}",
            "init_segment={$segmentDir}/init.mp4",
            'segment_template='.$segmentDir.'/$Number$.m4s',
        ];

        if ($input['type'] === 'audio') {
            $parts[] = "lang={$input['language']}";

            $label = self::descriptorLabel($input['name']);
            $parts[] = "dash_label={$label}";

            if (in_array('hls', $formats, true)) {
                $parts[] = 'hls_group_id=audio';
                $parts[] = "hls_name={$label}";
            }

            $parts = [...$parts, ...$this->audioAccessibility($input, $formats)];
        }

        if (in_array('hls', $formats, true)) {
            $parts[] = "playlist_name={$input['ulid']}/index.m3u8";
        }

        return implode(',', $parts);
    }

    /**
     * Accessibility descriptors for an audio track. An audio-description track wins over a
     * hearing-impaired mix when a source flags both. Values are semicolon-separated because the
     * stream descriptor itself is comma-separated; Apple defines no CHARACTERISTIC for HI audio.
     *
     * @param  array{hearing_impaired?:bool,visual_impaired?:bool}  $input
     * @param  list<string>  $formats
     * @return list<string>
     */
    private function audioAccessibility(array $input, array $formats): array
    {
        if (! empty($input['visual_impaired'])) {
            return [
                'dash_roles=description',
                'dash_accessibilities=urn:tva:metadata:cs:AudioPurposeCS:2007=1',
                ...(in_array('hls', $formats, true) ? ['hls_characteristics=public.accessibility.describes-video'] : []),
            ];
        }

        if (! empty($input['hearing_impaired'])) {
            return [
                'dash_roles=enhanced-audio-intelligibility',
                'dash_accessibilities=urn:tva:metadata:cs:AudioPurposeCS:2007=2',
            ];
        }

        return [];
    }

    /**
     * Build a SEPARATE packager run for subtitles in ONE format, with `$segmentDuration` longer than
     * the video so each track is a SINGLE segment under the stream's `{ulid}/` (a few KB — segmenting
     * them like video is pointless). DASH and HLS need DIFFERENT forms, so this runs once per format:
     * - `dash`: a single fMP4 WebVTT (`wvtt`) segment + an `init.mp4` — dashjs needs an init segment
     *   or it falls back to fetching the `<BaseURL>` directory (404). Emits throwaway `_subs.mpd`.
     * - `hls`: a single raw `text/vtt` segment — hls.js can't parse fMP4 WebVTT. Emits `_subs.m3u8`.
     * The throwaway manifest's text entries are grafted into the real manifests ({@see ManifestEditor}).
     * Separate run because `--segment_duration` is global; the main run keeps small segments for video.
     *
     * @param  list<array{path:string,type:string,ulid:string,language?:?string,forced?:bool,hearing_impaired?:bool,visual_impaired?:bool,name?:?string}>  $subs
     * @return list<string>
     */
    public function buildText(array $subs, string $outputDir, int $segmentDuration, string $format): array
    {
        $args = [$this->bin];

        foreach ($subs as $sub) {
            $args[] = $this->textDescriptor($sub, $outputDir, $format);
        }

        $args[] = '--segment_duration';
        $args[] = (string) $segmentDuration;

        if ($format === 'hls') {
            $args[] = '--hls_master_playlist_output';
            $args[] = "{$outputDir}/".self::SUBS_HLS_MANIFEST;
            $args[] = '--hls_playlist_type';
            $args[] = 'VOD';
        } else {
            $args[] = '--generate_static_live_mpd';
            $args[] = '--mpd_output';
            $args[] = "{$outputDir}/".self::SUBS_DASH_MANIFEST;
        }

        return $args;
    }

    /**
     * Subtitle stream descriptor for ONE format under the stream's own `{ulid}/` (same layout as the
     * video/audio renditions). DASH = fMP4 WebVTT (`wvtt`) with an `init_segment` (so dashjs loads a
     * real init instead of 404ing on the `<BaseURL>` dir); HLS = raw `text/vtt` segments + its media
     * playlist (hls.js can't parse fMP4 WebVTT). A long segment duration ({@see buildText}) makes
     * either a single segment.
     *
     * @param  array{path:string,type:string,ulid:string,language?:?string,forced?:bool,hearing_impaired?:bool,visual_impaired?:bool,name?:?string}  $input
     */
    private function textDescriptor(array $input, string $outputDir, string $format): string
    {
        $segmentDir = $this->segmentDir($outputDir, $input['ulid']);

        $parts = ["in={$input['path']}", 'stream=text'];

        if ($format === 'dash') {
            $parts[] = "init_segment={$segmentDir}/init.mp4";
            $parts[] = 'segment_template='.$segmentDir.'/$Number$.m4s';
            $parts[] = 'dash_label='.self::descriptorLabel($input['name']);
        } else {
            $parts[] = 'segment_template='.$segmentDir.'/$Number$.vtt';
            $parts[] = "playlist_name={$input['ulid']}/index.m3u8";
            $parts[] = 'hls_group_id=subs';
            $parts[] = 'hls_name='.self::descriptorLabel($input['name']);
        }

        $parts[] = "lang={$input['language']}";

        // A forced track already implies its own role; SDH takes `caption` instead of `subtitle`.
        if (! empty($input['forced'])) {
            $parts[] = 'forced_subtitle=1';
        } elseif (! empty($input['hearing_impaired'])) {
            $parts[] = 'dash_roles=caption';

            if ($format === 'hls') {
                $parts[] = 'hls_characteristics=public.accessibility.transcribes-spoken-dialog;public.accessibility.describes-music-and-sound';
            }
        } else {
            $parts[] = 'dash_roles=subtitle';
        }

        return implode(',', $parts);
    }

    /**
     * Last gate before a track name reaches the packager. A comma ends the descriptor field, and a
     * quote or a newline breaks out of the `NAME="…"` attribute of the `#EXT-X-MEDIA` line that
     * the HLS master is assembled from as plain text — a newline turns the rest of the name into a
     * playlist tag of its own, for every viewer.
     *
     * Both writers of the column already refuse these characters ({@see UpdateStreamData} on the
     * edit path, {@see CreateVideoStreamsService::baseName()} on ingest), so this changes nothing
     * in practice. It is here so a third writer cannot reopen the hole quietly.
     */
    private static function descriptorLabel(?string $name): string
    {
        return trim((string) preg_replace('/["\r\n,]+/u', ' ', (string) $name));
    }
}
