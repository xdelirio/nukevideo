<?php

namespace App\Data\AppSettings;

use App\Data\RequestData;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Mappers\CamelCaseMapper;

class UpdateTailscaleData extends RequestData
{
    public function __construct(
        /**
         * A reusable auth key from the Tailscale admin console (`tskey-…`). Checked for shape only:
         * the form of the key is all that can be checked without joining a node with it, and the
         * prefix rules out a pasted line of something else ending up on a node's command line.
         */
        #[MapInputName(CamelCaseMapper::class)]
        public string $authKey,
    ) {}

    public static function rules(): array
    {
        return [
            'authKey' => ['required', 'string', 'max:255', 'regex:/^tskey-[A-Za-z0-9_-]+$/'],
        ];
    }
}
