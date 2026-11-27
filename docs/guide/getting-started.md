# Getting Started

This guide walks you through running NukeVideo locally for development with Docker, and then through a [production install](#production).

## Prerequisites

- [Docker](https://docs.docker.com/get-docker/) and Docker Compose
- [Git](https://git-scm.com/)

The local stack is defined in `compose.yml`. Every service is routed under `*.nukevideo.localhost` by Traefik, which `compose.yml` does **not** start: it attaches the whole stack to an **external** Docker network named `traefik`, and expects a Traefik on that network watching Docker labels. The Quick Start runs one; skip that step if you already have one.

## Quick Start

```bash
git clone https://github.com/xdelirio/nukevideo.git
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

Sign in at the admin SPA (see the URL below). The seeder is for development only: never seed a production install, create the first admin with `php artisan users:create-admin` instead (see [Production](#production)).

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

## Production

Release images are published to Docker Hub on every `v*` tag (`chikenare/nukevideo-api`, `chikenare/nukevideo-proxy`, for `linux/amd64` and `linux/arm64`). `compose.prod.yml` runs the core stack from them: the API (which also serves the admin panel), Horizon, the scheduler, MariaDB and Redis. It is not `compose.yml`, which is the development stack, so name it with `-f` on every command.

```bash
git clone https://github.com/xdelirio/nukevideo.git && cd nukevideo
cp .env.example .env      # then edit it, see the checklist below

echo "base64:$(openssl rand -base64 32)"   # paste into APP_KEY

export TAG=vX.Y.Z         # a release tag; `latest` moves under you
docker compose -f compose.prod.yml up -d
docker compose -f compose.prod.yml exec nukevideo-api php artisan users:create-admin
```

The API container runs the migrations itself every time it starts, so there is no separate migrate step. Set `APP_KEY` before the first start: the migrations store encrypted settings.

`compose.prod.yml` publishes the API on port `8080` (`API_PORT`) over plain HTTP. Put a TLS-terminating reverse proxy in front of it; the panel's session cookie is only safe over HTTPS.

### `.env` checklist

| Variable | Value |
|----------|-------|
| `APP_ENV` / `APP_DEBUG` | `production` / `false`. Debug mode prints stack traces, and with them configuration, to anyone who triggers an error. |
| `APP_KEY` | The `openssl` output above. It encrypts the stored SSH key, the Cloudflare token and the Tailscale key: keep a copy, because a lost key makes them unreadable. |
| `APP_URL` | The public HTTPS URL you open the panel at, which is also the API's (`https://video.example.com`). The panel signs in with a session cookie that is only honoured for this host, so opening it at another address (the server's IP, a second domain) logs you in to nothing; add those to `SANCTUM_STATEFUL_DOMAINS`. |
| `DB_HOST` / `REDIS_HOST` | `db` / `redis`, the service names in `compose.prod.yml`. |
| `DB_PASSWORD` | A strong password. The MariaDB container is initialised with it, as the root password as well. |
| `WEBHOOK_SECRET`, `INTERNAL_API_SECRET` | Two different random values, e.g. `openssl rand -hex 32` each. |
| `AWS_*` | Your S3 bucket and credentials (see [S3 Storage](/guide/configuration#s3-storage)). |
| `SESSION_SECURE_COOKIE` | `true`, so the session cookie is never sent over plain HTTP. |
| `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN` | Leave unset while the panel is the one the API serves. Only for a [panel hosted elsewhere](#hosting-the-panel-elsewhere). |

The full reference is on the [Configuration](/guide/configuration) page.

### The admin panel

The API image serves the panel at the root of `APP_URL`, on the same origin as the API, so there is nothing else to deploy: open `APP_URL`, sign in with the admin you created, and continue with [Nodes](/guide/nodes) — workers to encode, proxies to deliver.

### Hosting the panel elsewhere

The panel is a static SPA (`front/`), so it can also live on a static host (Cloudflare Pages, S3, any CDN) on its own domain. Build it against your API, and serve `front/dist/` from a host that answers unknown paths with `index.html` (the router uses HTML5 history):

```bash
cd front
pnpm install --frozen-lockfile
VITE_URL_API=https://api.example.com/api pnpm run build-only
```

It still signs in with the API's session cookie, so it must be on the same site as the API (`app.example.com` and `api.example.com`), and the API needs two more variables:

| Variable | Value |
|----------|-------|
| `SANCTUM_STATEFUL_DOMAINS` | The panel's host (`app.example.com`). Only requests from these hosts are authenticated by the session cookie. |
| `SESSION_DOMAIN` | The parent domain the two share (`.example.com`), so the cookie reaches both. |

## Next Steps

- [What is NukeVideo?](/guide/what-is-nukevideo) — Architecture overview.
- [Video Processing](/guide/video-processing) — The encoding pipeline.
- [Templates](/guide/templates) — Define encoding configurations.
- [Streaming & VOD](/guide/streaming) — How packaged content is served.
- [Nodes](/guide/nodes) — Add worker and proxy nodes.
- [CDN & Delivery](/guide/cdn) — How proxy nodes deliver and protect playback.
- [Configuration](/guide/configuration) — Environment variables.
- [API Reference](/api/authentication) — Start integrating with the API.
