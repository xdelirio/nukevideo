<?php

namespace App\Data;

use Spatie\LaravelData\Data;

/**
 * The answer to a video-level mint: everything of this video that can be played, and the assets
 * that go around it.
 *
 * A manifest that cannot be served is simply absent. There is no per-output error channel and no
 * reason code: what a caller does with "this one is not playable" and with "this one does not
 * exist" is the same thing — play something else — and `sources` already says what it can play.
 * The whole-request failures stay whole-request, because they are conditions of the video.
 *
 * The thumbnail and the storyboard sit at this level because they are per-video and unsigned: the
 * same two URLs whatever the caller ends up playing.
 */
class VideoVodLinksData extends Data
{
    public function __construct(
        /** The video these links play — what tells the entries of a batch mint apart. */
        public string $videoUlid,
        public string $thumbnailUrl,
        public string $storyboardUrl,
        /**
         * When every link in this answer stops validating. One value for the batch: they are all
         * signed in the same request against the same provider window.
         */
        public string $expiresAt,
        /**
         * Every playable manifest of the video, one entry per output and format. Flat rather than
         * grouped by output ({@see VodSourceData}).
         *
         * @var VodSourceData[]
         */
        public array $sources,
        /**
         * Every subtitle track as a standalone WebVTT file, for players that take their text
         * tracks one by one rather than from the manifest ({@see VodSubtitleData}). Empty when the
         * video has none — never absent.
         *
         * @var VodSubtitleData[]
         */
        public array $subtitles,
    ) {}
}
