<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class SettingsController extends Controller
{
    public function versionCheck(): JsonResponse
    {
        $current = config('app.version');

        $releases = Cache::remember('github_releases', 3600, function () {
            $response = Http::withHeaders(['Accept' => 'application/vnd.github+json'])
                ->get('https://api.github.com/repos/xdelirio/nukevideo/releases', ['per_page' => 50]);

            if ($response->failed()) {
                return [];
            }

            return collect($response->json())
                ->filter(fn ($r) => ! $r['draft'] && ! $r['prerelease'])
                ->map(fn ($r) => $r['tag_name'])
                ->values()
                ->all();
        });

        $currentIndex = array_search("v{$current}", $releases);
        $behind = $currentIndex !== false ? $currentIndex : count($releases);

        return response()->json([
            'data' => [
                'current' => $current,
                'latest' => $releases[0] ?? "v{$current}",
                'behind' => $behind,
            ],
        ]);
    }
}
