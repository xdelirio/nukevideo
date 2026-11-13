<?php

namespace App\Http\Controllers;

use App\Http\Middleware\VerifyInternalSecret;
use App\Services\Cdn\ManifestOptionsRegistry;
use App\Services\Manifest\DynamicManifestService;
use App\Services\ManifestEditor;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ManifestController extends Controller
{
    public function __construct(
        private DynamicManifestService $manifests,
        private ManifestOptionsRegistry $options,
    ) {}

    /**
     * A master manifest, as the link it was requested with asked for it. Called by the edge, never
     * by a player ({@see VerifyInternalSecret}).
     *
     * The edge has already validated the token — its signature, its expiry, and that its ACL
     * covers this video's play zone — before asking, so nothing is re-checked here: the token
     * arrives only as the key the mint filed the link's options under. One this side does not
     * know, or none at all, is an ordinary link that asked for nothing, and gets the defaults.
     *
     * The body goes out uncompressed and exactly typed. The edge rewrites every URL in it to
     * carry the viewer's token, and its rewriter picks the manifest grammar by Content-Type and
     * cannot read a compressed body.
     */
    public function show(Request $request, string $video, string $file): Response
    {
        $body = $this->manifests->render($video, $file, $this->options->resolve($request->header('X-Link-Token')));

        if ($body === null) {
            abort(404);
        }

        return response($body, 200, [
            'Content-Type' => ManifestEditor::CONTENT_TYPES[DynamicManifestService::formatOf($file)],
            // Per link, so no shared cache may keep it. The built copies are cached on this side.
            'Cache-Control' => 'no-store',
        ]);
    }
}
