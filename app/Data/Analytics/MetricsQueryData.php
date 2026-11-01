<?php

namespace App\Data\Analytics;

use App\Enums\MetricDimension;
use App\Enums\MetricMeasure;
use App\Enums\MetricShape;
use App\Enums\UsageMetric;
use App\Http\Controllers\Api\MetricsController;
use App\Support\TrackingId;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Optional;

/**
 * The general metrics query ({@see MetricsController::query()}): a date range, the dimensions to
 * break down by, and optional lists to narrow each of them to.
 *
 * Shape only. Whether the caller MAY name a dimension is authorization, not validation, and is
 * decided in the controller where the project and the account are — the same split as
 * {@see VideoBytesQueryData}, whose rules validate ULIDs while ownership is enforced against the
 * resolved project.
 */
class MetricsQueryData extends BatchQueryData
{
    /** The most rows one query may return, whatever it asked for. */
    public const MAX_ROWS = 50000;

    public function __construct(
        public string $from,
        public string $to,
        /**
         * Kept as strings and mapped in {@see dimensions()}: Spatie casts a typed property to an
         * enum, but not the ELEMENTS of an array from a docblock alone, so typing this
         * `list<MetricDimension>` would hand the controller strings that look like enums until the
         * first `->value` fatals.
         *
         * @var list<string>
         */
        public array $dimensions,
        /** @var list<string> */
        public array|Optional $metrics,
        /** @var list<string> */
        public array|Optional $videos,
        /** @var list<string> */
        #[MapInputName(CamelCaseMapper::class)]
        public array|Optional $trackingIds,
        /** @var list<string> */
        #[MapInputName(CamelCaseMapper::class)]
        public array|Optional $externalUserIds,
        public MetricShape $shape = MetricShape::LONG,
        /**
         * Strings mapped in {@see measures()}, for the same reason as `$dimensions`.
         *
         * @var list<string>
         */
        public array|Optional $measures = new Optional,
    ) {}

    /**
     * What to compute beside the sum of `value`, as enum cases.
     *
     * @return list<MetricMeasure>
     */
    public function measures(): array
    {
        return array_map(fn (string $m) => MetricMeasure::from($m), $this->list('measures'));
    }

    /**
     * The dimensions as enum cases, deduplicated — naming one twice would repeat it in the GROUP BY
     * for no change in the answer. Validation has already refused anything that is not a case.
     *
     * @return list<MetricDimension>
     */
    public function dimensions(): array
    {
        return array_values(array_map(
            fn (string $d) => MetricDimension::from($d),
            array_unique($this->dimensions),
        ));
    }

    public function has(MetricDimension $dimension): bool
    {
        return in_array($dimension, $this->dimensions(), true);
    }

    /** @return list<string> */
    public function list(string $property): array
    {
        $value = $this->{$property};

        return $value instanceof Optional ? [] : array_values(array_unique($value));
    }

    public static function rules(): array
    {
        return static::rangeRules() + [
            // At least one, or the query is a single instance-wide total that `analytics` already
            // gives. Capped at the number that exist, so a caller cannot pad the list.
            'dimensions' => 'required|array|min:1|max:'.count(MetricDimension::cases()),
            'dimensions.*' => ['required', Rule::enum(MetricDimension::class)],

            // Every metric, not just the delivery ones: an account's upload volume and encoding
            // seconds live in the same table and are exactly the "more data" this endpoint exists
            // to stop costing a new endpoint each. The unit follows the metric, per row, and the
            // controller forces account scoping when a non-delivery metric is asked for.
            'metrics' => 'sometimes|array|min:1|max:'.count(UsageMetric::cases()),
            'metrics.*' => ['required', 'string', Rule::in(array_column(UsageMetric::cases(), 'value'))],

            'videos' => 'sometimes|array|min:1|max:'.self::MAX_BATCH,
            'videos.*' => 'required|ulid',

            'trackingIds' => 'sometimes|array|min:1|max:'.self::MAX_BATCH,
            'trackingIds.*' => array_merge(['required'], TrackingId::rules()),

            'externalUserIds' => 'sometimes|array|min:1|max:'.self::MAX_BATCH,
            'externalUserIds.*' => 'required|string|max:255',

            'shape' => ['nullable', Rule::enum(MetricShape::class)],

            'measures' => 'sometimes|array|min:1|max:'.count(MetricMeasure::cases()),
            'measures.*' => ['required', Rule::enum(MetricMeasure::class)],
        ];
    }
}
