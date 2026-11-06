<?php

namespace App\Services;

use App\DTOs\TrackUploadMeta;
use App\DTOs\UploadMeta;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class UppyS3Service
{
    /** How long an upload stays continuable: past it, its parts can no longer be listed, signed or completed. */
    public const META_TTL_DAYS = 7;

    public function generateKey(string $filename): string
    {
        return FileService::generateKey($filename);
    }

    public function storeUploadMeta(string $key, UploadMeta|TrackUploadMeta $meta): void
    {
        Cache::put("upload_meta:{$key}", $meta, now()->addDays(self::META_TTL_DAYS));
    }

    public function buildUploadMeta(User $user, Project $project, array $input): UploadMeta
    {
        return UploadMeta::fromRequest($user, $project, $input);
    }

    public function getUploadMeta(string $key): UploadMeta|TrackUploadMeta|null
    {
        return Cache::get("upload_meta:{$key}");
    }

    public function forgetUploadMeta(string $key): void
    {
        Cache::forget("upload_meta:{$key}");
    }
}
