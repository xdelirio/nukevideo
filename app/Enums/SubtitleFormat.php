<?php

namespace App\Enums;

/**
 * How a DASH manifest hands a player its subtitles.
 *
 * `vtt`, the default, points each text track at one plain `.vtt` file, which every player that
 * shows subtitles can load. `segments` is what the packager writes — fragmented-MP4 WebVTT
 * (`wvtt`), which Shaka, dash.js and ExoPlayer read but a number of smart-TV and embedded players
 * do not — and a link gets it only by asking. HLS is the same either way: it already carries raw
 * WebVTT, and has no way to name a bare file without a playlist around it.
 */
enum SubtitleFormat: string
{
    case SEGMENTS = 'segments';
    case VTT = 'vtt';
}
