<?php

namespace App\Data\AppSettings;

use App\Data\RequestData;
use App\Services\SshKeyService;
use phpseclib4\Crypt\PublicKeyLoader;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Mappers\CamelCaseMapper;

class UpdateSshKeyData extends RequestData
{
    public function __construct(
        /**
         * The private half only, and only when importing a pair made elsewhere. Absent, the
         * server generates one ({@see SshKeyService::set}). The public half is
         * never accepted: it is derived from the private key, so the two can never disagree.
         *
         * Replaces the current key outright, without touching the nodes — for a first key, or
         * when the new public half already reached the fleet by other means. To swap keys on a
         * running fleet use the rotation instead ({@see SshKeyService::rotate}).
         */
        #[MapInputName(CamelCaseMapper::class)]
        public ?string $privateKey = null,
    ) {}

    public static function rules(): array
    {
        return [
            'privateKey' => [
                'nullable',
                'string',
                function ($attr, $value, $fail) {
                    try {
                        $key = PublicKeyLoader::load($value);

                        if (! str_contains($value, 'PRIVATE KEY') || ! method_exists($key, 'getPublicKey')) {
                            return $fail('The private key format is invalid.');
                        }
                    } catch (\Throwable $e) {
                        return $fail('The private key is invalid.');
                    }
                },
            ],
        ];
    }
}
