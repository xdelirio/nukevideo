<?php

namespace App\Data;

use App\Enums\VideoPriority;
use App\Enums\VideoStatus;
use App\Models\Stream;
use App\Models\Video;
use App\Services\Cdn\AssetUrlResolver;
use Spatie\LaravelData\Data;

class VideoData extends Data
{
    public function __construct(
        public string $ulid,
        public string $name,
        public float $duration,
        public string $aspectRatio,
        public VideoStatus $status,
        public VideoPriority $priority,
        public string $createdAt,
        public string $updatedAt,
        public ?string $externalUserId,
        public ?string $externalResourceId,
        /** The template, and the version of it, this video was encoded with. Later edits to the
         *  template do not change it. Null once the template is gone. */
        public ?VideoTemplateData $template,
        public string $thumbnailUrl,
        public string $storyboardUrl,
        /** @var OutputData[] */
        public array $outputs,
        /** @var StreamData[] */
        public array $streams,
        // Total bytes stored on S3 (CMAF packages + kept processed files + the original while it exists).
        public int $size,
        // Playback-only bytes (CMAF packages); `size` minus this is the retained processed/source overhead.
        public int $servedSize,
    ) {}

    public static function fromModel(Video $video): self
    {
        $assets = app(AssetUrlResolver::class);

        return new self(
            ulid: $video->ulid,
            name: $video->name,
            duration: $video->duration,
            aspectRatio: $video->aspect_ratio,
            status: VideoStatus::from($video->status),
            priority: VideoPriority::from($video->priority),
            createdAt: $video->created_at->toIso8601String(),
            updatedAt: $video->updated_at->toIso8601String(),
            externalUserId: $video->external_user_id,
            externalResourceId: $video->external_resource_id,
            template: VideoTemplateData::fromVideo($video),
            thumbnailUrl: $assets->for($video->ulid, Video::THUMBNAIL_FILENAME),
            storyboardUrl: $assets->for($video->ulid, Video::STORYBOARD_VTT_FILENAME),
            outputs: OutputData::collect($video->outputs)->all(),
            streams: StreamData::collect($video->streams)->all(),
            size: $video->streams->sum(fn (Stream $s) => (int) $s->package_size + (int) $s->file_size),
            servedSize: $video->streams->sum(fn (Stream $s) => (int) $s->package_size),
        );
    }
}
