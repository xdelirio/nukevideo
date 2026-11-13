<?php

namespace App\Data\Analytics;

use App\Data\RequestData;
use App\Support\DateRange;

/**
 * What every metrics read that takes a list of values has in common: a bounded date range and a cap
 * on how many values one call may name.
 *
 * Extracted because three request objects were carrying the same two decisions, one of which was
 * reaching across into another class to borrow a constant. The response objects are NOT unified the
 * same way: `trackingId` and `video` differ only in the name of one field, and that name is the
 * entire meaning of the row — collapsing them into a generic `key` would buy a few lines and cost
 * every consumer its types.
 */
abstract class BatchQueryData extends RequestData
{
    /**
     * How many values one call may name.
     *
     * The number is the query's, not the transport's: a `GROUP BY` over a bound `Array(String)` is
     * one pass whatever the list's length, so a thousand costs about what ten do. What does not
     * stretch is a URL — a thousand 64-character ids is roughly 80 KB of query string, past what
     * most proxies will put in a request line — which is why these routes take POST as well, and
     * why a caller batching over GET should stay far below this.
     */
    public const MAX_BATCH = 1000;

    /**
     * Both bounds required, as ISO dates: they are compared against a `date` column, and an
     * open-ended range over a table kept indefinitely is a full scan nobody asked for.
     *
     * @return array<string, string>
     */
    protected static function rangeRules(): array
    {
        return [
            'from' => DateRange::from(),
            'to' => DateRange::to(),
        ];
    }
}
