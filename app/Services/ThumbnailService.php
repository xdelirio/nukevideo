<?php

namespace App\Services;

use App\Support\MediaSource;
use App\Support\Scratch;
use Exception;
use Illuminate\Support\Facades\Process;

class ThumbnailService
{
    /**
     * Extract a single poster frame. `-ss` before `-i` uses input seeking (nearest preceding
     * keyframe), so decode cost is O(1) regardless of the offset.
     */
    public function extractThumbnail(
        string $inputPath,
        string $outputPath,
        int $timeInSeconds = 1,
    ): string {
        if (! MediaSource::isReadable($inputPath)) {
            throw new Exception("Input video file not found: $inputPath");
        }

        Scratch::ensureDirectory(dirname($outputPath));

        $process = Process::timeout(60)->run([
            'ffmpeg',
            '-hide_banner',
            '-y',
            ...MediaSource::reconnectArguments($inputPath),
            '-ss', (string) $timeInSeconds,
            '-i', $inputPath,
            '-frames:v', '1',
            // Up to full HD for TVs, never upscaled: a smaller source keeps its own width.
            '-vf', "scale='min(1920,iw)':-2",
            '-q:v', '2',
            $outputPath,
        ]);

        if (! $process->successful()) {
            throw new Exception('Failed to extract thumbnail: '.$process->errorOutput());
        }

        if (! file_exists($outputPath)) {
            throw new Exception("Thumbnail file was not created at: $outputPath");
        }

        return $outputPath;
    }

    /**
     * Render every storyboard sprite sheet in one ffmpeg pass. `-skip_frame nokey` decodes only
     * keyframes (a typical GOP is 2-10s, so a tiny fraction of the file); thumbs snap to the
     * nearest keyframe, imperceptible for scrubbing. `tile` packs `columns x rows` per sheet.
     *
     * @param  string  $outputPattern  e.g. ".../storyboard_%d.jpg" (0-indexed)
     * @return int number of sprite sheets written
     */
    public function generateStoryboardSprites(
        string $inputPath,
        string $outputPattern,
        int $interval,
        int $columns,
        int $rows,
        int $thumbWidth,
        int $thumbHeight,
        int $quality,
        int $timeout,
    ): int {
        if (! MediaSource::isReadable($inputPath)) {
            throw new Exception("Input video file not found: $inputPath");
        }

        Scratch::ensureDirectory(dirname($outputPattern));

        // lanczos: only keyframes are decoded, so a few hundred frames are scaled per video and
        // the sharper filter costs nothing measurable, while the thumbs are large enough for a TV.
        $filter = sprintf(
            'fps=1/%d,scale=%d:%d:flags=lanczos,tile=%dx%d',
            $interval,
            $thumbWidth,
            $thumbHeight,
            $columns,
            $rows,
        );

        $process = Process::timeout($timeout)->run([
            'ffmpeg',
            '-hide_banner',
            '-y',
            ...MediaSource::reconnectArguments($inputPath),
            '-skip_frame', 'nokey',
            '-i', $inputPath,
            '-vf', $filter,
            '-q:v', (string) $quality,
            '-start_number', '0',
            $outputPattern,
        ]);

        if (! $process->successful()) {
            throw new Exception('FFmpeg storyboard failed: '.$process->errorOutput());
        }

        $glob = preg_replace('/%d/', '*', $outputPattern);
        $sprites = glob($glob) ?: [];

        if (empty($sprites)) {
            throw new Exception("No storyboard sprites produced for pattern: $outputPattern");
        }

        return count($sprites);
    }
}
