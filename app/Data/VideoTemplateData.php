<?php

namespace App\Data;

use App\Models\TemplateVersion;
use App\Models\Video;
use Spatie\LaravelData\Data;

/** The template a video was encoded with, and which version of it ({@see TemplateVersion}). */
class VideoTemplateData extends Data
{
    public function __construct(
        public string $ulid,
        public string $name,
        /** Null only for a video the versions backfill could not reach. */
        public ?int $version,
    ) {}

    public static function fromVideo(Video $video): ?self
    {
        if (! $video->template) {
            return null;
        }

        return new self(
            ulid: $video->template->ulid,
            name: $video->template->name,
            version: $video->templateVersion?->version,
        );
    }
}
