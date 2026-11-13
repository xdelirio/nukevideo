<?php

/**
 * A multipart upload the client still holds can be gone on S3: completed, aborted, or swept by
 * {@see PruneScratchJob} after a day without finishing. That answers 404, which tells a resumable
 * client to start a new upload, where it used to surface as a 500 the client retried for days.
 */

use App\DTOs\UploadMeta;
use App\Jobs\PruneScratchJob;
use App\Models\Project;
use App\Models\User;
use App\Services\UppyS3Service;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\S3\Exception\S3Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $this->key = 'tmp-videos/01M6HVRQ19K5AKWBY0Q7D3SKDM.mkv';
    $this->uploadId = '32767.4f404d6e-e2a4-432a-b628-d0eaf35e13ea';

    app(UppyS3Service::class)->storeUploadMeta($this->key, new UploadMeta(
        user: $user->ulid,
        project: $project->ulid,
        template: (string) str()->ulid(),
        filename: 'clip.mkv',
    ));

    // The disk's own client, answered by the SDK's mock handler instead of the network.
    $this->s3 = new MockHandler;
    config(['filesystems.disks.s3.handler' => $this->s3]);
    Storage::forgetDisk('s3');

    $this->s3Error = fn (string $code) => fn (CommandInterface $command) => new S3Exception($code, $command, ['code' => $code]);

    Sanctum::actingAs($user);
});

it('answers 404 when the upload no longer exists on S3', function (string $method, string $path) {
    $this->s3->append(($this->s3Error)('NoSuchUpload'));

    $this->json($method, "/api/s3/multipart/{$this->uploadId}{$path}?key=".urlencode($this->key), ['parts' => []])
        ->assertNotFound()
        ->assertJsonPath('message', 'This upload no longer exists: it was completed, aborted or expired. Start a new one.');
})->with([
    'list parts' => ['GET', ''],
    'complete' => ['POST', '/complete'],
    'abort' => ['DELETE', ''],
]);

it('still fails as a server error when S3 fails for any other reason', function () {
    $this->s3->append(($this->s3Error)('AccessDenied'));

    $this->getJson("/api/s3/multipart/{$this->uploadId}?key=".urlencode($this->key))
        ->assertServerError();
});

it('refuses to complete an upload with parts S3 could not take', function (mixed $parts) {
    // Nothing is appended to the mock: a request that reached S3 would fail the test on its own.
    $this->postJson("/api/s3/multipart/{$this->uploadId}/complete?key=".urlencode($this->key), ['parts' => $parts])
        ->assertStatus(422);
})->with([
    'missing' => [null],
    'a string' => ['all of them'],
    'a map' => [['a' => ['PartNumber' => 1, 'ETag' => 'x']]],
    'an entry that is not a part' => [['x']],
    'a part number out of range' => [[['PartNumber' => 10001, 'ETag' => 'x']]],
    'a part with no ETag' => [[['PartNumber' => 1]]],
    'an ETag that is not text' => [[['PartNumber' => 1, 'ETag' => ['x']]]],
]);
