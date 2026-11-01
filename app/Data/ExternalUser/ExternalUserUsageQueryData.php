<?php

namespace App\Data\ExternalUser;

use App\Data\Analytics\BatchQueryData;
use App\Enums\UsageGranularity;
use App\Enums\UsageMetric;
use App\Http\Controllers\Api\ExternalUserController;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Mappers\CamelCaseMapper;

/**
 * The batch usage read by customer ({@see ExternalUserController::usage()}): what each of the
 * named `externalUserId`s consumed, by metric — the read an integration bills its own customers
 * from, one request for all of them.
 */
class ExternalUserUsageQueryData extends BatchQueryData
{
    public function __construct(
        public string $from,
        public string $to,
        /** @var list<string> */
        #[MapInputName(CamelCaseMapper::class)]
        public array $externalUserIds,
        /** One metric only, when the caller bills a single line. Omitted, every account metric. */
        public ?string $metric = null,
        public UsageGranularity $granularity = UsageGranularity::TOTAL,
    ) {}

    public static function rules(): array
    {
        return static::rangeRules() + [
            'externalUserIds' => 'required|array|min:1|max:'.self::MAX_BATCH,
            'externalUserIds.*' => 'required|string|max:255',
            // Every metric an account owns, the operator's origin egress excluded: it carries no
            // customer and would always answer empty.
            'metric' => ['nullable', 'string', Rule::in(UsageMetric::account())],
            'granularity' => ['nullable', Rule::enum(UsageGranularity::class)],
        ];
    }
}
