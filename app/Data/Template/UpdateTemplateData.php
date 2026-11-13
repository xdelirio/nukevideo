<?php

namespace App\Data\Template;

use App\Data\RequestData;
use App\Rules\TemplateAudioRule;
use App\Rules\TemplateFormatRule;
use App\Rules\TemplateVideoCodecRule;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Optional;

class UpdateTemplateData extends RequestData
{
    public function __construct(
        public string|Optional $name,
        public bool|Optional $enabled,
        #[MapInputName(CamelCaseMapper::class)]
        public bool|Optional $keepProcessedFiles,
        #[MapInputName(CamelCaseMapper::class)]
        public bool|Optional $keepOriginal,
        /** @var array<string, mixed> */
        public array|Optional $query,
    ) {}

    public static function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'enabled' => 'sometimes|boolean',
            'keepProcessedFiles' => 'sometimes|boolean',
            'keepOriginal' => 'sometimes|boolean',
            // Both halves are needed. `required_with` alone still lets `query: []` through:
            // `required_with` treats an empty countable as "the field it depends on is itself
            // absent" and short-circuits, and the non-implicit `array|min:1` are then skipped for
            // a missing key. Either way the result was a template that encodes nothing, and every
            // upload using it failed only after the source had been downloaded and mirrored.
            'query' => 'sometimes|array|min:1',
            // Bounded: every output is a ladder of its own and every variant a rendition, each
            // encoded window by window, so one upload costs outputs × variants × windows jobs.
            'query.outputs' => 'required_with:query|array|min:1|max:10',
            'query.outputs.*.video_codec' => ['required', 'string', new TemplateVideoCodecRule],
            'query.outputs.*.variants' => 'required|array|min:1|max:20',
            'query.outputs.*.variants.*' => new TemplateFormatRule,
            'query.outputs.*.audio' => ['required', 'array', new TemplateAudioRule],
        ];
    }
}
