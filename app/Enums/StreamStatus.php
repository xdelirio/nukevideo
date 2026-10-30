<?php

namespace App\Enums;

use App\Jobs\AddTrackJob;

/**
 * Where a track added to an already-processed video stands ({@see AddTrackJob}). Streams
 * minted by a video's own run never carry one: their fate is the video's and the output's status,
 * so a null status reads as ready.
 */
enum StreamStatus: string
{
    case PROCESSING = 'processing';
    case READY = 'ready';
    case FAILED = 'failed';
}
