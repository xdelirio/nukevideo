<?php

namespace App\Services;

use App\DTOs\UploadMeta;
use App\Enums\VideoPriority;
use App\Enums\VideoStatus;
use App\Models\Stream;
use App\Models\User;
use App\Models\Video;
use Exception;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Creates the video record the moment the upload webhook lands, so the user never loses
 * track of an upload even if later stages fail. Probe-derived fields (duration, aspect
 * ratio, rendition streams) are left empty and filled by {@see CreateVideoStreamsService}
 * once PrepareVideoJob has mirrored the source — probing main S3 here timed out.
 */
class OnVideoUploadedService
{
    private UploadMeta $meta;

    public function __construct(private UppyS3Service $uppyService) {}

    public function handle(string $key, int $size): void
    {
        // Idempotency first: a duplicate S3 delivery lands after the first run already ingested
        // AND forgot the meta, so requiring meta before this check would wrongly throw. Already
        // ingested means redelivery/retry — skip. The unique streams.path index covers the
        // concurrent race in the transaction below.
        if (Stream::where('path', $key)->exists()) {
            Log::info('Upload already ingested; skipping duplicate', ['key' => $key]);
            $this->uppyService->forgetUploadMeta($key);

            return;
        }

        $meta = $this->uppyService->getUploadMeta($key);

        // A late redelivery for an upload already consumed: an added track's key stops being its
        // stream's path once published, and the object is gone with the metadata. Nothing to do,
        // and nothing worth five failed attempts.
        if (! $meta && ! Storage::disk('s3')->exists($key)) {
            Log::info('Upload already consumed; skipping redelivery', ['key' => $key]);

            return;
        }

        if (! $meta) {
            throw new Exception("Upload metadata not found for key: {$key}");
        }

        $this->meta = $meta;

        [$user, $project, $template] = $this->resolveUserProjectAndTemplate();

        // A template with no outputs can never encode anything, whatever the fleet does. It does
        // NOT throw: the upload already happened and this is the user's only copy, so throwing
        // would burn the job's retries and leave the panel with no trace of it at all. The video
        // is created and failed instead — visible, explained, recoverable with `videos:retry`.
        //
        // Missing worker capacity is deliberately NOT checked here. It is usually transient (a
        // reboot, a redeploy), the video costs nothing while it waits, and `videos:dispatch`
        // picks it up the moment a node returns. Failing it here would hand it to `videos:prune`,
        // which deletes a terminally-failed video and its source after a week — the user's only copy,
        // thrown away over a node restart. A fleet that genuinely lacks the template's hardware is
        // never claimed at all: DispatchPendingVideosCommand skips a video whose hardware is
        // missing, so it waits in PENDING instead of failing.
        $unencodable = empty($template->query['outputs'] ?? [])
            ? "No outputs configured for template {$this->meta->template}"
            : null;

        try {
            $video = DB::transaction(function () use ($user, $project, $template, $key, $size) {
                $video = Video::create([
                    'user_id' => $user->id,
                    'project_id' => $project->id,
                    'template_id' => $template->id,
                    // Pinned now: an edit to the template after this upload must not change what
                    // this video is encoded, retried or extended with.
                    'template_version_id' => $template->ensureCurrentVersion()->id,
                    'name' => $this->meta->filename,
                    'status' => VideoStatus::PENDING->value,
                    // `??`, not a plain read: metadata cached before this field existed unserializes
                    // with the property uninitialized, and an upload may sit on it for days.
                    'priority' => $this->meta->priority ?? VideoPriority::NORMAL->value,
                    'duration' => 0,
                    'aspect_ratio' => '',
                    'external_user_id' => $this->meta->externalUserId,
                    'external_resource_id' => $this->meta->externalResourceId,
                ]);

                $video->streams()->create([
                    'path' => $key,
                    'name' => 'Original',
                    'type' => 'original',
                    'file_size' => $size,
                    'meta' => [],
                ]);

                return $video;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent delivery won the race to insert the original stream; it owns this upload.
            Log::info('Concurrent ingestion detected; skipping duplicate', ['key' => $key]);
            $this->uppyService->forgetUploadMeta($key);

            return;
        }

        activity('video')
            ->performedOn($video)
            ->causedBy($user)
            ->event('video_processing_started')
            ->log("Video queued for processing: {$video->name}");

        UsageService::record($user->id, 'upload_bytes', $size, $this->meta->externalUserId ?? '', (int) $video->project_id, $video->ulid);

        $this->uppyService->forgetUploadMeta($key);

        WebhookDispatcher::forVideo('video.created', $video);

        // After `video.created`, so a consumer sees the video exist before it hears it failed.
        if ($unencodable) {
            $video->markAsFailed($unencodable);
        }
    }

    private function resolveUserProjectAndTemplate(): array
    {
        $user = User::where('ulid', $this->meta->user)->first();

        if (! $user) {
            throw new Exception("User with ulid {$this->meta->user} not found");
        }

        $project = $user->projects()->where('ulid', $this->meta->project)->first();

        if (! $project) {
            throw new Exception("Project with ulid {$this->meta->project} not found");
        }

        $template = $project->templates()->where('ulid', $this->meta->template)->first();

        if (! $template) {
            throw new Exception("Template with ulid {$this->meta->template} not found");
        }

        return [$user, $project, $template];
    }
}
