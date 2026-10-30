<?php

namespace App\Data;

use App\Enums\StreamStatus;
use App\Models\Stream;
use Spatie\LaravelData\Data;

class StreamData extends Data
{
    public function __construct(
        public string $ulid,
        /** Null for video renditions — they carry no label anywhere; the panel shows their height. */
        public ?string $name,
        public string $type,
        /** Only set on a track added after the video completed; null reads as ready. */
        public ?StreamStatus $status,
        public ?int $packageSize,
        public ?int $fileSize,
        /** @var array<string, mixed>|null */
        public ?array $inputParams,
        /** @var array<string, mixed>|null */
        public ?array $meta,
        public ?int $width,
        public ?int $height,
        public ?string $language,
        public bool $forced,
        public bool $hearingImpaired,
        public ?int $channels,
        public ?string $errorLog,
        public string $createdAt,
    ) {}

    public static function fromModel(Stream $stream): self
    {
        return new self(
            ulid: $stream->ulid,
            name: $stream->name,
            type: $stream->type,
            status: $stream->status,
            packageSize: $stream->package_size,
            fileSize: $stream->file_size,
            inputParams: $stream->input_params,
            meta: $stream->meta,
            width: $stream->width,
            height: $stream->height,
            language: $stream->language,
            forced: $stream->forced,
            hearingImpaired: (bool) data_get($stream->meta, 'hearing_impaired', false),
            channels: $stream->channels,
            errorLog: $stream->error_log,
            createdAt: $stream->created_at->toIso8601String(),
        );
    }
}
