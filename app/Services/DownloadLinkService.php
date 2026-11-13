<?php

namespace App\Services;

use App\Data\DownloadLinkData;
use App\Data\SkippedTrackData;
use App\Data\VideoDownloadLinksData;
use App\Enums\DownloadSkipReason;
use App\Enums\VideoStatus;
use App\Exceptions\NoCdnNodeAvailableException;
use App\Models\Project;
use App\Models\Stream;
use App\Models\Video;
use App\Services\Cdn\EdgeLinkSigner;
use App\Services\Cdn\ProxyRing;
use App\Services\Cdn\SignedLink;
use App\Services\Cdn\TrackingRegistry;
use App\Support\LinkExpiry;
use App\Support\SpeedLimit;

/**
 * Mints download links, for one track or for a video's worth of them.
 *
 * Downloads are per-track and API-only: the packaged renditions carry no audio
 * ({@see ChunkTranscodeService} encodes video with `-an`), so anything playable has to be muxed
 * from the pieces, and that is the caller's job. Each signature stays scoped to the object it
 * hands out — the batch mints N signatures, it does not widen one.
 *
 * The batch exists because almost nothing about a mint varies per track. Auth, the project
 * resolution, the video's status and the proxy node are all per VIDEO, so a caller fetching
 * forty tracks one call at a time paid them forty times — and Sanctum stamps `last_used_at` on
 * every request, so that was also forty writes to one row. Keyed by video rather than by an
 * arbitrary list of streams precisely so that hoisting them is valid.
 */
class DownloadLinkService
{
    /**
     * Types that have an object in the download zone. `original` is absent on purpose: it is the
     * untouched upload, lives in its own zone, and handing it out is a different product decision
     * from offering the encoded tracks.
     */
    private const DOWNLOADABLE = ['video', 'audio', 'subtitle'];

    public function __construct(
        private EdgeLinkSigner $cdn,
        private TrackingRegistry $tracking,
    ) {}

    /**
     * Resolve the track inside the caller's project and mint its link. The scoping lives here, not
     * in the controller: a stream looked up by ULID alone would hand one project's masters to
     * another.
     */
    public function forStreamUlid(
        string $ulid,
        Project $project,
        ?string $trackingId = null,
        ?int $speedLimitKbps = null,
        ?int $expiresIn = null,
    ): DownloadLinkData {
        $stream = Stream::with('video')
            ->whereHas('video', fn ($query) => $query->where('project_id', $project->id))
            ->where('ulid', $ulid)
            ->firstOrFail();

        return $this->forStream($stream, $trackingId, $speedLimitKbps, $expiresIn);
    }

    public function forStream(Stream $stream, ?string $trackingId = null, ?int $speedLimitKbps = null, ?int $expiresIn = null): DownloadLinkData
    {
        if (! in_array($stream->type, self::DOWNLOADABLE, true)) {
            abort(422, "A {$stream->type} track cannot be downloaded.");
        }

        $video = $stream->video;

        if ($video->status !== VideoStatus::COMPLETED->value) {
            abort(409, 'The video is still processing.');
        }

        // A template with `keep_processed_files` off drops the renditions before they ever reach
        // S3, so the row exists and the object does not. Fail here rather than hand out a link
        // that 404s at the CDN, where the caller cannot tell our fault from theirs.
        //
        // The row already answers that. `file_size` is written from the file on disk after the
        // relocation and stays null for exactly the tracks that were dropped
        // ({@see \App\Jobs\PackageVideoJob::recordStoredSizes}), so the HEAD this used to send
        // asked S3 what the record in hand already knew — once per mint, on a path a caller walks
        // once per track. It is also the signal the panel already gates its own download button
        // on (`StreamItem.vue`), so the two sides now agree on one definition of "retained".
        if ($stream->file_size === null) {
            abort(404, 'This track was not retained for download.');
        }

        SpeedLimit::ensureSupported($this->cdn, $speedLimitKbps);
        $lifetime = LinkExpiry::seconds($expiresIn, $this->tracking);

        $key = $stream->storedPath($video);
        $link = $this->sign($video, $key, $speedLimitKbps, $lifetime);

        // The id never enters the URL: the token does, on every request the link produces, and
        // the mint is the only moment anyone knows whose it is.
        $this->tracking->record($link, $trackingId, $lifetime);

        return $this->toData($stream, $key, $link, $lifetime);
    }

    /**
     * Mint every requested track of one video in a single pass.
     *
     * Per-track problems are reported, not thrown: one stale id in a list of forty must not cost
     * the other thirty-nine their links, so they come back in `skipped` with a reason. The 409 and
     * the 503 stay whole-request, because they are conditions of the video and of the fleet — no
     * track could have been served either way.
     *
     * @param  list<string>|null  $ulids  the tracks to mint, or null for every downloadable one
     * @param  int|null  $speedLimitKbps  applied to each link on its own, not shared across the batch
     * @param  int|null  $expiresIn  seconds every link stays valid, null for the token window
     */
    public function forVideo(
        Video $video,
        ?array $ulids = null,
        ?string $trackingId = null,
        ?int $speedLimitKbps = null,
        ?int $expiresIn = null,
    ): VideoDownloadLinksData {
        if ($video->status !== VideoStatus::COMPLETED->value) {
            abort(409, 'The video is still processing.');
        }

        SpeedLimit::ensureSupported($this->cdn, $speedLimitKbps);
        $lifetime = LinkExpiry::seconds($expiresIn, $this->tracking);

        $streams = $video->streams()->get()->keyBy('ulid');

        // An explicit list is answered in the caller's own order and every id in it gets a line,
        // including ones this video has no track for — that is the caller's mistake to see. An
        // omitted list means "every downloadable track", where the untouched original was never
        // asked for and so is not a skip. Duplicates collapse: the same id twice is one track,
        // and minting it twice would burn a second token for nothing.
        $requested = $ulids === null
            ? $streams->filter(fn (Stream $s) => in_array($s->type, self::DOWNLOADABLE, true))->keys()->all()
            : array_values(array_unique($ulids));

        $links = [];
        $signed = [];
        $skipped = [];

        foreach ($requested as $ulid) {
            $stream = $streams->get($ulid);

            $reason = match (true) {
                $stream === null => DownloadSkipReason::NOT_FOUND,
                ! in_array($stream->type, self::DOWNLOADABLE, true) => DownloadSkipReason::NOT_DOWNLOADABLE,
                $stream->file_size === null => DownloadSkipReason::NOT_RETAINED,
                default => null,
            };

            if ($reason !== null) {
                $skipped[] = new SkippedTrackData(ulid: $ulid, reason: $reason);

                continue;
            }

            $key = $stream->storedPath($video);
            $link = $this->sign($video, $key, $speedLimitKbps, $lifetime);

            $signed[] = $link;
            $links[] = $this->toData($stream, $key, $link, $lifetime);
        }

        // One cache round trip for the batch rather than one per track.
        $this->tracking->recordMany($signed, $trackingId, lifetime: $lifetime);

        return new VideoDownloadLinksData(links: $links, skipped: $skipped);
    }

    /**
     * The node behind this is resolved once per request, not once per call
     * ({@see ProxyRing}), which is what makes signing in a loop cheap.
     */
    private function sign(Video $video, string $key, ?int $speedLimitKbps, int $lifetime): SignedLink
    {
        try {
            return $this->cdn->downloadUrl($video->ulid, $key, $speedLimitKbps, $lifetime);
        } catch (NoCdnNodeAvailableException) {
            // Same answer playback gives ({@see VodLinkService}): the track
            // exists and the caller is entitled to it, there is just nothing to serve it right now.
            abort(503, 'No node available');
        }
    }

    private function toData(Stream $stream, string $key, SignedLink $link, int $lifetime): DownloadLinkData
    {
        return new DownloadLinkData(
            url: $link->url,
            expiresAt: now()->addSeconds($lifetime)->toIso8601String(),
            // The stored name as-is: a ULID plus its extension. Unique by construction, so a caller
            // fetching several tracks never has two land on the same name, and there is nothing to
            // sanitise before it touches a filesystem or a Content-Disposition header.
            filename: basename($key),
            type: $stream->type,
            size: $stream->file_size,
        );
    }
}
