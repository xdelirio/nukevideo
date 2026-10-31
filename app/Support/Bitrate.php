<?php

namespace App\Support;

/** Bitrates as the codec catalogue writes them (`128k`, `2M`, `96000`) and as bits per second. */
class Bitrate
{
    /** `128k` → 128000; null for an empty or non-numeric value (a VBR rung states none). */
    public static function toBps(int|string|null $rate): ?int
    {
        $rate = strtolower(trim((string) $rate));

        if ($rate === '' || ! is_numeric(rtrim($rate, 'km'))) {
            return null;
        }

        $multiplier = match (substr($rate, -1)) {
            'k' => 1_000,
            'm' => 1_000_000,
            default => 1,
        };

        return (int) round((float) rtrim($rate, 'km') * $multiplier);
    }
}
