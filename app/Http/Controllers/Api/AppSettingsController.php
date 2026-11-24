<?php

namespace App\Http\Controllers\Api;

use App\Data\AppSettings\UpdateCloudflareData;
use App\Data\AppSettings\UpdateSshKeyData;
use App\Data\AppSettings\UpdateTailscaleData;
use App\Data\AppSettingsData;
use App\Http\Controllers\Controller;
use App\Services\CloudflareDns;
use App\Services\SshKeyService;
use App\Settings\AppSettings;
use App\Settings\CloudflareSettings;
use App\Settings\TailscaleSettings;
use Illuminate\Http\JsonResponse;

class AppSettingsController extends Controller
{
    public function __construct(private SshKeyService $sshKeys) {}

    public function show(AppSettings $settings): JsonResponse
    {
        return response()->json(['data' => AppSettingsData::fromSettings($settings)]);
    }

    public function updateSshKey(UpdateSshKeyData $data): JsonResponse
    {
        return response()->json(['data' => AppSettingsData::fromSettings($this->sshKeys->set($data->privateKey))]);
    }

    public function rotateSshKey(): JsonResponse
    {
        return response()->json(['data' => $this->sshKeys->rotate()]);
    }

    public function updateCloudflare(UpdateCloudflareData $data, CloudflareSettings $cloudflare, AppSettings $settings): JsonResponse
    {
        if ($data->apiToken !== null) {
            $cloudflare->api_token = $data->apiToken;
        }
        $cloudflare->proxied = $data->proxied;
        $cloudflare->save();

        return response()->json(['data' => AppSettingsData::fromSettings($settings)]);
    }

    /**
     * Forgets the token. The records it created stay where they are: they are what the fleet is
     * being served through, and removing the integration is not a request to take it offline.
     */
    public function destroyCloudflare(CloudflareSettings $cloudflare, AppSettings $settings): JsonResponse
    {
        $cloudflare->api_token = '';
        $cloudflare->proxied = false;
        $cloudflare->save();

        return response()->json(['data' => AppSettingsData::fromSettings($settings)]);
    }

    public function updateTailscale(UpdateTailscaleData $data, TailscaleSettings $tailscale, AppSettings $settings): JsonResponse
    {
        $tailscale->auth_key = $data->authKey;
        $tailscale->save();

        return response()->json(['data' => AppSettingsData::fromSettings($settings)]);
    }

    /**
     * Forgets the key. Nodes already joined stay in the tailnet: their login lives on the node, and
     * removing the key only stops the next deploy from joining a node that is not in it.
     */
    public function destroyTailscale(TailscaleSettings $tailscale, AppSettings $settings): JsonResponse
    {
        $tailscale->auth_key = '';
        $tailscale->save();

        return response()->json(['data' => AppSettingsData::fromSettings($settings)]);
    }

    /**
     * The zones the stored token can see, asked of Cloudflare each time: it is how the panel
     * shows that a token saved months ago still works, and which hostnames it will cover.
     */
    public function cloudflareZones(CloudflareDns $dns): JsonResponse
    {
        if (! $dns->configured()) {
            return response()->json(['data' => []]);
        }

        try {
            return response()->json(['data' => $dns->zones()]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }
}
