<?php

namespace App\Services;

use App\Data\Stream\UpdateStreamData;
use App\Enums\StreamStatus;
use App\Enums\VideoStatus;
use App\Http\Controllers\MyCustomUppyController;
use App\Models\Project;
use App\Models\Stream;
use App\Models\Video;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class StreamManagementService
{
    public function __construct(private ManifestEditor $manifests) {}

    /**
     * Rename / relanguage / (un)force a track and push it into the already-packaged manifests. Runs
     * under a lock on the video row because every stream of a video shares the same manifest files:
     * two concurrent edits would otherwise read-modify-write the same objects and the later one
     * would silently revert the earlier.
     */
    public function update(string $ulid, UpdateStreamData $data, Project $project): Stream
    {
        return DB::transaction(function () use ($ulid, $data, $project) {
            $stream = $this->findOrFail($ulid, $project);
            $video = $stream->video;

            Video::whereKey($video->id)->lockForUpdate()->first();

            $this->assertNotProcessing($stream);

            if (! in_array($stream->type, ['audio', 'subtitle'], true)) {
                throw ValidationException::withMessages(['message' => 'Only audio and subtitle tracks can be edited.'])->status(400);
            }

            if (! in_array($video->status, [VideoStatus::COMPLETED->value, VideoStatus::FAILED->value])) {
                throw ValidationException::withMessages(['message' => 'You cannot edit any stream until the video is processed.'])->status(400);
            }

            $this->assertNameIsFree($stream, $data);

            // Not a column: the flag lives in `meta`, next to where the probe wrote it.
            $stream->update(collect($data->toDatabase())->except('hearing_impaired')->all());

            // Only what actually changed: the packager normalizes languages, so rewriting an
            // untouched one would drift the manifests ({@see ManifestEditor::relabelStream}).
            $fields = array_values(array_filter(
                ['name', 'language', 'forced'],
                fn (string $field) => $stream->wasChanged($field),
            ));

            if ($this->applyHearingImpaired($stream, $data)) {
                $fields[] = 'hearing_impaired';
            }

            // A FAILED video was never packaged, so it has no manifests to edit.
            if ($video->status === VideoStatus::COMPLETED->value) {
                $this->manifests->relabelStream($video, $stream, $fields);
            }

            return $stream;
        });
    }

    /**
     * Track names stay unique within their type: the packager merges same-language same-label tracks
     * into one AdaptationSet and HLS groups collide on `NAME`, which is why the ingest dedupes them
     * ({@see CreateVideoStreamsService::uniqueName}). Case-insensitive, like the ingest.
     *
     * Subtitles compare within their forced flag — the flag the EDIT is setting, since a rename may
     * flip it in the same request: a forced track and a full track legitimately share a name
     * ("Español" twice, one FORCED=YES), which is how Apple's own HLS examples label forced subs.
     */
    private function assertNameIsFree(Stream $stream, UpdateStreamData $data): void
    {
        $this->assertNameAvailable($stream->video_id, $stream->type, $data->name, $data->forced, $stream->id);
    }

    /**
     * {@see assertNameIsFree}, for a track that does not exist yet as well: an added track is held
     * to the same rule before its upload even starts ({@see MyCustomUppyController}).
     */
    public function assertNameAvailable(int $videoId, string $type, string $name, bool $forced, ?int $exceptId = null, string $field = 'name'): void
    {
        $query = Stream::where('video_id', $videoId)
            ->where('type', $type)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            // A track whose addition failed is in no manifest, so its name collides with nothing a
            // player sees; counting it would refuse the retry of the very same upload.
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', StreamStatus::FAILED->value))
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)]);

        if ($type === 'subtitle') {
            $query->where('forced', $forced);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                $field => 'Another '.$type.' track of this video already uses that name.',
            ]);
        }
    }

    /**
     * Flip `meta.hearing_impaired` when the request carries a different value. Subtitles only: for
     * them the flag is pure presentation (role/CHARACTERISTICS, same machinery as `forced`), while
     * on audio it was baked into the packaged DASH Accessibility descriptor and editing the flag
     * without that surgery would make the API contradict the manifests.
     */
    private function applyHearingImpaired(Stream $stream, UpdateStreamData $data): bool
    {
        $current = (bool) data_get($stream->meta, 'hearing_impaired', false);

        if ($data->hearingImpaired === null || $data->hearingImpaired === $current) {
            return false;
        }

        if ($stream->type !== 'subtitle') {
            throw ValidationException::withMessages([
                'hearingImpaired' => 'Only subtitle tracks can change this flag after packaging.',
            ]);
        }

        $stream->update(['meta' => [...($stream->meta ?? []), 'hearing_impaired' => $data->hearingImpaired]]);

        return true;
    }

    /** Same lock as {@see update}: both edit the video's shared manifest files on S3. */
    public function destroy(string $ulid, Project $project): void
    {
        DB::transaction(function () use ($ulid, $project) {
            $stream = $this->findOrFail($ulid, $project);
            $video = $stream->video;

            Video::whereKey($video->id)->lockForUpdate()->first();

            $this->assertNotProcessing($stream);

            if (! in_array($video->status, [VideoStatus::COMPLETED->value, VideoStatus::FAILED->value])) {
                throw ValidationException::withMessages(['message' => 'You cannot delete any stream until the video is processed.'])->status(400);
            }

            // Per output, not video-wide: renditions attach to outputs, so with A={1080p} and
            // B={480p,1080p} a video-wide count of 2 would still strip A's only variant and
            // leave its published manifests with no video at all.
            if ($stream->type === 'video') {
                $strandsOutput = $stream->outputs->contains(
                    fn ($output) => $output->streams()->where('type', 'video')->count() <= 1
                );

                if ($strandsOutput) {
                    throw ValidationException::withMessages(['message' => 'You cannot delete the last video rendition of an output.'])->status(400);
                }
            }

            // A FAILED video has no packaged manifests; only COMPLETED ones need S3 surgery. A track
            // whose addition failed is normally in none of them either, but a worker killed in the
            // instant between the manifest writes and the commit leaves it grafted and unattached —
            // so it is searched for in every output. Where it is absent this changes nothing.
            if ($video->status === VideoStatus::COMPLETED->value) {
                if ($stream->status === StreamStatus::FAILED) {
                    $stream->setRelation('outputs', $video->outputs);
                }

                $this->manifests->removeStream($video, $stream);

                // removeStream cleans up CMAF segments; the standalone VTT lives in the download
                // zone, which `path` does not name — resolve it or this deletes nothing.
                if ($stream->type === 'subtitle') {
                    Storage::disk('s3')->delete($stream->storedPath($video));
                }
            }

            $stream->delete();
        });
    }

    /**
     * A track being added is grafted into the manifests at the very end of its job, from rows read
     * under this same lock ({@see TrackPublisher}). Editing it now would be reverted
     * by the labels the job is about to publish, and deleting it would race the upload of segments
     * nothing references any more.
     */
    private function assertNotProcessing(Stream $stream): void
    {
        if ($stream->status === StreamStatus::PROCESSING) {
            throw ValidationException::withMessages(['message' => 'This track is still being processed.'])->status(409);
        }
    }

    private function findOrFail(string $ulid, Project $project): Stream
    {
        return Stream::with('video')
            ->whereHas('video', fn ($q) => $q->where('project_id', $project->id))
            ->where('ulid', $ulid)
            ->firstOrFail();
    }
}
