<?php

namespace App\Support;

use App\Services\MetricShaper;
use Closure;
use DateTimeImmutable;

/**
 * The rules every `from`/`to` pair of an analytics read is held to.
 *
 * A range has to end on or after its start, and it has to be finite in practice as well as in
 * form: `0001-01-01` to `9999-12-31` is two perfectly valid ISO dates and three and a half million
 * days, which a read that returns one row per day ({@see MetricShaper}) would
 * build in memory before deciding the answer was too large — for any caller with a token.
 */
class DateRange
{
    /** Ten years: longer than the table has existed, shorter than anything that hurts. */
    public const MAX_DAYS = 3660;

    /** @return list<string> */
    public static function from(): array
    {
        return ['required', 'date_format:Y-m-d'];
    }

    /**
     * @param  string  $from  the sibling field holding the start of the range
     * @return list<string|Closure>
     */
    public static function to(string $from = 'from'): array
    {
        return [
            'required',
            'date_format:Y-m-d',
            "after_or_equal:{$from}",
            function (string $attribute, mixed $value, Closure $fail) use ($from) {
                $start = DateTimeImmutable::createFromFormat('!Y-m-d', (string) request()->input($from));
                $end = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $value);

                // Malformed dates are the other rules' to report.
                if ($start && $end && $start->diff($end)->days > self::MAX_DAYS) {
                    $fail('The range may not span more than '.self::MAX_DAYS.' days.');
                }
            },
        ];
    }
}
