<?php

namespace App\Data;

use App\Models\Template;
use Spatie\LaravelData\Data;

class TemplateData extends Data
{
    public function __construct(
        public string $ulid,
        public string $name,
        /** @var array<string, mixed> */
        public array $query,
        // Disabled templates stay listed in the panel but are no longer offered to new uploads.
        public bool $enabled,
        /** The current version of the encoding definition; every change to `query` adds one, and
         *  each video keeps the version it was uploaded with. */
        public int $version,
        public bool $keepProcessedFiles,
        public bool $keepOriginal,
        /** @var string[] */
        public array $commands,
        public string $createdAt,
        public ?string $updatedAt,
    ) {}

    public static function fromModel(Template $template): self
    {
        return new self(
            ulid: $template->ulid,
            name: $template->name,
            query: $template->query ?? [],
            enabled: (bool) $template->enabled,
            version: $template->currentVersion?->version ?? 1,
            keepProcessedFiles: (bool) $template->keep_processed_files,
            keepOriginal: (bool) $template->keep_original,
            commands: $template->buildCommands(),
            createdAt: $template->created_at->toIso8601String(),
            updatedAt: $template->updated_at?->toIso8601String(),
        );
    }
}
