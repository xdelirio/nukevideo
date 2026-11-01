<?php

namespace App\Models\Concerns;

use App\Models\Node;
use App\Models\Stream;
use App\Models\Template;
use App\Models\TemplateVersion;
use App\Services\ChunkTranscodeService;

/**
 * What an encoding definition (`query`) demands of the fleet. Shared by a template, which answers
 * for the uploads still to come, and by a {@see TemplateVersion}, which answers for
 * the videos pinned to it — a video already ingested must be routed by what it will actually encode
 * with, not by what its template says since.
 */
trait ReadsEncodingQuery
{
    /**
     * GPU families this template's outputs encode on, empty for an all-CPU template.
     *
     * @return list<string>
     */
    public function accels(): array
    {
        return array_values(array_diff($this->capacities(), [Template::CPU]));
    }

    /**
     * Hardware each output encodes on: a GPU family, or CPU.
     *
     * @return list<string>
     */
    public function capacities(): array
    {
        return collect($this->query['outputs'] ?? [])
            ->map(fn (array $output) => ChunkTranscodeService::accelForCodec($output['video_codec'] ?? null) ?? Template::CPU)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * First hardware this template needs that no active worker node provides, or null. Chunks are
     * routed per hardware ({@see Stream::encodeQueue}), so a missing family — CPU included, since
     * GPU nodes never drain the CPU queue — leaves the video hanging until the reaper.
     */
    public function missingCapacity(): ?string
    {
        foreach ($this->capacities() as $capacity) {
            $nodes = Node::worker()->active();

            $available = $capacity === Template::CPU
                ? $nodes->whereNull('accel')->exists()
                : $nodes->where('accel', $capacity)->exists();

            if (! $available) {
                return $capacity;
            }
        }

        return null;
    }
}
