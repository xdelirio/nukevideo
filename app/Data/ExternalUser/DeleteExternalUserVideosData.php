<?php

namespace App\Data\ExternalUser;

use App\Data\RequestData;
use App\Http\Controllers\Api\ExternalUserController;

/**
 * The body of the one destructive call on a customer ({@see ExternalUserController::destroyVideos()}).
 *
 * `confirm` repeats the id from the path. A client that builds the path from the wrong variable —
 * an empty one, a stale one — would otherwise delete another customer's catalogue with a request
 * that looks perfectly valid, and nothing deleted here can be brought back.
 */
class DeleteExternalUserVideosData extends RequestData
{
    public function __construct(
        public string $confirm,
    ) {}

    public static function rules(): array
    {
        return [
            'confirm' => 'required|string|max:255',
        ];
    }
}
