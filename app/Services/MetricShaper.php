<?php

namespace App\Services;

use App\Data\Analytics\MetricsQueryData;
use App\Enums\MetricDimension;
use Carbon\CarbonPeriod;
use Illuminate\Validation\ValidationException;

/**
 * Turns the long rows a `GROUP BY` produces into the shape a chart wants.
 *
 * Two transformations, and neither is presentation — there are no labels, formats or colours here,
 * and there will not be:
 *
 * - **Pivot.** `GROUP BY tracking_id, metric, date` answers with one row per metric; a charting
 *   library wants one accessor per series, so `{date, streaming_bytes, download_bytes}`. Every
 *   consumer writes this transformation again, in its own language, and gets the missing-key case
 *   wrong the first time.
 * - **Zero fill.** The query omits a day that produced no rows. A line drawn straight over that
 *   gap is a lie — it interpolates traffic that did not happen — and the caller cannot fix it
 *   without knowing the range, which the server does.
 *
 * Opt-in, because filling a sparse month multiplies the response by the length of the range.
 */
class MetricShaper
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  list<MetricDimension>  $dimensions
     * @return array<int, array<string, mixed>>
     */
    public function wide(array $rows, array $dimensions, string $from, string $to): array
    {
        $pivots = in_array(MetricDimension::METRIC, $dimensions, true);
        $dated = in_array(MetricDimension::DATE, $dimensions, true);

        // What identifies a row in the wide result: every dimension except the one being pivoted
        // out into columns.
        $rowDims = array_values(array_filter($dimensions, fn (MetricDimension $d) => $d !== MetricDimension::METRIC));
        // What identifies a series across time: the same, minus the date.
        $seriesDims = array_values(array_filter($rowDims, fn (MetricDimension $d) => $d !== MetricDimension::DATE));

        $columns = $pivots ? $this->metricsIn($rows) : ['value'];
        $blank = array_fill_keys($columns, 0.0);

        $wide = [];
        $series = [];

        foreach ($rows as $row) {
            $identity = $this->identity($row, $rowDims);

            $wide[$identity] ??= $this->dimensionsOf($row, $rowDims) + $blank;
            $wide[$identity][$pivots ? (string) $row[MetricDimension::METRIC->value] : 'value'] = (float) $row['value'];

            if ($dated) {
                $series[$this->identity($row, $seriesDims)] ??= $this->dimensionsOf($row, $seriesDims);
            }
        }

        if ($dated) {
            // Counted before it is built: one row per series per day, so the size is known from
            // two numbers, and a range that cannot fit is refused without materialising it first.
            $days = CarbonPeriod::create($from, $to)->count();

            if (count($series) * $days > MetricsQueryData::MAX_ROWS) {
                throw ValidationException::withMessages([
                    'shape' => 'Filling every day of this range produces more than '.MetricsQueryData::MAX_ROWS
                        .' rows. Shorten the range, drop a dimension, or ask for the long shape.',
                ]);
            }

            $wide = $this->fillDates($wide, $series, $rowDims, $blank, $from, $to);
        }

        if (count($wide) > MetricsQueryData::MAX_ROWS) {
            throw ValidationException::withMessages([
                'shape' => 'Filling every day of this range produces more than '.MetricsQueryData::MAX_ROWS
                    .' rows. Shorten the range, drop a dimension, or ask for the long shape.',
            ]);
        }

        // Sorted by the dimensions, so a chart reads the array in axis order without re-sorting.
        ksort($wide);

        return array_values($wide);
    }

    /**
     * Every day of the range, for every series that appeared at all.
     *
     * Only for series that appeared: inventing rows for a tracking id with no traffic anywhere in
     * the range would contradict the rest of this API, where absence is the answer and a missing
     * key means zero. What is filled is the gap INSIDE a series that exists — which is the gap a
     * chart draws wrong.
     *
     * @param  array<string, array<string, mixed>>  $wide
     * @param  array<string, array<string, mixed>>  $series
     * @param  list<MetricDimension>  $rowDims
     * @param  array<string, float>  $blank
     * @return array<string, array<string, mixed>>
     */
    private function fillDates(array $wide, array $series, array $rowDims, array $blank, string $from, string $to): array
    {
        foreach (CarbonPeriod::create($from, $to) as $day) {
            $date = $day->format('Y-m-d');

            foreach ($series as $dimensions) {
                $row = $dimensions + [MetricDimension::DATE->value => $date];
                $identity = $this->identity($row, $rowDims);

                $wide[$identity] ??= $row + $blank;
            }
        }

        return $wide;
    }

    /**
     * The distinct metric names present, which become the pivoted columns.
     *
     * Read off the rows rather than off the request: a caller that named no metric got the delivery
     * set, and one that named four may have traffic under two. Emitting a column for a metric with
     * no data anywhere would be padding, and the missing key already means zero.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<string>
     */
    private function metricsIn(array $rows): array
    {
        $metrics = array_unique(array_map(fn (array $row) => (string) $row[MetricDimension::METRIC->value], $rows));
        sort($metrics);

        return array_values($metrics);
    }

    /**
     * A stable string identity for a row, so two rows of the same series and day collapse. Values
     * are joined with a character the columns cannot contain (a ULID, a date, a metric name, an
     * address and a tracking id are all free of it), so two different rows cannot collide into one.
     *
     * @param  array<string, mixed>  $row
     * @param  list<MetricDimension>  $dimensions
     */
    private function identity(array $row, array $dimensions): string
    {
        return implode("\0", array_map(fn (MetricDimension $d) => (string) ($row[$d->alias()] ?? ''), $dimensions));
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<MetricDimension>  $dimensions
     * @return array<string, mixed>
     */
    private function dimensionsOf(array $row, array $dimensions): array
    {
        $out = [];

        foreach ($dimensions as $dimension) {
            $out[$dimension->alias()] = $row[$dimension->alias()] ?? null;
        }

        return $out;
    }
}
