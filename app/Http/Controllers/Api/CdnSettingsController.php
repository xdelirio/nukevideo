<?php

namespace App\Http\Controllers\Api;

use App\Data\CdnSettingsData;
use App\Http\Controllers\Controller;
use App\Settings\CdnSettings;
use App\Settings\EdgeS3Settings;
use App\Support\AllowedDomains;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CdnSettingsController extends Controller
{
    public function show(CdnSettings $settings): JsonResponse
    {
        return response()->json(['data' => CdnSettingsData::fromSettings($settings)]);
    }

    public function update(Request $request, CdnSettings $settings, EdgeS3Settings $edgeS3): JsonResponse
    {
        $validated = $request->validate([
            // Hex, in whole bytes: the signer packs it with `H*` and the edge writes it into its
            // nginx config as it stands, where anything else is either a signature no edge
            // accepts or a line of config — and an edge that cannot load its config crashloops.
            'tokenSecret' => ['nullable', 'string', 'max:512', 'regex:/^([0-9a-fA-F]{2})+\z/'],
            'tokenName' => ['required', 'string', 'regex:/^[a-z0-9_]+$/'],
            'tokenWindow' => ['required', 'integer', 'min:1'],
            'bindIp' => ['sometimes', 'boolean'],
            'allowedDomains' => ['sometimes', 'array', 'max:'.AllowedDomains::MAX],
            'allowedDomains.*' => ['string', 'regex:'.AllowedDomains::pattern()],
            'allowNullOrigin' => ['sometimes', 'boolean'],

            // Validated as a pair: half of one signs every S3 request wrongly, and every cache miss
            // on the proxies would be a 403. An unchanged key may leave the secret out, since the
            // panel is never handed it back; a changed one cannot keep the old key's secret.
            'edgeS3' => ['sometimes', 'array'],
            'edgeS3.accessKeyId' => [
                Rule::requiredIf(fn () => $request->filled('edgeS3.secretAccessKey')),
                'nullable',
                'string',
                'max:255',
                // What an S3 key can be made of — and, since the edge writes both into its nginx
                // config, nothing nginx would read as syntax.
                'regex:/^[A-Za-z0-9\/+=._-]+\z/',
            ],
            'edgeS3.secretAccessKey' => [
                Rule::requiredIf(fn () => $request->filled('edgeS3.accessKeyId') && (
                    $request->input('edgeS3.accessKeyId') !== $edgeS3->access_key_id || $edgeS3->secret_access_key === ''
                )),
                'nullable',
                'string',
                'max:255',
                // What an S3 key can be made of — and, since the edge writes both into its nginx
                // config, nothing nginx would read as syntax.
                'regex:/^[A-Za-z0-9\/+=._-]+\z/',
            ],
        ], [
            'tokenSecret.regex' => 'The token secret must be hexadecimal, in whole bytes (an even number of digits).',
            'allowedDomains.*.regex' => 'Each domain must be a bare hostname, like example.com or *.example.com. ":input" is not.',
        ]);

        // A field the request leaves out keeps what is stored: an older panel, or a script, saving
        // the rest must not switch a protection off behind the operator. The secret arrives as
        // null when the field is empty, so it too can only be replaced, never blanked.
        $settings->token_name = $validated['tokenName'];
        $settings->token_window = (int) $validated['tokenWindow'];

        if (isset($validated['tokenSecret'])) {
            $settings->token_secret = $validated['tokenSecret'];
        }
        if (isset($validated['bindIp'])) {
            $settings->bind_ip = (bool) $validated['bindIp'];
        }
        // An empty list is a value, not an absence: it is how the restriction gets lifted.
        if (isset($validated['allowedDomains'])) {
            $settings->allowed_domains = AllowedDomains::normalize($validated['allowedDomains']);
        }
        if (isset($validated['allowNullOrigin'])) {
            $settings->allow_null_origin = (bool) $validated['allowNullOrigin'];
        }

        if (array_key_exists('edgeS3', $validated)) {
            $edgeS3->access_key_id = $validated['edgeS3']['accessKeyId'] ?? '';
            $edgeS3->secret_access_key = $edgeS3->access_key_id === ''
                ? ''
                : ($validated['edgeS3']['secretAccessKey'] ?? $edgeS3->secret_access_key);
            $edgeS3->save();
        }

        $settings->save();

        return response()->json(['data' => CdnSettingsData::fromSettings($settings)]);
    }
}
