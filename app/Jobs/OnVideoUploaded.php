<?php

namespace App\Jobs;

use App\DTOs\TrackUploadMeta;
use App\Models\User;
use App\Services\OnVideoUploadedService;
use App\Services\TrackIngestService;
use App\Services\UppyS3Service;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class OnVideoUploaded implements ShouldQueue
{
    use Queueable;

    // Object-created events are at-least-once; ingestion is idempotent (unique streams.path),
    // so retries cover transient blips without ever creating a duplicate video.
    public int $tries = 5;

    public array $backoff = [10, 30, 60, 120];

    // Light job (DB + cache only; probing lives in PrepareVideoJob). Runs on the default
    // queue, so keep the timeout under that connection's retry_after (90).
    public int $timeout = 60;

    public function __construct(
        private string $key,
        private int $size,
    ) {}

    public function handle(OnVideoUploadedService $service, UppyS3Service $uploads, TrackIngestService $tracks): void
    {
        Log::info('OnVideoUploaded started', ['key' => $this->key, 'size' => $this->size]);

        // Tracks for an already-processed video share the upload folder with new videos; what the
        // upload IS lives in its metadata. A redelivery after the metadata was forgotten reaches
        // the video path either way, whose idempotency check on streams.path covers both.
        if ($uploads->getUploadMeta($this->key) instanceof TrackUploadMeta) {
            $tracks->handle($this->key, $this->size);
            Log::info('OnVideoUploaded completed as a track', ['key' => $this->key]);

            return;
        }

        $service->handle($this->key, $this->size);
        Log::info('OnVideoUploaded completed', ['key' => $this->key]);
    }

    public function failed(Throwable $exception): void
    {
        $meta = app(UppyS3Service::class)->getUploadMeta($this->key);
        $userUlid = $meta?->user;
        $filename = $meta?->filename ?? $this->key;

        if ($userUlid) {
            $user = User::where('ulid', $userUlid)->first();

            if ($user) {
                activity('video')
                    ->causedBy($user)
                    ->withProperties(['error' => $exception->getMessage(), 'file' => $filename])
                    ->event('video_upload_processing_failed')
                    ->log("Video upload processing failed for \"{$filename}\": {$exception->getMessage()}");
            }
        }

        // Do NOT delete the uploaded source: the failure may be recoverable and this is the
        // user's only copy. Orphaned sources are reclaimed by age-based GC.
        Log::error('Video upload processing failed permanently; source retained for recovery', [
            'key' => $this->key,
            'error' => $exception->getMessage(),
        ]);
    }
}
