<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\ApiTokenController;
use App\Http\Controllers\Api\AppSettingsController;
use App\Http\Controllers\Api\CdnSettingsController;
use App\Http\Controllers\Api\MetricsController;
use App\Http\Controllers\Api\NodeController;
use App\Http\Controllers\Api\NodeEnvironmentController;
use App\Http\Controllers\Api\NodeOperationController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\UsageController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BandwidthController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\MyCustomUppyController;
use App\Http\Controllers\ProjectApiKeyController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\StreamController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\VideoController;
use App\Http\Controllers\VideoWebhookController;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\VerifyInternalSecret;
use App\Http\Middleware\VerifyWebhookSignature;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('logout', [AuthController::class, 'logout']);

Route::middleware(['auth:sanctum'])->group(function () {
    // Read-only delivery and queue metrics, open to ANY authenticated token — a personal one or a
    // project API key. Deliberately outside both groups below: not `no-project-key`, because
    // reading these back is exactly what an integrating backend needs a project key for, and not
    // `resolve.project`, because these two answer with or without a project and requiring the
    // header would only 400 a caller that has nothing to name.
    //
    // The trade this accepts is that the figures are instance-wide: a project key reads totals
    // that include every other project's traffic. NukeVideo is a component other backends embed,
    // reached server to server with a key that never leaves their infrastructure, so a tenant
    // seeing aggregate bandwidth is not the boundary that matters here. Nothing else moved —
    // nodes, users, CDN settings and the account surfaces stay admin-only, and neither of these
    // endpoints reads `$request->user()`, which for a project key is a Project and not a User.
    Route::get('analytics/queue', [AnalyticsController::class, 'queueStatus']);

    // Delivered bytes for a batch of tracking ids — the same numbers `analytics` reports, for a
    // list the caller names, which is what billing a per-subscriber quota needs. Same group and
    // same reasoning as the two above. POST as well as GET because the input is a list and a
    // thousand ids do not fit in a query string; it still only reads.
    //
    // Its sibling `analytics/videos` is NOT here: a video belongs to a project, so that one can be
    // scoped and therefore must be. See the resolve.project group below.
    Route::match(['get', 'post'], 'analytics/tracking-ids', [AnalyticsController::class, 'trackingIds']);

    // Upload and encoding consumption, for the same reason and with one extra step: this one IS
    // keyed by user, so the controller resolves a project key back to the account that owns it
    // rather than reading `$request->user()->id`, which for a Project is a project id and would
    // have silently answered with another account's numbers — or with none.
    Route::get('usage', [UsageController::class, 'index']);

    // Account-wide: these span every project, so a project API key has no business here — usage is
    // keyed by the owning user there, not by project. No resolve.project either: a stale
    // X-Project-Ulid header must not 404 account endpoints.
    Route::middleware('no-project-key')->group(function () {
        Route::get('me', MeController::class);
        Route::put('profile', [ProfileController::class, 'update']);
        Route::put('profile/password', [ProfileController::class, 'updatePassword']);

        Route::post('projects/{project}/api-key', ProjectApiKeyController::class);
        Route::apiResource('projects', ProjectController::class);

        Route::get('tokens', [ApiTokenController::class, 'index']);
        Route::post('tokens', [ApiTokenController::class, 'store']);
        Route::delete('tokens/{id}', [ApiTokenController::class, 'destroy']);
    });

    // Project-scoped: everything below works on the project named by the header/API key.
    Route::middleware('resolve.project')->group(function () {
        // Templates
        Route::get('templates-config', [TemplateController::class, 'getConfig']);
        Route::get('template-presets', [TemplateController::class, 'presets']);
        Route::post('template-presets/{slug}/adopt', [TemplateController::class, 'adoptPreset']);
        Route::get('templates', [TemplateController::class, 'index']);
        Route::post('templates', [TemplateController::class, 'store']);
        // Before the {template} routes: `reorder` is not a ULID, but the router would still try.
        Route::post('templates/reorder', [TemplateController::class, 'reorder']);
        Route::post('templates/{template}/duplicate', [TemplateController::class, 'duplicate']);
        Route::get('templates/{template}', [TemplateController::class, 'show']);
        Route::match(['put', 'patch'], 'templates/{template}', [TemplateController::class, 'update']);
        Route::delete('templates/{template}', [TemplateController::class, 'destroy']);

        // Videos
        Route::get('videos', [VideoController::class, 'index']);
        Route::get('videos/{video}', [VideoController::class, 'show']);
        Route::match(['put', 'patch'], 'videos/{video}', [VideoController::class, 'update']);
        Route::delete('videos/{video}', [VideoController::class, 'destroy']);
        // Batch mint. Additive: the per-stream route below stays, it is public API. Here rather
        // than under `streams/` because the video is what the hoisted work belongs to.
        Route::post('videos/{video}/downloads', [VideoController::class, 'downloads']);
        // Playback. Every output of the video at once: what a mint needs besides the signature is
        // per video, and a player should not have to pick an output before it knows what they are.
        // And many videos at once, same answer per video (a page of a catalogue, say).
        Route::post('videos/play', [VideoController::class, 'playMany']);
        Route::post('videos/{video}/play', [VideoController::class, 'play']);
        // Requeue a failed video. POST and not a status PATCH: `update` writes columns, and this
        // clears the failed run's batches, progress and errors before it moves the status — the
        // caller must not be able to reach the second half by writing `status` on the first.
        Route::post('videos/{video}/retry', [VideoController::class, 'retry']);

        // Streams
        Route::match(['put', 'patch'], 'streams/{stream}', [StreamController::class, 'update']);
        Route::delete('streams/{stream}', [StreamController::class, 'destroy']);
        Route::post('streams/{stream}/download', [StreamController::class, 'download']);

        // The delivery dashboard. Inside this group so a project resolves when the caller names
        // one — `ResolveProject` never aborts, it only makes the project available — which is what
        // lets the breakdowns that name viewers, addresses and titles be answered at all: narrowed
        // to a project they are the caller's own, and unnarrowed they are everyone's.
        Route::get('analytics', [AnalyticsController::class, 'index']);

        // The same dashboard a panel at a time, for the admin page to load in parallel and draw as
        // each lands. Same group and same rules as the whole payload above, which is built from
        // the same pieces.
        Route::get('analytics/summary', [AnalyticsController::class, 'summary']);
        Route::get('analytics/bandwidth', [AnalyticsController::class, 'bandwidth']);
        Route::get('analytics/top-ips', [AnalyticsController::class, 'topIps']);
        Route::get('analytics/top-videos', [AnalyticsController::class, 'topVideos']);
        Route::get('analytics/top-tracking-ids', [AnalyticsController::class, 'topTrackingIds']);
        Route::get('analytics/top-external-users', [AnalyticsController::class, 'topExternalUsers']);

        // The general read over `usage_daily`: any breakdown the allowlist permits, rather than a new
        // endpoint per question. Inside this group so that a project resolves when the caller has
        // one — `ResolveProject` never aborts, it only makes the project available — which lets the
        // controller demand project context for exactly the dimensions that need it (`video`,
        // `ip`) and for no others.
        Route::match(['get', 'post'], 'metrics', [MetricsController::class, 'query']);

        // Per-title delivered bytes, for a batch of the project's videos. The one metrics endpoint
        // inside this group, and deliberately: per-title bandwidth is only ever a tenant's own, so
        // the query is pinned to `project_id` and a ULID from elsewhere matches nothing. Leaving it
        // with the unscoped metrics would have let any key read any project's per-title bandwidth.
        Route::match(['get', 'post'], 'analytics/videos', [AnalyticsController::class, 'videos']);

        // Activity log (scoped to the project's videos)
        Route::get('activity-log', [ActivityLogController::class, 'index']);

        // Upload (S3) — the project arrives in the metadata: Uppy does not go through our axios
        // interceptor, so it never sends the X-Project-Ulid header.
        Route::get('s3/params', [MyCustomUppyController::class, 'getUploadParameters']);
        Route::post('s3/multipart', [MyCustomUppyController::class, 'createMultipartUpload']);
        Route::get('s3/multipart/{uploadId}', [MyCustomUppyController::class, 'getUploadedParts']);
        Route::post('s3/multipart/{uploadId}/complete', [MyCustomUppyController::class, 'completeMultipartUpload']);
        Route::delete('s3/multipart/{uploadId}', [MyCustomUppyController::class, 'abortMultipartUpload']);
        Route::get('s3/multipart/{uploadId}/{partNumber}', [MyCustomUppyController::class, 'signPartUpload']);
    });

    // Admin
    Route::middleware(['no-project-key', EnsureAdmin::class])->group(function () {
        Route::post('nodes/deploy', [NodeOperationController::class, 'deployMany']);
        Route::apiResource('nodes', NodeController::class);
        Route::post('nodes/{node}/deploy', [NodeOperationController::class, 'deploy']);
        Route::post('nodes/{node}/start', [NodeOperationController::class, 'start']);
        Route::post('nodes/{node}/stop', [NodeOperationController::class, 'stop']);
        Route::get('node-operations', [NodeOperationController::class, 'index']);
        Route::get('node-operations/{operation}/lines', [NodeOperationController::class, 'lines']);
        Route::post('nodes/{node}/validate', [NodeController::class, 'validateNode']);
        Route::get('nodes/{node}/cache-disks', [NodeController::class, 'cacheDisks']);
        Route::get('analytics/edges', [AnalyticsController::class, 'edges']);
        Route::post('nodes/{node}/bootstrap-token', [NodeController::class, 'generateBootstrapToken']);

        Route::get('node-environment', [NodeEnvironmentController::class, 'show']);
        Route::patch('node-environment', [NodeEnvironmentController::class, 'update']);

        Route::get('app-settings', [AppSettingsController::class, 'show']);
        Route::put('app-settings/ssh-key', [AppSettingsController::class, 'updateSshKey']);
        Route::post('app-settings/ssh-key/rotate', [AppSettingsController::class, 'rotateSshKey']);
        Route::put('app-settings/cloudflare', [AppSettingsController::class, 'updateCloudflare']);
        Route::delete('app-settings/cloudflare', [AppSettingsController::class, 'destroyCloudflare']);
        Route::get('app-settings/cloudflare/zones', [AppSettingsController::class, 'cloudflareZones']);

        Route::get('cdn-settings', [CdnSettingsController::class, 'show']);
        Route::patch('cdn-settings', [CdnSettingsController::class, 'update']);

        Route::apiResource('users', UserController::class);

        Route::get('settings/version', [SettingsController::class, 'versionCheck']);
    });
});

// Public
Route::get('nodes/{node}/bootstrap', [NodeController::class, 'bootstrapScript'])
    ->middleware('signed')->name('nodes.bootstrap');

// Webhooks
Route::post('webhooks/video-uploaded', [VideoWebhookController::class, 'handle'])
    ->middleware(VerifyWebhookSignature::class);

// Bandwidth ingest (Vector -> queue -> usage_daily)
Route::post('internal/bandwidth', [BandwidthController::class, 'ingest'])
    ->middleware(VerifyInternalSecret::class);

Route::get('/videos/{ulid}/{filename}', [VideoController::class, 'getAsset'])
    ->where('filename', 'storyboard(_\d+)?\.(vtt|jpg)|thumbnail\.jpg')
    ->withoutMiddleware(EnsureFrontendRequestsAreStateful::class)
    ->middleware('cache.headers:public;max_age=604800;s_maxage=604800;etag');
