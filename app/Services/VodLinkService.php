<?php

namespace App\Services;

use App\Data\Video\ManifestOptions;
use App\Data\VideoVodLinksData;
use App\Data\VodSourceData;
use App\Data\VodSubtitleData;
use App\Enums\StreamStatus;
use App\Enums\SubtitleFormat;
use App\Enums\VideoStatus;
use App\Exceptions\NoCdnNodeAvailableException;
use App\Jobs\PackageVideoJob;
use App\Models\Output;
use App\Models\Stream;
use App\Models\Video;
use App\Services\Cdn\AssetUrlResolver;
use App\Services\Cdn\EdgeLinkSigner;
use App\Services\Cdn\ManifestOptionsRegistry;
use App\Services\Cdn\ProxyRing;
use App\Services\Cdn\SignedLink;
use App\Services\Cdn\TrackingRegistry;
use App\Support\LinkExpiry;
use App\Support\SpeedLimit;
use Illuminate\Validation\ValidationException;

/**
 * Mints a whole video's playback links in one pass.
 *
 * The same argument as {@see DownloadLinkService::forVideo}: auth, the project resolution, the
 * video's status and the delivery node are per VIDEO, so a player that has to try three outputs
 * — or one output in two formats — paid all of it once per manifest, and Sanctum stamps
 * `last_used_at` on every one of those requests, all to the same row.
 *
 * Playback makes the case sharper than downloads did. A download token is scoped to its one
 * object, so N tracks genuinely need N signatures; a playback token is scoped to the manifest's
 * DIRECTORY — an ACL with a trailing `*` on the edge ({@see EdgeLinkSigner::aclFor}) — and
 * every output of a video packages into the same `{videoUlid}/play/` prefix
 * ({@see Output::packagePrefix}). So the signatures this loop produces all authorize exactly the
 * same bytes. Collapsing them to one is a change inside {@see EdgeLinkSigner} and needs
 * nothing from here — which is why this mints the obvious way and stays correct either way.
 */
class VodLinkService
{
    public function __construct(
        private EdgeLinkSigner $cdn,
        private TrackingRegistry $tracking,
        private AssetUrlResolver $assets,
        private ManifestOptionsRegistry $manifestOptions,
    ) {}

    /**
     * Every manifest of the video that can be played, one per output and format.
     *
     * An output that cannot be served is left out, not thrown. A video is `completed` once at
     * least one output succeeded, so a failed sibling is an ordinary state and must not cost the
     * survivors their links; what a caller does about it is the same either way — play one of the
     * outputs that came back. The 409 and the 503 stay whole-request: they are conditions of the
     * video and of the fleet, under which nothing could have been served.
     *
     * @param  string  $ip  the address that will fetch the manifests
     * @param  int|null  $speedLimitKbps  per-request cap on every manifest and segment of the session
     * @param  bool  $download  the links are for an offline download, booked as `download_bytes`
     * @param  int|null  $expiresIn  seconds the links stay valid, null for the token window
     */
    public function forVideo(
        Video $video,
        ?int $resolution,
        string $ip,
        ?string $trackingId = null,
        ?int $speedLimitKbps = null,
        bool $download = false,
        ?int $expiresIn = null,
        ?SubtitleFormat $subtitlesFormat = null,
    ): VideoVodLinksData {
        if ($video->status !== VideoStatus::COMPLETED->value) {
            abort(409, 'The video is still processing.');
        }

        SpeedLimit::ensureSupported($this->cdn, $speedLimitKbps);
        $manifestKey = $this->manifestKey($resolution, $subtitlesFormat);
        $lifetime = LinkExpiry::seconds($expiresIn, $this->tracking);
        $signed = [];

        $links = $this->links($video, $ip, $speedLimitKbps, $lifetime, now()->addSeconds($lifetime)->toIso8601String(), $signed, $manifestKey);

        // One cache round trip for the whole answer rather than one per manifest.
        $this->tracking->recordMany($signed, $trackingId, $download, $lifetime);

        return $links;
    }

    /**
     * The same links for many videos at once, in the order they were asked for — the ones that
     * can be played. A video that is not here (not in the caller's project, or no such ULID) or
     * not playable yet is simply absent, as an output that cannot be served is absent from
     * `sources`: what a caller does about either is the same, and the list it sent says which
     * ones are missing. The 503 stays whole-request, a condition of the fleet.
     *
     * Everything a mint repeats per request is done once for the batch: the speed-limit check,
     * the lifetime, the expiry every link shares, and the one cache write that attributes them.
     *
     * @param  iterable<Video>  $videos  already scoped to the caller's project, outputs and streams loaded
     * @param  list<string>  $ulids  the order to answer in
     * @return list<VideoVodLinksData>
     */
    public function forVideos(
        iterable $videos,
        array $ulids,
        ?int $resolution,
        string $ip,
        ?string $trackingId = null,
        ?int $speedLimitKbps = null,
        bool $download = false,
        ?int $expiresIn = null,
        ?SubtitleFormat $subtitlesFormat = null,
    ): array {
        SpeedLimit::ensureSupported($this->cdn, $speedLimitKbps);
        $manifestKey = $this->manifestKey($resolution, $subtitlesFormat);
        $lifetime = LinkExpiry::seconds($expiresIn, $this->tracking);
        $expiresAt = now()->addSeconds($lifetime)->toIso8601String();

        $byUlid = [];
        foreach ($videos as $video) {
            $byUlid[$video->ulid] = $video;
        }

        $answer = [];
        $signed = [];

        foreach (array_unique($ulids) as $ulid) {
            $video = $byUlid[$ulid] ?? null;

            if ($video !== null && $video->status === VideoStatus::COMPLETED->value) {
                $answer[] = $this->links($video, $ip, $speedLimitKbps, $lifetime, $expiresAt, $signed, $manifestKey);
            }
        }

        $this->tracking->recordMany($signed, $trackingId, $download, $lifetime);

        return $answer;
    }

    /**
     * The key this mint's manifest links carry for what it asks of them — null when it asks for
     * nothing but the defaults. Refused up front when it cannot be honoured.
     *
     * The key rides in each link's token, so a deployment that does not sign has nowhere to put
     * it, and every manifest it serves is built with the defaults. A 422 for the same reason a
     * speed limit gets one ({@see SpeedLimit::ensureSupported}): the caller asked because
     * something depends on it, and a link that silently ignores it is the one answer they cannot
     * detect.
     */
    private function manifestKey(?int $resolution, ?SubtitleFormat $subtitlesFormat): ?string
    {
        $options = new ManifestOptions($resolution, $subtitlesFormat ?? SubtitleFormat::VTT);

        if (! $options->isDefault() && ! $this->cdn->signs()) {
            throw ValidationException::withMessages([
                $resolution !== null ? 'resolution' : 'subtitlesFormat' => 'Unsigned links cannot carry manifest options.',
            ]);
        }

        return $this->manifestOptions->keyFor($options);
    }

    /**
     * One video's answer. Appends every link it signs to `$signed`, for the caller to attribute
     * all of them in one write.
     *
     * @param  list<SignedLink>  $signed
     * @param  string|null  $manifestKey  what the manifest links are built with ({@see manifestKey})
     */
    private function links(Video $video, string $ip, ?int $speedLimitKbps, int $lifetime, string $expiresAt, array &$signed, ?string $manifestKey): VideoVodLinksData
    {
        $sources = [];

        foreach ($video->outputs as $output) {
            // The manifest path reads the output's video, which is this one: handed over rather
            // than lazy-loaded, which was a query per output — per video, in a batch.
            $output->setRelation('video', $video);
            $formats = $this->playableFormats($output);

            if ($formats === []) {
                continue;
            }

            // Resolved once per output, not once per format: the codecs cost a pass over the
            // streams each, and both manifests of one output carry the same ones.
            $videoCodec = $output->videoCodec();
            $audioCodec = $output->audioCodec();

            foreach ($formats as $format) {
                $link = $this->sign($video, $output->manifestPath($format), $ip, $speedLimitKbps, $lifetime, $manifestKey);
                $signed[] = $link;

                $sources[] = new VodSourceData(
                    url: $link->url,
                    format: $format,
                    outputUlid: $output->ulid,
                    videoCodec: $videoCodec,
                    audioCodec: $audioCodec,
                );
            }
        }

        return new VideoVodLinksData(
            videoUlid: $video->ulid,
            // Per-video and unsigned: the same two URLs whatever the caller ends up playing.
            thumbnailUrl: $this->assets->for($video->ulid, Video::THUMBNAIL_FILENAME),
            storyboardUrl: $this->assets->for($video->ulid, Video::STORYBOARD_VTT_FILENAME),
            expiresAt: $expiresAt,
            sources: $sources,
            subtitles: $this->subtitles($video, $lifetime, $signed),
        );
    }

    /**
     * The video's subtitle tracks as standalone `.vtt` links, for a player that cannot read its
     * text tracks out of the manifest.
     *
     * These point at the DOWNLOAD zone, not at the packaged track next to the manifest, because
     * the two are different files. What sits in `play/` is a packager segment — fragmented MP4 for
     * DASH, and for HLS a WebVTT that only an HLS client resolves against the playlist around it —
     * while the download zone holds the sanitized WebVTT the packager was fed: one plain file a
     * `<track>` element loads as it is. It is also there for every video, whatever the template's
     * `keep_processed_files` says ({@see PackageVideoJob::OPTIONAL_TYPES}).
     *
     * So each one is a download signature: scoped to its own object, and not bound to the viewer's
     * address ({@see EdgeLinkSigner::downloadUrl}). Neither costs anything here — a subtitle is the
     * one track whose content is no more valuable than the playback it accompanies — and the edge
     * answers that zone with the same open CORS a cross-origin `<track>` needs.
     *
     * No speed limit is signed in: it exists to shape the video's bitrate, and all it could do to
     * a file of a few kilobytes is delay the first cue.
     *
     * Appends what it signs to `$signed` like the manifests, so a tracking id covers these too.
     *
     * @param  list<SignedLink>  $signed
     * @return list<VodSubtitleData>
     */
    private function subtitles(Video $video, int $lifetime, array &$signed): array
    {
        $subtitles = [];

        foreach ($video->streams->sortBy('id') as $stream) {
            if (! $this->isServableSubtitle($stream)) {
                continue;
            }

            try {
                $link = $this->cdn->downloadUrl($video->ulid, $stream->storedPath($video), expiresIn: $lifetime);
            } catch (NoCdnNodeAvailableException) {
                abort(503, 'No node available');
            }

            $signed[] = $link;

            $subtitles[] = new VodSubtitleData(
                url: $link->url,
                streamUlid: $stream->ulid,
                language: $stream->language,
                name: $stream->name,
                forced: (bool) $stream->forced,
                hearingImpaired: (bool) data_get($stream->meta, 'hearing_impaired', false),
            );
        }

        return $subtitles;
    }

    /**
     * A subtitle whose file is actually in the download zone. `file_size` is the record of that
     * ({@see DownloadLinkService::forStream}): it is written from the file that was synced, so a
     * track still being added, or one whose add failed, has none and is left out rather than
     * handed out as a link that 404s at the CDN. The status check covers the same two states by
     * name; a null status is a track of the video's own run, which is ready by definition.
     */
    private function isServableSubtitle(Stream $stream): bool
    {
        return $stream->type === 'subtitle'
            && $stream->file_size !== null
            && in_array($stream->status, [null, StreamStatus::READY], true);
    }

    /**
     * The formats to mint for one output — empty when there is nothing to serve, which is what
     * keeps the output out of the answer entirely.
     *
     * The status check is not redundant with the video's. A video is `completed` once at least one
     * output succeeded, so a failed output sits under a completed video, and `formats()` falls
     * back to a live computation from the attached streams while `packaged_formats` is null
     * ({@see Output::formats}) — exactly the state a failed output is in. Minting from that names
     * a manifest that was never written, and the link 404s at the CDN, where the caller cannot
     * tell our fault from theirs. Same reasoning as {@see DownloadLinkService::forStream}'s
     * `file_size` check.
     *
     * @return list<string>
     */
    private function playableFormats(Output $output): array
    {
        return $output->status === VideoStatus::COMPLETED ? $output->formats() : [];
    }

    /**
     * The node behind this is resolved once per request, not once per call ({@see ProxyRing}),
     * which is what makes signing in a loop cheap.
     */
    private function sign(Video $video, string $path, string $ip, ?int $speedLimitKbps, int $lifetime, ?string $manifestKey): SignedLink
    {
        try {
            return $this->cdn->manifestUrl($video, $path, $ip, $speedLimitKbps, $lifetime, $manifestKey);
        } catch (NoCdnNodeAvailableException) {
            // The same answer the per-output mint gives: the video exists and the caller is
            // entitled to it, there is just nothing to serve it right now.
            abort(503, 'No node available');
        }
    }
}
