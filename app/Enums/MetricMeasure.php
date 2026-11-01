<?php

namespace App\Enums;

use App\Http\Controllers\Api\MetricsController;

/**
 * What a metrics query measures for each group ({@see MetricsController}), beyond the sum of
 * `value` it always returns.
 *
 * `usage_daily` holds more than the sum: every row counts the requests that were folded into it,
 * and carries the address and tracking id that served it. Without these an integrator could read
 * how many bytes a video moved but not how many times it was fetched or by how many viewers — the
 * numbers a per-title report is usually about.
 *
 * The distinct counts skip the empty value. A row with no address is upload volume or encoding
 * time, not a viewer, and a row with no tracking id is traffic nobody attributed: counting `''` as
 * one more viewer would add a phantom to every group.
 */
enum MetricMeasure: string
{
    /** The sum of `value`, in the unit the metric names. Always returned; naming it is a no-op. */
    case VALUE = 'value';

    /** How many requests were summed into the group: segment and manifest fetches, for delivery. */
    case REQUESTS = 'requests';

    /** Distinct viewer addresses in the group. */
    case UNIQUE_IPS = 'unique_ips';

    /** Distinct tracking ids in the group — distinct viewers, when every link carries one. */
    case UNIQUE_TRACKING_IDS = 'unique_tracking_ids';

    /**
     * The aggregate that produces this measure, aliased to its own name. Class-controlled, never
     * caller input — the caller picks a case, not an expression.
     */
    public function selection(): string
    {
        return match ($this) {
            self::VALUE => 'sum(value) as value',
            self::REQUESTS => 'sum(requests) as requests',
            self::UNIQUE_IPS => "count(distinct case when ip != '' then ip end) as unique_ips",
            self::UNIQUE_TRACKING_IDS => "count(distinct case when tracking_id != '' then tracking_id end) as unique_tracking_ids",
        };
    }
}
