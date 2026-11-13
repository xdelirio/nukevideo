<?php

declare(strict_types=1);

namespace App\Services\Cdn;

use App\Exceptions\NoCdnNodeAvailableException;
use App\Http\Controllers\VideoController;
use App\Models\Video;

/**
 * Where a client should fetch a video's thumbnail or storyboard.
 *
 * Prefers the CDN: the edge serves the `assets/` zone unsigned, so the URL is stable and both the
 * browser and the edge cache can keep it — which is the whole point, since these are rendered once
 * per row in a listing. Bytes stop passing through PHP entirely.
 *
 * Falls back to the API route ({@see VideoController::getAsset}) when no
 * proxy node is available, so images still resolve on an installation with no CDN fleet — which is
 * every fresh one, and every local checkout.
 */
class AssetUrlResolver
{
    public function __construct(private EdgeLinkSigner $cdn) {}

    public function for(string $videoUlid, string $filename): string
    {
        try {
            return $this->cdn->assetUrl(
                $videoUlid,
                Video::assetKeyFor($videoUlid, $filename),
            );
        } catch (NoCdnNodeAvailableException) {
            return url('/api/videos/'.Video::assetPath($videoUlid, $filename));
        }
    }
}
