<?php

namespace App\Data\Video;

use App\Data\RequestData;
use App\Enums\SubtitleFormat;
use App\Support\LinkExpiry;
use App\Support\SpeedLimit;
use App\Support\TrackingId;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\Validation\IP;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Mappers\CamelCaseMapper;

/**
 * The body of the batch playback mint: the videos to mint for, and the same knobs as the
 * single-video mint ({@see PlayVideoData}), applied to every video in the list.
 */
class PlayVideosData extends RequestData
{
    /**
     * Ceiling on one batch. It bounds the work and the answer — every video costs its outputs'
     * signatures and a few kilobytes of URLs — so a list longer than a page of a catalogue is
     * a list to split into pages.
     */
    public const MAX_VIDEOS = 500;

    public function __construct(
        /** @var list<string> */
        public array $videos,
        #[Min(144), Max(4320)]
        public ?int $resolution = null,
        #[IP]
        public ?string $ip = null,
        #[MapInputName(CamelCaseMapper::class)]
        public ?string $trackingId = null,
        #[MapInputName(CamelCaseMapper::class)]
        public ?int $speedLimitKbps = null,
        public ?bool $download = null,
        #[MapInputName(CamelCaseMapper::class)]
        public ?int $expiresIn = null,
        /**
         * How the DASH manifests of this answer carry their subtitles ({@see SubtitleFormat}).
         * Omitted is `vtt`: each text track points at one plain `.vtt` file. `segments` asks for
         * the packaged fragmented-MP4 form instead. Never part of a URL: it is filed under the
         * links' tokens and applied when a manifest is requested.
         */
        #[MapInputName(CamelCaseMapper::class)]
        public ?SubtitleFormat $subtitlesFormat = null,
    ) {}

    /** One key per field, as {@see PlayVideoData::rules()}. */
    public static function rules(): array
    {
        return [
            'videos' => ['required', 'array', 'min:1', 'max:'.self::MAX_VIDEOS],
            'videos.*' => ['string', 'size:26'],
            'trackingId' => TrackingId::rules(),
            'speedLimitKbps' => SpeedLimit::rules(),
            'expiresIn' => LinkExpiry::rules(),
        ];
    }
}
