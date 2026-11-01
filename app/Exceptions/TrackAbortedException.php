<?php

namespace App\Exceptions;

use App\Services\TrackPublisher;
use RuntimeException;

/**
 * An added track could not be published because the video moved on underneath it
 * ({@see TrackPublisher}): it stopped being COMPLETED (a retry, a delete), or the
 * track's own rows were deleted while it was being processed. Nothing was grafted either way.
 */
class TrackAbortedException extends RuntimeException
{
    public static function videoChanged(): self
    {
        return new self('The video stopped being completed while the track was processed.');
    }

    public static function tracksGone(): self
    {
        return (new self('The track was deleted while it was processed.'))->markGone();
    }

    private bool $gone = false;

    /** Whether there is no track left to report on: a delete is not a failure worth announcing. */
    public function tracksAreGone(): bool
    {
        return $this->gone;
    }

    private function markGone(): self
    {
        $this->gone = true;

        return $this;
    }
}
