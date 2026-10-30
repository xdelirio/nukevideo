<?php

namespace App\Services;

use App\Data\VideoData;
use App\Jobs\DispatchWebhookJob;
use App\Models\Video;

class WebhookDispatcher
{
    public static function forVideo(string $event, Video $video): void
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
        ];

        DispatchWebhookJob::dispatch(
            $url,
            $settings['webhookSecret'] ?? $settings['webhook_secret'] ?? null,
            $payload,
        )->afterCommit();
    }
}
