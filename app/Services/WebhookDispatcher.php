<?php

namespace App\Services;

use App\Data\VideoData;
use App\Jobs\DispatchWebhookJob;
use App\Models\Video;

class WebhookDispatcher
{
    /** `$extra` adds top-level fields next to `data`, which stays the full video for every event. */
    public static function forVideo(string $event, Video $video, array $extra = []): void
    {
        $project = $video->project;

        if (! $project) {
            return;
        }

        $settings = $project->settings ?? [];
        $url = $settings['webhookUrl'] ?? $settings['webhook_url'] ?? null;

        if (! $url) {
            return;
        }

        $payload = [
            'event' => $event,
            'timestamp' => now()->timestamp,
            'data' => VideoData::fromModel($video->loadMissing(['outputs.streams', 'streams', 'template', 'templateVersion'])),
            ...$extra,
        ];

        DispatchWebhookJob::dispatch(
            $url,
            $settings['webhookSecret'] ?? $settings['webhook_secret'] ?? null,
            $payload,
        )->afterCommit();
    }
}
