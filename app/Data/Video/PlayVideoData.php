<?php

namespace App\Data\Video;

use App\Data\RequestData;
use App\Enums\SubtitleFormat;
use App\Models\Output;
use App\Services\Cdn\EdgeLinkSigner;
use App\Services\Cdn\TrackingRegistry;
use App\Support\LinkExpiry;
use App\Support\SpeedLimit;
use App\Support\TrackingId;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\Validation\IP;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Mappers\CamelCaseMapper;

/**
 * The body of the playback mint. Every field is optional, and none of them selects anything: the
 * answer carries every manifest the video can serve, and the client picks from it by codec and
 * format. What is left is the two things the SERVER cannot know — how tall the viewer's player
 * wants the ladder, and who the viewer is.
 *
 * There is no list of output ULIDs either. What this endpoint hoists — auth, the project, the
 * status check, the delivery node — is per video, so the honest key is the video and nothing
 * narrower.
 */
class PlayVideoData extends RequestData
{
    public function __construct(
        /** Caps every output's ladder at this height; each one resolves it against its own
         *  renditions ({@see Output::resolveCap}). */
        #[Min(144), Max(4320)]
        public ?int $resolution = null,
        /**
         * The address that will fetch the manifests, when minting from your own backend. It is
         * signed into the token only when the operator turns on `bindIp`, and the edge then
         * refuses the link from any other address ({@see EdgeLinkSigner::sign}) — so with that
         * on, a wrong address is a link that never plays.
         */
        #[IP]
        public ?string $ip = null,
        /**
         * Your own label for this viewer. Never part of a URL: it is recorded against every token
         * the mint hands out ({@see TrackingRegistry::recordMany}). One id for the whole answer,
         * because one answer is one viewer opening one video.
         */
        #[MapInputName(CamelCaseMapper::class)]
        public ?string $trackingId = null,
        /**
         * Caps the transfer rate of every request the session makes — the manifests and each
         *  segment, which inherit the manifest's token. Per request, not per viewer: a player
         *  fetching two segments at once gets twice this. An ABR player measures it as its
         *  throughput and settles on the renditions that fit under it, in kilobits per second ({@see SpeedLimit}). Signed into
         * the token, so the viewer cannot lift it by editing the URL. Omit it for no limit.
         */
        #[MapInputName(CamelCaseMapper::class)]
        public ?int $speedLimitKbps = null,
        /** The links are for an offline download: their bytes count as `download_bytes`
         *  instead of `streaming_bytes`. Omitted or null is streaming. */
        public ?bool $download = null,
        /**
         * Seconds the links stay valid ({@see LinkExpiry}): 60 to a week. Omit it for the CDN's
         * token window — an hour unless the operator changed it.
         */
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

    /**
     * One key per field: the properties that map camelCase do so explicitly, so the mapped name
     * and the bare property name Spatie also binds are the same string
     * ({@see DownloadVideoTracksData::rules()} for why that matters).
     */
    public static function rules(): array
    {
        return [
            'trackingId' => TrackingId::rules(),
            'speedLimitKbps' => SpeedLimit::rules(),
            'expiresIn' => LinkExpiry::rules(),
        ];
    }
}
