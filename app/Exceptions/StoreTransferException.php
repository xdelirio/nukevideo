<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An s5cmd transfer that kept failing through every in-job attempt. Carries the disk so a job can
 * tell an outage of the primary store — which is the provider's and passes on its own — from a
 * broken local mirror, and wait for the former instead of spending the video on it.
 */
class StoreTransferException extends RuntimeException
{
    public function __construct(public readonly string $disk, string $message)
    {
        parent::__construct($message);
    }
}
