<?php

namespace App\Services;

use App\Data\ProjectStorageData;
use App\Data\StatusStorageData;
use App\Data\StorageData;
use App\Models\Project;
use App\Models\Stream;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What videos occupy on storage, added up from their streams: `package_size` is what playback
 * serves, `file_size` what is kept beside it (the original, processed renditions). Read live off
 * the streams, so it is always what the bucket holds now and never a figure that drifted.
 */
class StorageService
{
    /** @param  Builder  $videos  a query of the videos to add up; only its ids are read */
    public function forVideos(Builder $videos): StorageData
    {
        $row = $this->sums(Stream::query()->whereIn('video_id', (clone $videos)->select('videos.id'))->toBase())->first();

        return self::storage($row);
    }

    /** The project's whole catalogue, and the same split by video status. */
    public function forProject(Project $project): ProjectStorageData
    {
        $rows = $this->sums(
            DB::table('streams')
                ->join('videos', 'videos.id', '=', 'streams.video_id')
                ->where('videos.project_id', $project->id)
                ->select('videos.status')
                ->selectRaw('count(distinct videos.id) as videos')
                ->groupBy('videos.status')
        )->get();

        // Counted on `videos`: a video with no stream yet holds nothing, but it is still one.
        $counts = $project->videos()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $byStatus = [];
        foreach ($counts as $status => $total) {
            $row = $rows->firstWhere('status', $status);
            $byStatus[] = new StatusStorageData(status: $status, videos: (int) $total, storage: self::storage($row));
        }

        return new ProjectStorageData(
            videos: (int) $counts->sum(),
            storage: new StorageData(
                totalBytes: array_sum(array_map(fn (StatusStorageData $s) => $s->storage->totalBytes, $byStatus)),
                servedBytes: array_sum(array_map(fn (StatusStorageData $s) => $s->storage->servedBytes, $byStatus)),
                sourceBytes: array_sum(array_map(fn (StatusStorageData $s) => $s->storage->sourceBytes, $byStatus)),
            ),
            byStatus: $byStatus,
        );
    }

    private function sums(\Illuminate\Database\Query\Builder $streams): \Illuminate\Database\Query\Builder
    {
        return $streams
            ->selectRaw('coalesce(sum(streams.package_size), 0) as served')
            ->selectRaw('coalesce(sum(streams.file_size), 0) as files')
            ->selectRaw("coalesce(sum(case when streams.type = 'original' then streams.file_size else 0 end), 0) as source");
    }

    private static function storage(?object $row): StorageData
    {
        return new StorageData(
            totalBytes: (int) ($row->served ?? 0) + (int) ($row->files ?? 0),
            servedBytes: (int) ($row->served ?? 0),
            sourceBytes: (int) ($row->source ?? 0),
        );
    }
}
