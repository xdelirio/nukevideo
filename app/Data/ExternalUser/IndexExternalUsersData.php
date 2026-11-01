<?php

namespace App\Data\ExternalUser;

use App\Data\RequestData;
use App\Http\Controllers\Api\ExternalUserController;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Mappers\CamelCaseMapper;

/**
 * The listing of a project's customers ({@see ExternalUserController::index()}): every
 * `externalUserId` its videos were uploaded with, with how much they hold.
 */
class IndexExternalUsersData extends RequestData
{
    public const SORTS = ['external_user_id', 'videos', 'storage_bytes', 'last_upload_at'];

    public const PER_PAGE_DEFAULT = 25;

    public const PER_PAGE_MAX = 100;

    public function __construct(
        /** A prefix of the id: customer ids are usually structured, and a prefix can use the index. */
        public ?string $search = null,
        public string $sort = 'last_upload_at',
        public string $direction = 'desc',
        #[MapInputName(CamelCaseMapper::class)]
        public int $perPage = self::PER_PAGE_DEFAULT,
    ) {}

    public static function prepareForPipeline(array $properties): array
    {
        foreach (['sort', 'direction', 'perPage'] as $key) {
            if (array_key_exists($key, $properties) && ($properties[$key] === null || $properties[$key] === '')) {
                unset($properties[$key]);
            }
        }

        return $properties;
    }

    public static function rules(): array
    {
        return [
            'search' => 'nullable|string|max:255',
            'sort' => ['nullable', Rule::in(self::SORTS)],
            'direction' => 'nullable|in:asc,desc',
            'perPage' => 'nullable|integer|min:1|max:'.self::PER_PAGE_MAX,
        ];
    }
}
