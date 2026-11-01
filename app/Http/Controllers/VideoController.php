<?php

namespace App\Http\Controllers;

use App\Console\Commands\RetryVideos;
use App\Data\Video\DownloadVideoTracksData;
use App\Data\Video\IndexVideosData;
use App\Data\Video\PlayVideoData;
use App\Data\Video\PlayVideosData;
use App\Data\Video\UpdateVideoData;
use App\Data\VideoData;
use App\Models\Stream;
use App\Models\Video;
use App\Services\DownloadLinkService;
use App\Services\VideoService;
use App\Services\VodLinkService;
use App\Support\TrackingId;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class VideoController extends Controller
{
    public function __construct(
        protected VideoService $videoService,
        protected DownloadLinkService $downloads,
        protected VodLinkService $vod,
    ) {}

    public function index(Request $request, IndexVideosData $data)
    {
        $query = $request->project()->videos()
            ->with(['outputs.streams', 'streams', 'template', 'templateVersion']);

        if ($data->search) {
            $query->where('name', 'like', "%{$data->search}%");
        }

        if ($data->externalUserId) {
            $query->where('external_user_id', $data->externalUserId);
        }

        if ($data->externalResourceId) {
            $query->where('external_resource_id', $data->externalResourceId);
        }

        if ($statuses = $data->statuses()) {
            $query->whereIn('status', $statuses);
        }

        if ($data->updatedSince) {
            $query->where('updated_at', '>=', Carbon::parse($data->updatedSince));
        }

        // `size` is not a column: it is what the listing shows, the sum of every stream's package
        // and file bytes ({@see VideoData::fromModel}), so it is ordered by the same sum as a
        // correlated subquery. The others are plain columns. `id` is the tie-breaker on every
        // sort: without it two equal names could swap between pages as the planner pleases.
        $sort = $data->sort === 'size'
            ? Stream::selectRaw('COALESCE(SUM(package_size + file_size), 0)')->whereColumn('video_id', 'videos.id')
            : $data->sort;

        $query->orderBy($sort, $data->direction)->orderBy('id', $data->direction);

        $videos = $query->paginate($data->perPage);

        return [
            'data' => array_map(fn ($v) => VideoData::fromModel($v), $videos->items()),
            'currentPage' => $videos->currentPage(),
            'perPage' => $videos->perPage(),
            'total' => $videos->total(),
        ];
    }

    /**
     * Signed links for a video's tracks in one pass.
     *
     * Keyed by video rather than by an arbitrary list of streams: what the batch hoists — the
     * status check, the proxy node, the caller's auth — is per video, and the route is what makes
     * that invariant true instead of a rule someone has to remember.
     */
    public function downloads(Request $request, DownloadVideoTracksData $data, string $ulid)
    {
        $video = $request->project()->videos()->where('ulid', $ulid)->firstOrFail();

        return response()->json([
            'data' => $this->downloads->forVideo(
                $video,
                $data->streamUlids,
                TrackingId::resolve($data->trackingId, $request->user()),
                $data->speedLimitKbps,
                $data->expiresIn,
            ),
        ]);
    }

    /**
     * Signed playback links for every output of a video, in one pass.
     *
     * Keyed by video for the same reason the batch download is ({@see downloads}), and with the
     * same effect on the caller: a player no longer has to know which output to ask for before it
     * can ask for anything. This replaced the per-output mint outright — there was no question a
     * caller could answer better one output at a time.
     */
    public function play(Request $request, PlayVideoData $data, string $ulid)
    {
        $video = $request->project()->videos()
            ->with('outputs.streams')
            ->where('ulid', $ulid)->firstOrFail();

        return response()->json([
            'data' => $this->vod->forVideo(
                $video,
                $data->resolution,
                // The address that will actually fetch the manifests, so an integrator minting
                // from its own backend must name the end viewer; falls back to the caller's own,
                // which is right when the player itself is asking.
                $data->ip ?? $request->ip(),
                TrackingId::resolve($data->trackingId, $request->user()),
                $data->speedLimitKbps,
                $data->download ?? false,
                $data->expiresIn,
            ),
        ]);
    }

    /**
     * Playback links for many videos in one request: a page of a catalogue, say. The same answer
     * as {@see play}, one entry per video that can be played, in the order asked. Scoped like
     * every other lookup — a ULID from another project matches nothing and is simply absent.
     */
    public function playMany(Request $request, PlayVideosData $data)
    {
        $videos = $request->project()->videos()
            ->with('outputs.streams')
            ->whereIn('ulid', $data->videos)
            ->get();

        return response()->json([
            'data' => $this->vod->forVideos(
                $videos,
                $data->videos,
                $data->resolution,
                $data->ip ?? $request->ip(),
                TrackingId::resolve($data->trackingId, $request->user()),
                $data->speedLimitKbps,
                $data->download ?? false,
                $data->expiresIn,
            ),
        ]);
    }

    public function show(Request $request, string $ulid)
    {
        $video = $request->project()->videos()
            ->with(['outputs.streams', 'streams', 'template', 'templateVersion'])
            ->where('ulid', $ulid)->firstOrFail();

        return response()->json(['data' => VideoData::fromModel($video)]);
    }

    public function update(Request $request, UpdateVideoData $data, string $ulid)
    {
        $video = $this->videoService->update($ulid, $data->toDatabase(), $request->project());

        return response()->json([
            'message' => 'Video updated successfully',
            'data' => VideoData::fromModel($video->fresh()->load(['outputs.streams', 'streams', 'template', 'templateVersion'])),
        ]);
    }

    public function destroy(Request $request, string $ulid)
    {
        $this->videoService->destroy($ulid, $request->project());

        return response()->json([
            'message' => 'Video deleted successfully',
        ]);
    }

    /**
     * Requeues a failed video, which is the only way back into the pipeline: dispatch only ever
     * picks up a video that is PENDING, and getting there takes more than a status change
     * ({@see VideoService::retry()}).
     *
     * Takes no body. `retry --reprobe` — rebuilding the streams and outputs from the template
     * instead of reusing them — stays on the CLI ({@see RetryVideos}),
     * because from here the two are indistinguishable: a run that failed while probing left no
     * derived streams, so the plain retry re-probes anyway, and the difference the flag does make
     * is re-encoding a whole video that only needed its last chunk.
     */
    public function retry(Request $request, string $ulid)
    {
        $video = $request->project()->videos()->where('ulid', $ulid)->firstOrFail();

        $this->videoService->retry($video);

        return response()->json([
            'message' => 'Video queued for another run successfully',
            'data' => VideoData::fromModel($video->fresh()->load(['outputs.streams', 'streams', 'template', 'templateVersion'])),
        ]);
    }

    /**
     * The URL keeps the flat `{ulid}/{filename}` shape ({@see Video::assetPath}) wherever the object
     * actually sits, so cached links and API consumers never break; the zone is resolved here, at the
     * cost of one indexed query on a cache miss.
     *
     * The flat key is tried second rather than skipped, because the two writers of these objects run
     * on worker nodes from a baked image with no code mount: a worker still on an older build keeps
     * publishing `{ulid}/thumbnail.jpg` after this deploys, and nothing ever re-runs those jobs. The
     * route caches responses for a week and Laravel's cache-header middleware does not spare error
     * responses, so a single miss here would pin a public 404 for seven days.
     */
    public function getAsset(Request $request, string $ulid, string $filename)
    {
        $video = Video::where('ulid', $ulid)->first();
        $flat = Video::assetPath($ulid, $filename);

        $path = $video?->assetKey($filename) ?? $flat;

        if ($path !== $flat && ! Storage::exists($path)) {
            $path = $flat;
        }

        if (! Storage::exists($path)) {
            return response()->json([
                'message' => 'Thumbnail file not found',
            ], 404);
        }

        $file = Storage::get($path);
        $mimeType = Storage::mimeType($path);

        return response($file, 200, [
            'Content-Type' => $mimeType,
        ]);
    }
}
