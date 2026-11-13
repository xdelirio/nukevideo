<?php

namespace App\Http\Controllers;

use App\Data\Stream\UpdateStreamData;
use App\DTOs\TrackUploadMeta;
use App\DTOs\UploadMeta;
use App\Enums\VideoPriority;
use App\Jobs\OnVideoUploaded;
use App\Jobs\PruneScratchJob;
use App\Models\Project;
use App\Models\Video;
use App\Services\TrackIngestService;
use App\Services\UppyS3Service;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/*
 * Formerly extended tapp/laravel-uppy-s3-multipart-upload's controller; the package stopped at
 * Laravel 12 support, so the S3 multipart plumbing it provided lives inline here now.
 */
class MyCustomUppyController extends Controller
{
    protected S3Client $client;

    protected string $bucket;

    public function __construct(
        protected UppyS3Service $uppyService,
        private TrackIngestService $trackIngest,
    ) {
        $this->client = Storage::disk('s3')->getClient();
        $this->bucket = config('filesystems.disks.s3.bucket');
    }

    public function createMultipartUpload(Request $request)
    {
        $project = $this->resolveProject($request);

        [$key, $meta] = $this->prepareUpload($request, $project);

        $result = $this->client->createMultipartUpload([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'ContentDisposition' => 'inline',
        ]);

        $this->uppyService->storeUploadMeta($key, $meta);

        return response()
            ->json([
                'uploadId' => $result['UploadId'],
                'key' => $result['Key'],
            ]);
    }

    public function getUploadParameters(Request $request)
    {
        $project = $this->resolveProject($request);

        [$key, $meta] = $this->prepareUpload($request, $project);

        $this->uppyService->storeUploadMeta($key, $meta);

        try {
            $cmd = $this->client->getCommand('PutObject', [
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);

            // Same window as the multipart part signer; hardcoding it here meant changing the
            // configured expiry only affected files large enough to go multipart.
            $signedRequest = $this->client->createPresignedRequest(
                $cmd,
                config('uppy-s3-multipart-upload.s3.presigned_url.expiry_time'),
            );
        } catch (Throwable $exception) {
            Log::error('S3 presigned URL generation failed', ['error' => $exception->getMessage()]);
            $status = method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : 500;

            return response()
                ->json([
                    'message' => $exception->getMessage(),
                ], $status);
        }

        return response()->json([
            'method' => 'PUT',
            'url' => (string) $signedRequest->getUri(),
            'key' => $key,
        ]);
    }

    /*
     * The package endpoints below act on any key/uploadId the caller supplies; scope them to
     * uploads the authenticated user actually started (their cached UploadMeta) so one tenant
     * can't complete, abort or list another tenant's in-flight upload.
     */

    public function getUploadedParts(Request $request, string $uploadId)
    {
        $this->authorizeUploadKey($request);

        return response()->json(
            $this->onExistingUpload(fn () => $this->listParts($request->input('key'), $uploadId))
        );
    }

    public function completeMultipartUpload(Request $request, string $uploadId)
    {
        $this->authorizeUploadKey($request);

        // Passed to S3 as it arrives, so it has to be what S3 takes: a list of part numbers and
        // their ETags. Anything else used to surface as an SDK exception and a 500.
        $parts = $request->input('parts');
        $wellFormed = is_array($parts) && array_is_list($parts) && count($parts) <= 10000
            && collect($parts)->every(fn ($part) => is_array($part)
                && filter_var($part['PartNumber'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]) !== false
                && is_string($part['ETag'] ?? null) && strlen($part['ETag']) <= 255);

        abort_unless($wellFormed, 422, 'The parts of the upload must be a list of part numbers and ETags.');

        $result = $this->onExistingUpload(fn () => $this->client->completeMultipartUpload([
            'Bucket' => $this->bucket,
            'Key' => $request->input('key'),
            'UploadId' => $uploadId,
            'MultipartUpload' => [
                'Parts' => array_map(fn (array $part) => [
                    'PartNumber' => (int) $part['PartNumber'],
                    'ETag' => $part['ETag'],
                ], $parts),
            ],
        ]));

        return response()->json([
            'location' => $result['Location'],
        ]);
    }

    public function abortMultipartUpload(Request $request, string $uploadId)
    {
        $this->authorizeUploadKey($request);

        $this->onExistingUpload(fn () => $this->client->abortMultipartUpload([
            'Bucket' => $this->bucket,
            'Key' => $request->input('key'),
            'UploadId' => $uploadId,
        ]));

        return response()->json([]);
    }

    public function signPartUpload(Request $request)
    {
        $this->authorizeUploadKey($request);

        $command = $this->client->getCommand('UploadPart', [
            'Bucket' => $this->bucket,
            'Key' => $request->input('key'),
            'UploadId' => $request->route('uploadId'),
            'PartNumber' => (int) $request->route('partNumber'),
        ]);

        $result = $this->client->createPresignedRequest(
            $command,
            config('uppy-s3-multipart-upload.s3.presigned_url.expiry_time'),
        );

        return response()->json([
            'url' => (string) $result->getUri(),
        ]);
    }

    /**
     * Runs a call on a multipart upload the client is still holding. S3 answers `NoSuchUpload`
     * once the upload is gone (completed, aborted, or swept by {@see PruneScratchJob}
     * after a day without finishing), and that is the client's to recover from by starting a new
     * one, not a fault of ours: a 404 says so, where the 500 it used to surface as was retried by
     * resumable clients for days and logged a stack trace on every attempt.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function onExistingUpload(callable $call): mixed
    {
        try {
            return $call();
        } catch (S3Exception $exception) {
            if ($exception->getAwsErrorCode() === 'NoSuchUpload') {
                abort(404, 'This upload no longer exists: it was completed, aborted or expired. Start a new one.');
            }

            throw $exception;
        }
    }

    /** S3 caps ListParts pages at 1000; follow the marker so huge files list completely. */
    private function listParts(string $key, string $uploadId): array
    {
        $parts = [];
        $marker = 0;

        do {
            $page = $this->client->listParts([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
                'PartNumberMarker' => $marker,
            ]);

            $parts = array_merge($parts, $page['Parts'] ?? []);
            $marker = $page['NextPartNumberMarker'] ?? 0;
        } while (! empty($page['IsTruncated']));

        return $parts;
    }

    /** Runs once per signed part, so it stays a string compare against the cached meta — no queries. */
    private function authorizeUploadKey(Request $request): void
    {
        $request->validate(['key' => 'required|string']);

        $meta = $this->uppyService->getUploadMeta($request->input('key'));
        $actor = $request->user();

        $owns = $meta && ($actor instanceof Project
            ? $meta->project === $actor->ulid
            : $meta->user === $actor->ulid);

        abort_unless($owns, 403, 'This upload does not belong to you.');
    }

    /**
     * Uppy carries the project in the metadata (its requests skip our axios interceptor, so there is
     * no header). A project API key can only ever mean its own project; a user must own the one named.
     */
    private function resolveProject(Request $request): Project
    {
        $request->validate([
            'metadata.project' => 'required|ulid',
        ]);

        $ulid = $request->input('metadata.project');
        $actor = $request->user();

        if ($actor instanceof Project) {
            abort_unless($actor->ulid === $ulid, 403, 'This API key belongs to another project.');

            return $actor;
        }

        return $actor->projects()->where('ulid', $ulid)->firstOrFail();
    }

    /**
     * Validate the upload and pick its key and metadata. `metadata.video` makes it a track for an
     * already-processed video ({@see TrackUploadMeta}) rather than a new video. Both land in the
     * same folder: the metadata is what tells them apart ({@see OnVideoUploaded}), so a
     * bucket notification filtered on that folder keeps covering tracks.
     *
     * @return array{0: string, 1: UploadMeta|TrackUploadMeta}
     */
    private function prepareUpload(Request $request, Project $project): array
    {
        if ($request->filled('metadata.video')) {
            $video = $this->validateTrackMetadata($request, $project);

            return [
                $this->uppyService->generateKey($request->input('filename')),
                TrackUploadMeta::fromRequest($project->user, $project, $video, $request->all()),
            ];
        }

        $this->validateMetadata($request, $project);

        return [
            $this->uppyService->generateKey($request->input('filename')),
            $this->uppyService->buildUploadMeta($project->user, $project, $request->all()),
        ];
    }

    /**
     * Everything an added track can be refused for is checked here, before the upload starts: the
     * file may be gigabytes of audio, and a refusal after the fact would only surface as a failed
     * track. The ingest re-checks what can change in between ({@see TrackIngestService}).
     */
    private function validateTrackMetadata(Request $request, Project $project): Video
    {
        // @uppy/aws-s3 sends every metadata value through String(), so the panel's flags arrive as
        // "true"/"false" — which the `boolean` rule refuses. Normalised first; anything else is
        // left as sent, for the rule to judge.
        $metadata = (array) $request->input('metadata', []);
        foreach (['forced', 'hearingImpaired', 'visualImpaired'] as $flag) {
            if (is_string($metadata[$flag] ?? null)) {
                $metadata[$flag] = filter_var($metadata[$flag], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $metadata[$flag];
            }
        }
        $request->merge(['metadata' => $metadata]);

        $request->validate([
            'filename' => 'required|string|max:255',
            'metadata.video' => 'required|ulid',
            'metadata.trackType' => ['required', Rule::in(array_keys(TrackIngestService::EXTENSIONS))],
            // Not `name`/`type`: Uppy fills those meta fields with the file's own name and MIME type.
            // Same criteria as editing a track: the name is written into the packager descriptor and
            // the HLS master as plain text.
            'metadata.trackName' => ['required', 'string', 'max:'.TrackIngestService::MAX_NAME_LENGTH, 'regex:'.UpdateStreamData::NAME_PATTERN],
            'metadata.language' => ['required', ...UpdateStreamData::rules()['language']],
            'metadata.forced' => 'nullable|boolean',
            'metadata.hearingImpaired' => 'nullable|boolean',
            'metadata.visualImpaired' => 'nullable|boolean',
            'metadata.externalUserId' => 'nullable|string|max:255',
        ]);

        $video = $project->videos()->where('ulid', $request->input('metadata.video'))->firstOrFail();

        $refusal = $this->trackIngest->refusal(
            $video,
            $request->input('metadata.trackType'),
            $request->input('metadata.trackName'),
            $request->boolean('metadata.forced'),
            pathinfo((string) $request->input('filename'), PATHINFO_EXTENSION),
        );

        if ($refusal !== null) {
            $field = $refusal['field'] === 'filename' ? 'filename' : "metadata.{$refusal['field']}";

            throw ValidationException::withMessages([$field => $refusal['message']]);
        }

        return $video;
    }

    private function validateMetadata(Request $request, Project $project): void
    {
        $request->validate([
            'filename' => 'required|string|max:255',
            'metadata.template' => [
                'required',
                'ulid',
                // Disabled templates are retired, not deleted (they cannot be deleted once a video
                // uses them), so they must not be selectable for a new upload either.
                Rule::exists('templates', 'ulid')->where(function ($query) use ($project) {
                    return $query->where('project_id', $project->id)->where('enabled', true);
                }),
            ],
            'metadata.externalUserId' => 'nullable|string|max:255',
            'metadata.externalResourceId' => 'nullable|string|max:255',
            'metadata.priority' => ['nullable', Rule::enum(VideoPriority::class)],
        ]);
    }
}
