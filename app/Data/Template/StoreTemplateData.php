<?php

namespace App\Data\Template;

use App\Data\RequestData;
use App\Rules\TemplateAudioRule;
use App\Rules\TemplateFormatRule;
use App\Rules\TemplateVideoCodecRule;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Optional;

class StoreTemplateData extends RequestData
{
    public function __construct(
        public string $name,
        public bool|Optional $enabled,
        #[MapInputName(CamelCaseMapper::class)]
        public bool|Optional $keepProcessedFiles,
        #[MapInputName(CamelCaseMapper::class)]
        public bool|Optional $keepOriginal,
        /** @var array<string, mixed> */
        public array $query,
    ) {}

    public static function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'enabled' => 'sometimes|boolean',
            'keepProcessedFiles' => 'sometimes|boolean',
            'keepOriginal' => 'sometimes|boolean',
            // Bounded: every output is a ladder of its own and every variant a rendition, each
            // encoded window by window, so one upload costs outputs × variants × windows jobs.
            'query.outputs' => 'required|array|min:1|max:10',
            'query.outputs.*.video_codec' => ['required', 'string', new TemplateVideoCodecRule],
            'query.outputs.*.variants' => 'required|array|min:1|max:20',
            'query.outputs.*.variants.*' => new TemplateFormatRule,
            'query.outputs.*.audio' => ['required', 'array', new TemplateAudioRule],
        ];
    }
}
