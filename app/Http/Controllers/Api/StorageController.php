<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The project's storage, as an integration bills or caps it. Project context is required: storage
 * is always somebody's catalogue, and there is no instance-wide figure to share.
 */
class StorageController extends Controller
{
    public function __construct(
        private StorageService $storage,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->storage->forProject($request->project())]);
    }
}
