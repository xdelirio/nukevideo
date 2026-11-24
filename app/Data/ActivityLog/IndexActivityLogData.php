<?php

namespace App\Data\ActivityLog;

use App\Data\RequestData;

/**
 * The activity log's filters. A failed video's reason is recorded here (`video_failed`, with
 * `properties.reason`) and nowhere else, so finding why one video failed is filtering this by that
 * video and that event, rather than paging through every upload of the project.
 */
class IndexActivityLogData extends RequestData
{
    public function __construct(
        /** Only this event, as recorded (`video_failed`, `thumbnail_failed`…). */
        public ?string $event = null,
        /** Only the events of this video, by its ULID. */
        public ?string $video = null,
    ) {}

    public static function rules(): array
    {
        return [
            'event' => 'nullable|string|max:64|alpha_dash',
            'video' => 'nullable|string|ulid',
        ];
    }
}
