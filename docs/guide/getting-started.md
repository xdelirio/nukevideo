# Getting Started

This guide walks you through running NukeVideo locally for development with Docker.

## Prerequisites

- [Docker](https://docs.docker.com/get-docker/) and Docker Compose
- [Git](https://git-scm.com/)

The local stack is defined in `compose.yml`. Every service is routed under `*.nukevideo.localhost` by Traefik, which `compose.yml` does **not** start: it attaches the whole stack to an **external** Docker network named `traefik`, and expects a Traefik on that network watching Docker labels. The Quick Start runs one; skip that step if you already have one.

## Quick Start

```bash
git clone https://github.com/chikenare/nukevideo.git
cd nukevideo

# Traefik routes all services; this external network must exist first
docker network create traefik
docker run -d --name traefik --restart unless-stopped --network traefik \
  -p 80:80 -p 8080:8080 -v /var/run/docker.sock:/var/run/docker.sock:ro \
  traefik:v3.6.25 --api.insecure=true --providers.docker=true \
  --providers.docker.exposedbydefault=false --entrypoints.web.address=:80

cp .env.example .env   # then see "Local .env" below

# The docs container serves without installing; the frontend installs on start
docker compose run --rm nukevideo-docs pnpm install
docker compose up -d --build

# Application setup (run inside the API container, which mounts the checkout)
docker compose exec nukevideo-api composer install
docker compose exec nukevideo-api php artisan key:generate
docker compose exec nukevideo-api php artisan migrate --seed

# The scheduler and Horizon exit on a fresh checkout (no vendor/, no APP_KEY yet): start them again
docker compose up -d
```

### Local .env

The containers reach each other by service name, so point the copied `.env` at them before going further:

- `REDIS_HOST=redis` (`.env.example` ships `127.0.0.1`, which inside a container is the container itself). `DB_HOST=db` already matches.
- A non-empty `DB_PASSWORD`: the MariaDB container is initialised with it as the root password.
- For the bundled RustFS: `AWS_ENDPOINT=http://rustfs:9000` and `AWS_USE_PATH_STYLE_ENDPOINT=true`, with its credentials and a bucket created in the RustFS console.

## Default Login

The seeder creates an admin user:

- **Email:** `test@example.com`
- **Password:** `password`

Sign in at the admin SPA (see the URL below).

## Local URLs

Traefik publishes each service under a `*.nukevideo.localhost` hostname (these resolve automatically, no `/etc/hosts` edits needed):

| Service | URL |
|---------|-----|
| API (Laravel) | `http://api.nukevideo.localhost` (also published directly on `http://localhost:8089`) |
| Admin SPA | `http://app.nukevideo.localhost` |
| Docs | `http://docs.nukevideo.localhost` |
| Adminer (DB UI) | `http://adminer.nukevideo.localhost` |
| RustFS S3 API | `http://s3-data.nukevideo.localhost` |
| RustFS console | `http://s3-ui.nukevideo.localhost` |
| RedisInsight | `http://redis.nukevideo.localhost` |
| Traefik dashboard | `http://localhost:8080` (the Traefik started above) |

## Next Steps

- [What is NukeVideo?](/guide/what-is-nukevideo) — Architecture overview.
- [Video Processing](/guide/video-processing) — The encoding pipeline.
- [Templates](/guide/templates) — Define encoding configurations.
- [Streaming & VOD](/guide/streaming) — How packaged content is served.
- [Nodes](/guide/nodes) — Add worker and proxy nodes.
- [CDN & Delivery](/guide/cdn) — How proxy nodes deliver and protect playback.
- [Configuration](/guide/configuration) — Environment variables.
- [API Reference](/api/authentication) — Start integrating with the API.
