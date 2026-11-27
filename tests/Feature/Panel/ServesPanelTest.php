<?php

/**
 * The release image serves the admin panel from the API's origin: any path no route owns gets the
 * panel's index.html, so a reload on `/videos/…` works. What must not change is the API itself — an
 * unknown `/api/…` path stays a JSON 404 — and where the file is absent nothing is served at all.
 */

use App\Http\Controllers\PanelController;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->index = resource_path(PanelController::INDEX);
    $this->existed = is_file($this->index);
});

afterEach(function () {
    if (! $this->existed) {
        File::delete($this->index);
        @rmdir(dirname($this->index));
    }
});

function installPanel(string $html = '<!doctype html><div id="app"></div>'): void
{
    File::ensureDirectoryExists(dirname(resource_path(PanelController::INDEX)));
    File::put(resource_path(PanelController::INDEX), $html);
}

it('answers a panel page with the panel, revalidated on every load', function () {
    installPanel();

    $response = $this->get('/videos/01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('text/html')
        ->and($response->headers->get('Cache-Control'))->toContain('no-cache')
        ->and($response->getFile()->getPathname())->toBe(resource_path(PanelController::INDEX));
});

it('answers the root with the panel', function () {
    installPanel();

    $this->get('/')->assertOk();
});

it('keeps an unknown API path a JSON 404', function () {
    installPanel();

    $this->getJson('/api/no-such-endpoint')->assertNotFound()->assertJsonStructure(['message']);
    $this->get('/api')->assertNotFound();
});

it('leaves the health check alone', function () {
    installPanel();

    $this->get('/up')->assertOk();
});

it('serves nothing where the panel is not installed', function () {
    if ($this->existed) {
        $this->markTestSkipped('A built panel is installed in this checkout.');
    }

    $this->get('/videos/01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertNotFound();
});
