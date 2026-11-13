<div align="center">

# 🎬 NukeVideo

**Open-source, self-hosted video processing & delivery engine.**

Upload → transcode with FFmpeg → package once to CMAF → stream adaptive HLS/DASH with token auth.
Ship a video backend without gluing together FFmpeg scripts, a packager, storage, and a CDN yourself.

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![Laravel](https://img.shields.io/badge/Laravel-PHP%208.5-FF2D20?logo=laravel&logoColor=white)](https://laravel.com)
[![Vue 3](https://img.shields.io/badge/Vue-3-4FC08D?logo=vuedotjs&logoColor=white)](https://vuejs.org)
[![Docker](https://img.shields.io/badge/Docker-ready-2496ED?logo=docker&logoColor=white)](https://www.docker.com)

</div>

> [!WARNING]
> NukeVideo is under active development. APIs, database schemas, and configuration may still change between releases. Pin a tagged image (`chikenare/nukevideo-api:vX.Y.Z`) if you run it in production.

---

## What it does

NukeVideo is the **core engine**, not a finished product with its own frontend. It exposes an API and an admin panel so you can plug video upload, encoding, and adaptive streaming into your own application.

```
Upload → S3 → Webhook → Encode (chunked, parallel) → Package (CMAF) → Deliver (HLS/DASH)
```

1. A file is uploaded straight to **S3** via multipart upload (Uppy, client-side signed).
2. An **S3 webhook** notifies the API that a new original is ready.
3. **Worker nodes** split the source into chunks and transcode them in parallel with **FFmpeg** (AV1 / H.264), picking the CRF per rendition from a **VMAF probe** to hit a target quality.
4. **shaka-packager** packages each rendition **once** into static **CMAF** — the same segments serve both HLS and DASH. Subtitles are packaged as CMAF too.
5. Content is delivered as adaptive **HLS/DASH** with time-limited **token authentication** from **self-hosted proxy nodes**.

## Features

- **🎞 Multi-codec encoding** — AV1 (SVT-AV1) and H.264/H.265 via FFmpeg, with reusable encoding templates (resolutions, bitrates, codecs, multi-output fallbacks).
- **🎯 Per-title VMAF CRF** — probes sample windows of each source, measures VMAF, and interpolates the CRF needed to hit a target quality per rendition instead of a fixed CRF for everything.
- **📦 Package once, stream everywhere** — shaka-packager writes static CMAF; one set of segments feeds both HLS and DASH (plus CMAF subtitles), so no on-the-fly repackaging.
- **⚡ Distributed, chunked processing** — scale by adding worker nodes; sources are chunked and encoded in parallel, and jobs auto-distribute to the least busy node.
- **🚚 Self-hosted delivery** — run your own **proxy nodes** (custom nginx, token validation, S3-backed, edge caching). No third-party edge.
- **🔐 Token-based access control** — Akamai-style stream tokens, validated at the edge.
- **🗄 S3-compatible storage** — AWS S3, MinIO, RustFS, iDrive e2, or any S3 API (transfers via `s5cmd`).
- **📊 Bandwidth & usage analytics** — Vector.dev ships edge access logs to the API; usage is tracked per video and per IP.
- **🔌 RESTful API + admin panel** — Sanctum auth (SPA sessions + API tokens), webhooks, node management over SSH, and a Vue 3 admin SPA.

## Tech stack

| Layer | Technology |
|-------|-----------|
| API | Laravel (PHP 8.5) in production |
| Admin panel | Vue 3, TypeScript, Vite |
| Queue | Redis + Laravel Horizon |
| Database | MariaDB 11 (MySQL-compatible) |
| Encoding | FFmpeg (SVT-AV1, x264/x265) + VMAF |
| Packaging | shaka-packager (static CMAF) |
| Delivery | Self-hosted proxy nodes (nginx) |
| Storage | S3-compatible (AWS, MinIO, RustFS, iDrive e2) via `s5cmd` |
| Analytics | MariaDB + Vector.dev |
| Routing | Traefik |
| Infra | Docker |

## Architecture

```
                         ┌──────────────┐
   Browser ──upload────> │  S3 storage  │ <──── originals + packaged CMAF
                         └──────┬───────┘
                                │ webhook
                         ┌──────┴───────┐
                         │     API      │  Laravel · Horizon · admin panel
                         └──────┬───────┘
              ┌────────────────┼────────────────┐
        ┌─────┴─────┐    ┌─────┴─────┐    ┌──────┴──────┐
        │  Worker   │    │   Redis   │    │   MariaDB   │
        │  nodes    │    │  (queue)  │    │ (+analytics)│
        │ FFmpeg +  │    └───────────┘    └─────────────┘
        │  shaka    │
        └───────────┘

   Delivery:
     Proxy nodes  →  nginx + token validation  →  S3   →  Client
```

## Quick start (development)

Everything runs in Docker. Dev uses `compose.yml` with Traefik and `*.nukevideo.localhost` domains (no `/etc/hosts` edits needed).

```bash
git clone https://github.com/chikenare/nukevideo.git
cd nukevideo

docker network create traefik          # external network required by compose.yml
cp .env.example .env

docker compose up -d --build
docker compose exec nukevideo-api php artisan key:generate
docker compose exec nukevideo-api php artisan migrate --seed
```

Then open:

| Service | URL |
|---------|-----|
| Admin panel | http://app.nukevideo.localhost |
| API | http://api.nukevideo.localhost |
| Docs | http://docs.nukevideo.localhost |
| Adminer (DB) | http://adminer.nukevideo.localhost |
| RustFS console | http://s3-ui.nukevideo.localhost |
| RedisInsight | http://redis.nukevideo.localhost |
| Traefik dashboard | http://localhost:8080 |

**Default login:** `test@example.com` / `password`

## Production

Production images are built and pushed to Docker Hub automatically on every `v*` git tag (`chikenare/nukevideo-api`, `chikenare/nukevideo-proxy`), for `linux/amd64` and `linux/arm64`.

The provided `docker-compose.yml` pulls those images and runs the core stack — API, Horizon, scheduler, MariaDB and Redis:

```bash
cp .env.example .env      # then set APP_KEY, DB/Redis creds, S3, WEBHOOK_SECRET, INTERNAL_API_SECRET...
TAG=v1.0.0 docker compose up -d
docker compose exec nukevideo-api php artisan migrate --force
```

Then, from the admin panel:

1. **Add storage** — point NukeVideo at your S3 bucket and configure the upload webhook.
2. **Add worker nodes** — register a server with an SSH key and deploy the worker image; workers pull encoding jobs from the queue.
3. **Add proxy nodes** — deployed over SSH like workers, they deliver the packaged video (see below).

See the [Deployment guide](docs/guide/configuration.md) for the full environment reference.

## Delivery: proxy nodes

NukeVideo packages everything to static CMAF on S3, so delivery is just "serve those files with token auth + caching." That is the job of **self-hosted proxy nodes**: a custom nginx build that validates stream tokens, fetches packaged segments from S3 with AWS auth, caches them at the edge (manifests for 30 seconds), and supports Cloudflare real-IP. Managed and deployed from the admin panel over SSH.

## Documentation

Full docs (VitePress) live in [`docs/`](docs/) and cover the processing pipeline, encoding templates, nodes, streaming, CDN, and the API reference. Run them locally at http://docs.nukevideo.localhost, or:

```bash
docker compose exec nukevideo-docs pnpm run docs:dev   # if not already up via compose
```

## Contributing

Issues and pull requests are welcome. Please open an issue to discuss substantial changes first. Follow the existing code style — PHP is formatted with Pint, and the frontend with ESLint + Prettier.

## License

Released under the [MIT License](LICENSE).
