<?php

/**
 * The storyboard is built at packaging from an encoded rendition already on local disk, not from
 * the source at preparation: the source read was a whole file (30 GB for one film) at the busiest
 * moment of a video's life. The thumbs are 640 px wide so they stay sharp on a TV, and each sheet
 * stays within the 4096 px texture limit whatever the aspect ratio.
 */

use App\Models\Stream;
use App\Models\Video;
use App\Services\StoryboardService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

function storyboardRendition(int $id, int $width, int $height): Stream
{
    $stream = new Stream(['type' => 'video', 'width' => $width, 'height' => $height]);
    $stream->id = $id;

    return $stream;
}

describe('the rendition it is built from', function () {
    it('is the smallest one at least as wide as the thumbs', function () {
        // Nothing upscaled, and no 4K keyframe decoded for a 640 px thumb.
        $picked = StoryboardService::pickRendition(new Collection([
            storyboardRendition(1, 3840, 2160),
            storyboardRendition(2, 426, 240),
            storyboardRendition(3, 1280, 720),
            storyboardRendition(4, 640, 360),
        ]));

        expect($picked->id)->toBe(4);
    });

    it('is the widest one when none reaches the thumb width', function () {
        $picked = StoryboardService::pickRendition(new Collection([
            storyboardRendition(1, 256, 144),
            storyboardRendition(2, 426, 240),
        ]));

        expect($picked->id)->toBe(2);
    });

    it('is none for a video without video streams', function () {
        expect(StoryboardService::pickRendition(new Collection))->toBeNull();
    });
});

describe('the sheets', function () {
    it('fit as many rows as stay within the texture limit', function () {
        expect(StoryboardService::spriteRows(360))->toBe(10)   // 16:9 at 640: 3600 px
            ->and(StoryboardService::spriteRows(480))->toBe(8)  // 4:3: 3840 px
            ->and(StoryboardService::spriteRows(1138))->toBe(3) // 9:16: 3414 px
            ->and(StoryboardService::spriteRows(5000))->toBe(1);
    });

    it('come out of a real rendition within the limit, with a cue per ten seconds', function () {
        Storage::fake('s3');
        Storage::fake('tmp');

        $path = sys_get_temp_dir().'/nukevideo-storyboard.mp4';
        Process::timeout(120)->run(sprintf(
            'ffmpeg -hide_banner -v error -y -f lavfi -i testsrc2=s=640x360:r=24 -t 130 '
            .'-c:v libx264 -preset ultrafast -g 48 -pix_fmt yuv420p %s',
            escapeshellarg($path),
        ))->throw();

        $video = new Video(['duration' => 130, 'aspect_ratio' => '16:9']);
        $video->ulid = '01TESTSTORYBOARD0000000000';

        try {
            app(StoryboardService::class)->generate($video, $path);
        } finally {
            @unlink($path);
        }

        $sheet = Storage::disk('s3')->get($video->assetKey(Video::storyboardSpriteFilename(0)));
        [$width, $height] = getimagesizefromstring($sheet);
        $vtt = Storage::disk('s3')->get($video->assetKey(Video::STORYBOARD_VTT_FILENAME));

        expect([$width, $height])->toBe([6 * 640, 10 * 360])
            ->and(substr_count($vtt, ' --> '))->toBe(13)
            ->and($vtt)->toContain('storyboard_0.jpg#xywh=640,0,640,360');
    });
});
