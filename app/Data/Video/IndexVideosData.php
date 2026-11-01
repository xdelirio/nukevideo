<?php

namespace App\Data\Video;

use App\Data\RequestData;
use App\Enums\VideoStatus;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Mappers\CamelCaseMapper;

/**
 * The listing's query string, camelCased on the wire like every other request in this API.
 *
 * It used to take the global snake_case input mapping, which quietly split every guard from the
 * value it guarded: Spatie binds the bare property name too, so `?perPage=` reached `$perPage`
 * without passing the clamp below — which was looking for `per_page` — and a cap of 100 answered
 * 100000. Mapping the properties explicitly gives the mapper, the rules and the clamp one name.
 */
class IndexVideosData extends RequestData
{
    /** What the list can be ordered by. `size` is summed over the streams, the rest are columns. */
    public const SORTS = ['created_at', 'updated_at', 'name', 'size', 'duration', 'status'];

    /** The page size when none is asked for, and the cap: the payload embeds each video's outputs and streams. */
    public const PER_PAGE_DEFAULT = 15;

    public const PER_PAGE_MAX = 100;

    public function __construct(
        public ?string $search = null,
        #[MapInputName(CamelCaseMapper::class)]
        public ?string $externalUserId = null,
        #[MapInputName(CamelCaseMapper::class)]
        public ?string $externalResourceId = null,
        /** One status, or several comma-separated (`completed,failed`). */
        public ?string $status = null,
        /**
         * Only the videos changed at or after this instant (ISO 8601). How an integration keeps
         * its own copy in sync: ask for what changed since the last poll, sorted by `updated_at`,
         * instead of walking the whole catalogue. A deleted video does not appear: the
         * `video.deleted` webhook reports those, for videos uploaded with an `externalResourceId`.
         */
        #[MapInputName(CamelCaseMapper::class)]
        public ?string $updatedSince = null,
        public string $sort = 'created_at',
        public string $direction = 'desc',
        #[MapInputName(CamelCaseMapper::class)]
        public int $perPage = self::PER_PAGE_DEFAULT,
    ) {}

    /**
     * Runs before validation and mapping, on the raw query string.
     *
     * `?sort=&direction=&perPage=` is how an integrator's query builder spells "the default"
     * — an empty value, not an absent key — and it must read as such: an empty string would
     * otherwise land in the typed property (or in `orderBy`) as is. The page size is clamped to
     * the cap rather than refused, which is what the listing has always done and what a client
     * asking for "everything" expects; only a non-numeric value is left for validation to reject.
     */
    public static function prepareForPipeline(array $properties): array
    {
        foreach (['sort', 'direction', 'perPage'] as $key) {
            if (array_key_exists($key, $properties) && ($properties[$key] === null || $properties[$key] === '')) {
                unset($properties[$key]);
            }
        }

        if (isset($properties['perPage']) && is_numeric($properties['perPage'])) {
            $properties['perPage'] = min(max((int) $properties['perPage'], 1), self::PER_PAGE_MAX);
        }

        return $properties;
    }

    /** @return list<string> */
    public function statuses(): array
    {
        return $this->status === null || $this->status === ''
            ? []
            : array_values(array_unique(array_filter(array_map('trim', explode(',', $this->status)))));
    }

    public static function rules(): array
    {
        return [
            'search' => 'nullable|string|max:255',
            'externalUserId' => 'nullable|string|max:255',
            'externalResourceId' => 'nullable|string|max:255',
            'updatedSince' => 'nullable|date',
            'status' => [
                'nullable', 'string',
                function ($attribute, $value, $fail) {
                    $allowed = array_column(VideoStatus::cases(), 'value');

                    foreach (explode(',', $value) as $status) {
                        if (! in_array(trim($status), $allowed, true)) {
                            $fail("Unknown status [{$status}]. Allowed: ".implode(', ', $allowed).'.');
                        }
                    }
                },
            ],
            // Not `nullable`: the properties are typed non-null with defaults, and an empty
            // value never reaches this ({@see prepareForPipeline()}).
            'sort' => [Rule::in(self::SORTS)],
            'direction' => [Rule::in(['asc', 'desc'])],
            // Already clamped to [1, PER_PAGE_MAX] upstream; this only refuses non-integers.
            'perPage' => 'integer',
        ];
    }
}
