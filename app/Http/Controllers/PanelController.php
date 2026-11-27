<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the admin panel, a single-page app, on every path the API does not own. The release image
 * builds the panel into `public/` (its assets, which nginx serves as files) and puts its
 * `index.html` here, outside `public/`: nginx would otherwise answer `/` with it straight from disk,
 * with nothing to stop a browser from keeping it, and a kept `index.html` names asset files the
 * next release no longer ships.
 *
 * Registered as the router's fallback, so it only sees what no route matched: a panel page like
 * `/videos/…` on a reload, or `/`. An unmatched `/api/…` path is still a JSON 404 — an integrator
 * calling a mistyped endpoint must not get the panel's HTML with a 200.
 *
 * Without the file (development, where Vite serves the panel; or a panel hosted elsewhere) every
 * such path is a 404, as before.
 */
class PanelController extends Controller
{
    public const INDEX = 'panel/index.html';

    public function __invoke(Request $request): BinaryFileResponse
    {
        abort_if($request->is('api', 'api/*'), 404);

        $index = resource_path(self::INDEX);

        abort_unless(is_file($index), 404);

        return response()->file($index, [
            'Content-Type' => 'text/html; charset=UTF-8',
            // Revalidated on every load: it is the one file whose name does not change per release.
            'Cache-Control' => 'no-cache',
        ]);
    }
}
