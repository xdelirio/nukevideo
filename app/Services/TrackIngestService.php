<?php

namespace App\Services;

use App\DTOs\TrackUploadMeta;
use App\Enums\StreamStatus;
use App\Enums\VideoStatus;
use App\Jobs\AddTrackJob;
use App\Models\Stream;
use App\Models\Video;
use Exception;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Turns a finished track upload into the stream row the panel and the API follow while
 * {@see AddTrackJob} processes it. The row exists from this moment on, so a file that turns out to
 * be unusable still leaves a visible, explained failure behind instead of an upload that went
 * nowhere.
 */
class TrackIngestService
{
    /** What each kind of added track may be uploaded as. Audio takes any container ffmpeg reads an
     *  audio stream from, video files included (the first audio stream is used). */
    public const EXTENSIONS = [
        'audio' => ['wav', 'mp3', 'm4a', 'aac', 'flac', 'ogg', 'opus', 'mka', 'mp4', 'mkv', 'mov', 'webm', 'ac3', 'eac3'],
        'subtitle' => ['srt', 'vtt', 'ass', 'ssa'],
    ];

    /** Room for the layout suffix a ladder appends (`" (Stereo)"`) inside the 255-character column. */
    public const MAX_NAME_LENGTH = 240;

    public function __construct(
        private UppyS3Service $uploads,
        private StreamManagementService $streams,
        private TrackParamsResolver $params,
    ) {}

    public function handle(string $key, int $size): void
    {
        // Idempotency first, as in {@see OnVideoUploadedService::handle}: a redelivery lands after
        // the first run already forgot the metadata.
        if (Stream::where('path', $key)->exists()) {
            $this->uploads->forgetUploadMeta($key);

            return;
        }

        $meta = $this->uploads->getUploadMeta($key);

        if (! $meta instanceof TrackUploadMeta) {
            throw new Exception("Track upload metadata not found for key: {$key}");
        }

        $video = Video::where('ulid', $meta->video)
            ->whereHas('project', fn ($q) => $q->where('ulid', $meta->project))
            ->first();

        if (! $video) {
            // Deleted while the upload ran: there is nothing to attach the track to, and the object
            // is reclaimed by the orphan sweep once the metadata is gone.
            Log::info('Track upload for a video that no longer exists; dropped', ['key' => $key, 'video' => $meta->video]);
            $this->uploads->forgetUploadMeta($key);

            return;
        }

        // What the upload endpoint checked may no longer hold: the video can be retried, or another
        // track can take the name, while the file was still uploading.
        $reason = $this->refusal($video, $meta->type, $meta->name, $meta->forced)['message'] ?? null;

        try {
            $stream = $video->streams()->create([
                'path' => $key,
                'type' => $meta->type,
                'status' => $reason === null ? StreamStatus::PROCESSING : StreamStatus::FAILED,
                'error_log' => $reason,
                'name' => $meta->name,
                'language' => $meta->language,
                'forced' => $meta->type === 'subtitle' && $meta->forced,
                'meta' => [
                    // Groups every stream this one upload becomes: a 5.1 dub can resolve to more
                    // than one rung ({@see AddTrackJob}), and they succeed or fail together.
                    'track_upload' => $key,
                    // The name as asked for: a split into rungs suffixes the row's own name.
                    'track_name' => $meta->name,
                    'hearing_impaired' => $meta->hearingImpaired,
                    ...($meta->type === 'audio' ? ['visual_impaired' => $meta->visualImpaired] : []),
                    'external_user_id' => $meta->externalUserId,
                ],
            ]);
        } catch (UniqueConstraintViolationException) {
            Log::info('Concurrent track ingest already created the stream', ['key' => $key]);

            return;
        }

        UsageService::record(
            $video->user_id,
            'upload_bytes',
            $size,
            $meta->externalUserId ?? $video->external_user_id ?? '',
            (int) $video->project_id,
        );

        $this->uploads->forgetUploadMeta($key);

        if ($stream->status === StreamStatus::PROCESSING) {
            AddTrackJob::dispatch($stream->id, $video->id);

            return;
        }

        // Refused: nothing will ever read the upload, and the row says why. Announced like a
        // failure of the job, so an integrator never has to poll for it.
        Storage::disk('s3')->delete($key);
        WebhookDispatcher::forVideo('video.track_failed', $video, ['tracks' => [$stream->ulid]]);
    }

    /**
     * Why a track cannot be added to `$video`, as `['field' => …, 'message' => …]`, or null when it
     * can. The one set of rules both the upload endpoint (before a byte is sent) and the ingest
     * (when the upload lands) apply. `field` is the upload metadata key at fault, `filename` for
     * the extension. `$extension` is only known at the endpoint.
     *
     * @return array{field: string, message: string}|null
     */
    public function refusal(Video $video, string $type, string $name, bool $forced, ?string $extension = null): ?array
    {
        if ($video->status !== VideoStatus::COMPLETED->value) {
            return ['field' => 'video', 'message' => 'Tracks can only be added to a video that finished processing.'];
        }

        if ($extension !== null && ! in_array(strtolower($extension), self::EXTENSIONS[$type] ?? [], true)) {
            return ['field' => 'filename', 'message' => "A {$type} track must be one of: ".implode(', ', self::EXTENSIONS[$type] ?? []).'.'];
        }

        if ($type === 'audio' && ! $this->params->canTakeAudio($video)) {
            return ['field' => 'trackType', 'message' => "None of this video's outputs carries audio in its template."];
        }

        try {
            $this->streams->assertNameAvailable($video->id, $type, $name, $forced);
        } catch (ValidationException $e) {
            return ['field' => 'trackName', 'message' => (string) collect($e->errors())->flatten()->first()];
        }

        return null;
    }
}
