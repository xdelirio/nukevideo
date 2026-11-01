<?php

namespace App\DTOs;

use App\Models\Project;
use App\Models\User;
use App\Models\Video;
use App\Services\TrackIngestService;

/**
 * What a track upload carries from the moment the multipart upload is created to the moment the
 * bucket webhook ingests it ({@see TrackIngestService}). `user` and `project` mean
 * exactly what they mean on {@see UploadMeta}: the part-signing endpoints authorize against them
 * without knowing which kind of upload they are signing.
 */
class TrackUploadMeta
{
    public function __construct(
        public readonly string $user,
        public readonly string $project,
        public readonly string $video,
        /** `audio` or `subtitle`. */
        public readonly string $type,
        public readonly string $name,
        public readonly ?string $language,
        public readonly bool $forced,
        public readonly bool $hearingImpaired,
        public readonly bool $visualImpaired,
        public readonly string $filename,
        public readonly ?string $externalUserId = null,
    ) {}

    public static function fromRequest(User $user, Project $project, Video $video, array $input): self
    {
        $metadata = $input['metadata'];

        return new self(
            user: $user->ulid,
            project: $project->ulid,
            video: $video->ulid,
            type: $metadata['trackType'],
            name: $metadata['trackName'],
            language: $metadata['language'] ?? null,
            forced: filter_var($metadata['forced'] ?? false, FILTER_VALIDATE_BOOL),
            hearingImpaired: filter_var($metadata['hearingImpaired'] ?? false, FILTER_VALIDATE_BOOL),
            visualImpaired: filter_var($metadata['visualImpaired'] ?? false, FILTER_VALIDATE_BOOL),
            filename: $input['filename'],
            externalUserId: $metadata['externalUserId'] ?? null,
        );
    }
}
