<?php

namespace App\Data;

use Spatie\LaravelData\Data;

/**
 * One subtitle track as a standalone WebVTT file, for a player that cannot take its text tracks
 * from the manifest and has to be handed them one by one (`<track src>`, `addRemoteTextTrack`).
 *
 * The same tracks are already inside every manifest in `sources`, so a player that reads them from
 * there needs nothing from this list — loading both shows each language twice.
 *
 * Carries what a text-track menu needs and nothing else, so the caller does not have to join the
 * answer against `streams[]` of `GET /api/videos/{ulid}` to label an entry.
 */
class VodSubtitleData extends Data
{
    public function __construct(
        /**
         * The signed `.vtt`: the whole track in one file, cue times on the video's own timeline.
         * Signed for this one object, unlike a manifest link ({@see VodSourceData}).
         */
        public string $url,
        /** The track, matching an entry of `streams[]` on the video. */
        public string $streamUlid,
        /** As stored on the stream (ISO 639), null when the source did not tag it. */
        public ?string $language,
        /** The label a menu shows. Unique among the video's subtitles, case-insensitively. */
        public ?string $name,
        /** Foreign-dialogue only: meant to be shown without the viewer asking for subtitles. */
        public bool $forced,
        /** SDH — carries sound descriptions as well as dialogue. */
        public bool $hearingImpaired,
    ) {}
}
