# CLAUDE.md

Guidance for Claude Code in this repository. Read it before writing code.

## What NukeVideo is

An open-source video processing and delivery engine: it takes a file uploaded straight to S3,
transcodes it in parallel by spreading keyframe-aligned windows across remote worker nodes (CPU or
GPU), packages it as CMAF (HLS + DASH over the same segments) and serves it through a CDN. The
admin panel is a first-party SPA, and there is a public API for external consumers.

Monorepo: **Laravel 13 backend at the root**, **Vue 3 SPA in `front/`**, **VitePress docs in `docs/`**.

## Layout

```
.                       # Laravel 13 (PHP ^8.4) at the root
├── app/
│   ├── Console/Commands/   # videos:dispatch, videos:reap, videos:prune, videos:retry
│   ├── Data/               # Spatie Data — request and response DTOs (there are NO FormRequests)
│   ├── DTOs/               # plain DTOs, not Spatie (UploadMeta, StorageWebhookData)
│   ├── Enums/              # backed string enums
│   ├── Http/Controllers/   # plus Controllers/Api for admin and account endpoints
│   ├── Http/Middleware/    # ResolveProject, DenyProjectKey, EnsureAdmin, Verify*
│   ├── Jobs/ + Jobs/Concerns/
│   ├── Models/             # Video, Output, Stream, Template, Node, Project, User
│   ├── Observers/          # registered with #[ObservedBy] on the model
│   ├── Rules/              # rules used inside the Data objects' rules()
│   ├── Services/           # plus Services/Cdn (providers) and Services/Concerns (traits)
│   ├── Settings/           # spatie/laravel-settings (AppSettings holds the one SSH key)
│   └── Support/            # stateless helpers: Cpu, Gpu, MediaDuration, MediaSource
├── database/
│   ├── migrations/         # MariaDB in production, SQLite in tests
│   └── settings/           # spatie/laravel-settings migrations
├── resources/deploy/       # the node deploy, as bash; NodeService only prepends a variable header
├── docs/                   # VitePress (guide/ + api/)
├── front/                  # Vue 3 + Vite SPA (its own pnpm project)
├── vod/                    # delivery edge nginx (secure_token)
├── vector/                 # Vector config: edge logs → API → usage_daily
└── compose.yml             # the whole local stack
```

**There is no `app/Actions`, no `app/Http/Requests`, no `app/Policies` and no `routes/web.php`.**

## Commands

Everything runs in Docker. Never run `php`, `composer`, `pnpm` or `artisan` directly on the host.
The backend container is **`nukevideo-api`** (not `app`).

```bash
docker compose up -d                                          # bring the stack up
docker compose exec nukevideo-api php artisan <command>
docker compose exec nukevideo-api composer <command>

# Tests — ALWAYS clear the config first (see "Gotchas")
docker compose exec nukevideo-api php artisan config:clear
docker compose exec nukevideo-api php artisan test

docker compose exec nukevideo-api ./vendor/bin/pint           # format PHP
docker compose exec nukevideo-api php artisan migrate
docker compose exec nukevideo-api php artisan typescript:transform   # regenerates front/src/types/generated.d.ts
docker compose exec nukevideo-api php artisan data:cache-structures  # after editing app/Data
```

Frontend (`front/`, **pnpm only** — the lockfile is `pnpm-lock.yaml`). The Vite dev server already
starts with `docker compose up -d` (it is the container's `CMD`), so `exec` is only for the checks:

```bash
docker compose exec nukevideo-front pnpm run lint         # eslint . --fix --cache
docker compose exec nukevideo-front pnpm run type-check   # vue-tsc --build
docker compose exec nukevideo-front pnpm run format       # prettier
```

Docs (`docs/`, pnpm as well): the `nukevideo-docs` container serves `docs:dev` at
`docs.nukevideo.localhost`. To build: `docker compose exec nukevideo-docs pnpm run docs:build`.

**CI is the reference for what must pass** (`.github/workflows/ci.yml`, on PRs to `main` only):
`./vendor/bin/pint --test` and `php artisan test` at the root, plus `pnpm exec eslint .` (no
`--fix`) and `pnpm run type-check` in `front/`. Images are published on `v*` tags only.

> `composer dev` and `composer setup` are stock Laravel scripts and **are broken here**: they call
> `npm run dev` and `npm install` at the root, where there is no `package.json`. Use
> `docker compose up -d`. `composer test` does work (it runs `config:clear`, then `artisan test`).

## Stack

**Backend:** Laravel 13, PHP ^8.4 (CI builds on 8.5) · Sanctum (session for the SPA, plus personal
and project tokens) · Spatie Laravel Data (DTOs and validation) ·
spatie/laravel-typescript-transformer (generates the frontend types) · Horizon on Redis ·
Telescope (local only — it is in `dont-discover` and registered conditionally) · Pest 5 · Pint ·
spatie/laravel-activitylog · spatie/laravel-settings · Sentry · phpseclib (SSH to the nodes).

**Frontend:** Vue 3 `<script setup lang="ts">` · Vite 8 · Pinia (setup stores) · Vue Router ·
Tailwind v4 (no `tailwind.config.js`; the config lives in CSS) · shadcn-vue on top of reka-ui ·
axios · shaka-player / hls.js / dashjs · Uppy.

**Infrastructure:** Docker + Traefik (`*.nukevideo.localhost`) · **MariaDB 11** (not PostgreSQL) ·
Redis · RustFS (S3) · **ffmpeg** and **shaka-packager** (both compiled in the
Dockerfile) · **s5cmd** for every S3 transfer · Vector.

## The encoding pipeline

This is the heart of the project; understand it before touching `app/Jobs` or `app/Services`.

Direct multipart upload to S3 (Uppy) → bucket webhook → `OnVideoUploaded` creates the `Video`
(status `PENDING`) and its `original` stream → the `videos:dispatch` scheduler claims a worker slot
and dispatches **`PrepareVideoJob`** (`orchestration` queue), which downloads the source from
primary S3 **exactly once**, mirrors it to the LAN `chunks` store, probes it into `Output`s and
`Stream`s, runs the probes (per-title CRF, bitrate, preflight), plans keyframe-aligned windows and
fans out **one `Bus::batch` per hardware queue** with a `ProcessChunkJob` per (window × rendition).
`EncodeSidecarTracksJob` (audio and subtitles in a single pass, never chunked),
`ExtractThumbnailJob` and `GenerateVideoStoryboard` run alongside it. The last batch's `then()`
moves the video to `UPLOADING` and dispatches **`PackageVideoJob`** (`packaging` queue,
`ShouldBeUnique`), which concatenates the chunks with `-c copy`, runs shaka-packager once per
distinct height over a shared segment tree, grafts the subtitles in with `ManifestEditor` and
`s5cmd sync`s everything to primary S3. `CompletesVideo` closes the video out.

Concepts worth having straight:

- **Stream** = one concrete track (`original` | `video` | `audio` | `subtitle`). A "rendition" is a
  video stream. Segments live under `{videoUlid}/{streamUlid}/`.
- **Output** = one CMAF package / manifest family, 1:1 with an entry of the template's
  `query.outputs[]`. **Streams are shared across outputs** when their resolved parameters are
  identical; what is per-output is the manifests.
- **Template** = a JSON `query` column holding `outputs[].variants[]`. `config/ffmpeg.php` is the
  codec catalogue and the schema for every parameter (rules, `available_for`, flag template).
- **`chunks` disk vs `s3`**: `chunks` is a RustFS on the LAN, disposable (mirrored source, chunks,
  staged sidecars). Primary `s3` is read once and written once per video.
- **Queues**: `default` (API host only), `orchestration` (every worker), `packaging`,
  `video-processing` (CPU) and `video-processing-{intel,nvidia}` (GPU nodes do not drain the CPU
  queue). `config/horizon.php` picks the supervisors from `NODE_TYPE`/`NODE_ACCEL`.

Project-specific configs: `ffmpeg.php` (codec and parameter catalogue), `packager.php`, `nuke.php`
(webhook/internal secrets, worker timeout), `template-presets.php`, `horizon.php`,
`filesystems.php` (the `chunks` disk).

## Backend conventions

- **Business logic lives in `app/Services`**, not in controllers. There are no Actions — do not
  introduce the pattern. Trivial CRUD does sit inline in the controller; destructive or multi-step
  work goes to a service.
- **Data objects are the validation layer.** There are no FormRequests and nothing calls
  `$request->validate()`.
  - Input: `app/Data/{Domain}/{Store|Update}{Model}Data.php` extends `App\Data\RequestData`, which
    supplies `toDatabase()` (a snake_case array, omitting absent `Optional` fields).
  - Output: `app/Data/{Model}Data.php` extends `Data`, with a `fromModel()` built from named
    arguments.
  - **Casing**: the global *input* mapper is snake_case and the *output* one is camelCase
    (`config/data.php`). That is why every property that has to accept camelCase from the panel
    carries `#[MapInputName(CamelCaseMapper::class)]` **on the property** — a class-level
    `#[MapName]` is ignored on input. `rules()` keys are written in camelCase.
  - No `#[TypeScript]` attributes: the whole `app/Data` and `app/Enums` directories are transformed.
- **Thin controllers**: promoted constructor injection, the Data object injected as an action
  argument, and `response()->json(['data' => XData::fromModel($m)])` on the way out.
- **Project scoping**: `$request->project()` is a Request macro that aborts 400 when no project is
  resolved. Every resource query starts there
  (`$request->project()->videos()->where('ulid', $ulid)->firstOrFail()`). Looking a resource up by
  ULID without that scoping is a cross-tenant leak, not a style slip.
- **Routes** (`routes/api.php`, flat, **unversioned**): three groups inside `auth:sanctum` —
  `no-project-key` (account-wide), `resolve.project` (project resources) and
  `['no-project-key', EnsureAdmin::class]` (admin). Picking the wrong group is a security bug: a
  project API key authenticates **as the project**. There is **no API-wide rate limit**, and that is
  deliberate rather than an oversight: `throttleApi()` would also cover the S3 multipart routes,
  which sign one request per part — a thousand of them for a single large upload — so any ceiling
  loose enough to upload through protects nothing. `throttle:login` is the only limiter.
- **Jobs**: always set `$tries` and `$backoff` deliberately, with a comment on the reasoning; heavy
  ones also set `$timeout`. The queue goes in a class constant
  (`private const QUEUE = 'orchestration';`) and dispatch goes through `->onQueue(self::QUEUE)`.
  Constructors take **primitives**, not models. `failed()` marks the video with `markAsFailed()`.
- **Models**: the ULID is assigned in a hand-written `boot()` (there is no `HasUlid` trait); the
  primary key stays the auto-increment and the ULID is the public identifier. `casts()` is a
  method. Observers are registered with `#[ObservedBy]`. Models carry a fair amount of domain logic
  and the storage-path constants.
- **Migrations**: see `/migration`. Anonymous class, a docblock explaining **why**, always a
  `down()`, `Schema::hasColumn` guards so they can be re-run, and compatible with MariaDB **and**
  SQLite (no `JSON_EXTRACT`; guard `dropForeign` for sqlite).
- **Style**: Pint on the default preset (there is no `pint.json`). The codebase does **not** use
  `declare(strict_types=1)` outside `Services/Cdn`, and has no
  `final` classes: follow what is there rather than introducing a second style.
- Comments explain **why**, and reason about failure modes. That density is the house style.

## Frontend conventions

- Composition API with `<script setup lang="ts">`, always.
- **Never hand-write API types.** They come from `front/src/types/generated.d.ts`
  (`App.Data.VideoData`, `App.Enums.VideoStatus`), generated from the Data objects. If something is
  missing, fix the Data object and regenerate. The file is committed; do not edit it.
- `front/src/services/` — one class per domain with a `BASE_PATH`,
  `constructor(private api = apiClient)` and a single exported instance. The central axios client is
  `front/src/services/api.ts` (interceptors: `X-Project-Ulid` header from localStorage, 401 →
  `/login`, 422 → `ValidationException`).
- Pages live in `front/src/pages/` (not `views/`). Pinia stores are setup stores.
- `front/src/components/ui/` is generated shadcn-vue: never edit by hand (it is excluded from ESLint).
- Prettier: no semicolons, single quotes, print width 100.

## Gotchas that cost hours

- **`config:clear` before the tests.** `TestCase::setUp` drops and re-migrates the database; with a
  cached config it reads the development connection and **wipes the real database**. There is a
  guard that aborts, but clear the config anyway.
- **Spatie Data structure cache**: after editing a class in `app/Data`, new properties vanish from
  the JSON. It lives in the `file` store, not Redis: `php artisan data:cache-structures`.
- **The database is MariaDB**, whatever the global guide says about PostgreSQL. Write migrations
  for MySQL.
- Worker containers run a baked image with no code mount: testing a change there means `docker cp`
  plus `horizon:terminate`. Nodes never build: a deploy pulls `nukevideo-{api,proxy}:node-dev`
  from `DOCKER_REGISTRY` while `APP_ENV=local` (the released tag otherwise), so shipping the
  working copy to a node is `bin/push-node-dev` (builds the release targets on the host and pushes
  them) followed by a redeploy from the panel. `:dev` is compose's own runtime-only image and must
  not be reused. A development panel refuses to deploy without `DOCKER_REGISTRY`.
- The deploy runs over SSH with no terminal, so the node user is either root or has passwordless
  sudo (`NOPASSWD`); the script checks that first and aborts with the fix. A user in the `docker`
  group is root-equivalent anyway, so there is nothing to gain from a restricted one.
- Everything a deploy names is prefixed `nukevideo_dev_` in local and `nukevideo_` otherwise
  (`Node::containerPrefix()`). Both environments number their nodes from their own database, so
  without that a dev deploy would replace the production containers on the same host. Vector is
  deployed **only on proxy nodes** — nothing else produces the access-log lines it ships.
- Retrying a video takes more than `status = pending`: delete its `job_batches` row first (the
  chunks survive, so the retry is a cache hit).
- **`Project` extends `Model`, not `Authenticatable`.** A project API key authenticates *as the
  project*, so `$request->user()` can be a Project: `getAuthIdentifier()` fatals on it, and its `id`
  comes from a different sequence than a user's, so reading it as a user id silently answers with
  another account's data — use `$request->user()->accountId()`, which both models implement.
  `is_admin` needs nothing: the attribute is simply absent on a Project, so Eloquent answers null.
- Master manifests are not served from the bucket: the edge asks `GET internal/manifests/{video}/{file}`
  and `DynamicManifestService` builds them from the options the link's token names: an opaque
  `mo` key signed into the token, resolved by `ManifestOptionsRegistry` to a `ManifestOptions`.
  The key must stay IN the token — tokens are not unique per mint, so anything merely filed under
  one would cross between viewers. A new personalization is one `ManifestTransformer`
  plus one option property — no edge change. That endpoint runs on every playback start and
  touches no database, on purpose; and it must stay viewer-agnostic, because built copies are
  shared by every link with equal options. When the API is unreachable the edge falls back to the
  static manifest, so nothing built here may be an entitlement yet.
- Metrics live in MariaDB, in `usage_daily`: one row per day per combination of dimensions, and
  the unique key over those dimensions IS the aggregation. Every write goes through
  `UsageService::add()`, which upserts `value = value + new`; a plain insert or an Eloquent
  `upsert()` either fails on the key or overwrites a total. `add()` also keeps three per-dimension
  rollups of delivered bytes (`usage_daily_by_{video,ip,tracking_id}`, `UsageService::ROLLUPS`) in
  the same transaction, and the dashboard reads those, not `usage_daily`: a row written any other
  way never reaches the dashboard. Reads go through `AnalyticsService`
  on the query builder with nothing MariaDB-only, because the suite runs them on SQLite.
  `country` is the one column outside the key: `GeoIp` resolves it from the address at ingest, off
  a GeoLite2 file on the local disk of the container draining `default` — there is no shared
  volume, so the download is a queued job (`RefreshGeoIpJob`: weekly, and on demand when the ingest finds
  the file missing), not a scheduled command.

## Rules for Claude

- Before creating a file, look for the equivalent that already exists and follow its pattern.
- Do not add dependencies without asking.
- **Never edit generated files**: `front/src/types/generated.d.ts`, `front/src/components/ui/`.
  Fix the source and regenerate.
- Do not touch `.env` or secrets; if a variable is missing, say so and add it to `.env.example`.
- Do not modify infrastructure (`compose.yml`, `Dockerfile`, Traefik, the `vod/` nginx, CI) unless
  explicitly asked.
- When you finish backend work: Pint and the relevant tests. Frontend: lint and type-check.
- If a change implies a destructive data migration, stop and ask.
- Conventional Commits, in English, imperative mood. Do not push unless asked.
- **This is an open-source project: everything in the repository is written in English** — code,
  names, comments, docblocks, commit messages, documentation and this file included.
