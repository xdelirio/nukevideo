ARG PHP_FPM_IMAGE=serversideup/php:8.5-fpm-nginx
ARG S5CMD_IMAGE=peakcom/s5cmd:v2.3.0
ARG SHAKA_PACKAGER_VERSION=v3.7.2

FROM ${S5CMD_IMAGE} AS s5cmd

FROM alpine:3.20 AS shaka-builder

ARG TARGETARCH
ARG SHAKA_PACKAGER_VERSION
# The release binaries of SHAKA_PACKAGER_VERSION. A download that does not hash to these fails
# the build, so a replaced release asset cannot ship. Bumping the version means bumping both.
ARG SHAKA_PACKAGER_SHA256_X64=88b022b8cb12602ddb539972efd07a3496ea64f8662a484798c96e95afa41fd8
ARG SHAKA_PACKAGER_SHA256_ARM64=e4a43aaa8fdb87d0306876bc41581b371d7082e9d1b8469aef06a4e74004fd69

RUN apk add --no-cache curl && \
    if [ "$TARGETARCH" = "arm64" ]; then \
      SHAKA_ARCH="arm64"; SHAKA_SHA256="$SHAKA_PACKAGER_SHA256_ARM64"; \
    else \
      SHAKA_ARCH="x64"; SHAKA_SHA256="$SHAKA_PACKAGER_SHA256_X64"; \
    fi && \
    curl -fSL "https://github.com/shaka-project/shaka-packager/releases/download/${SHAKA_PACKAGER_VERSION}/packager-linux-${SHAKA_ARCH}" \
      -o /usr/local/bin/packager && \
    echo "${SHAKA_SHA256}  /usr/local/bin/packager" | sha256sum -c - && \
    chmod +x /usr/local/bin/packager

FROM alpine:3.20 AS ffmpeg-builder

ARG TARGETARCH
# Pinned, not BtbN's rolling `latest`: two images built a day apart used to carry different
# encoders, so a release was not reproducible and the encodes of one fleet could differ by node.
# Pinned to a build AND its checksums (from the release's checksums.sha256), so a replaced asset
# fails the build instead of shipping.
#
# BtbN prunes its daily builds after about two weeks and keeps the month-end ones for about two
# years: a daily tag here stops building once it is pruned. Prefer a month-end build
# (autobuild-YYYY-MM-{28..31}-…). To bump: pick the tag, take the `N-…` id from its asset names,
# and copy both linux64/linuxarm64 `-gpl.tar.xz` lines from its checksums.sha256.
ARG FFMPEG_BUILD_TAG=autobuild-2026-11-24-12-41
ARG FFMPEG_BUILD_ID=N-129267-g9ec5cf1aac
ARG FFMPEG_SHA256_AMD64=17030ca1c9c7e40bfaecb46a1c57292704a3ac8b853a62ee117b3a4b10c518e1
ARG FFMPEG_SHA256_ARM64=32ea09918512e76ece3a207481b1c6b072c2ef1ee4a02d9d334b7ab41c93fbe2

RUN apk add --no-cache curl tar xz && \
    if [ "$TARGETARCH" = "arm64" ]; then \
      FFMPEG_ARCH="linuxarm64"; FFMPEG_SHA256="$FFMPEG_SHA256_ARM64"; \
    else \
      FFMPEG_ARCH="linux64"; FFMPEG_SHA256="$FFMPEG_SHA256_AMD64"; \
    fi && \
    curl -fSL "https://github.com/BtbN/FFmpeg-Builds/releases/download/${FFMPEG_BUILD_TAG}/ffmpeg-${FFMPEG_BUILD_ID}-${FFMPEG_ARCH}-gpl.tar.xz" \
      -o /tmp/ffmpeg.tar.xz && \
    echo "${FFMPEG_SHA256}  /tmp/ffmpeg.tar.xz" | sha256sum -c - && \
    tar -xJf /tmp/ffmpeg.tar.xz -C /tmp && \
    mv /tmp/ffmpeg-*/bin/ffmpeg /usr/local/bin/ffmpeg && \
    mv /tmp/ffmpeg-*/bin/ffprobe /usr/local/bin/ffprobe && \
    chmod +x /usr/local/bin/ffmpeg /usr/local/bin/ffprobe && \
    rm -rf /tmp/ffmpeg*

# --- PHP base with common user setup ---
FROM ${PHP_FPM_IMAGE} AS php-base

# Force UTF-8 so mkvmerge -J doesn't truncate its JSON on accented track names.
ENV LANG=C.UTF-8

USER root

COPY --from=ffmpeg-builder /usr/local/bin/ffprobe /usr/local/bin/ffmpeg /usr/local/bin/

COPY --from=shaka-builder /usr/local/bin/packager /usr/local/bin/packager

COPY --from=s5cmd /s5cmd /usr/local/bin/s5cmd

ARG USER_ID=1000
ARG GROUP_ID=1000

RUN docker-php-serversideup-set-id www-data $USER_ID:$GROUP_ID && \
    docker-php-serversideup-set-file-permissions --owner $USER_ID:$GROUP_ID



# --- PHP runtime: php-base + system packages + extensions, shared by api-dev and api-prod ---
FROM php-base AS php-runtime

ARG TARGETARCH

# media-types: /etc/mime.types drives s5cmd Content-Type guessing — VOD edge secure_token needs exact manifest types
# Intel QSV runtime for GPU nodes (amd64 only — no arm64 builds of the Intel stack): libvpl
# dispatcher -> libmfx-gen -> iHD VA-API driver; the iHD encode entrypoints only ship in the non-free build.
RUN sed -i 's/^Components: main$/Components: main non-free non-free-firmware/' /etc/apt/sources.list.d/*.sources && \
    apt-get update && apt-get install -y --no-install-recommends \
      openssh-client media-types mkvtoolnix && \
    if [ "$TARGETARCH" = "amd64" ]; then \
      apt-get install -y --no-install-recommends \
        intel-media-va-driver-non-free libvpl2 libmfx-gen1.2 libva-drm2; \
    fi && \
    rm -rf /var/lib/apt/lists/*
RUN install-php-extensions redis intl

# --- API dev ---
FROM php-runtime AS api-dev

USER www-data

# --- API build ---
FROM php-base AS api-build

ARG APP_VERSION=dev

USER root

WORKDIR /var/www/html

COPY composer.json composer.lock ./

RUN --mount=type=cache,target=/root/.composer/cache,id=composer-cache \
    composer install \
    --no-dev \
    --optimize-autoloader \
    --prefer-dist \
    --no-interaction \
    --no-scripts

COPY --chown=www-data:www-data . .

ENV APP_VERSION=${APP_VERSION}

RUN mkdir -p bootstrap/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/framework/cache/data \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

RUN php artisan package:discover

# --- API prod ---
FROM php-runtime AS api-prod

ENV PHP_OPCACHE_ENABLE=true

WORKDIR /var/www/html

COPY --from=api-build --chown=www-data:www-data /var/www/html /var/www/html

COPY docker/entrypoint.d/99-laravel-autorun.sh /etc/entrypoint.d/99-laravel-autorun.sh

USER www-data


FROM alpine:3.20 AS proxy-builder

ENV NGINX_VERSION=1.30.6
# Kaltura deleted its nginx module repositories from GitHub in November 2026, with no notice and no
# advisory, and every build that was not served from cache started failing on a 404. Until the edge
# stops needing them, the three modules come from this project's own forks, so that nobody else can
# take them away again — and pinned by commit, never by tag or branch: a git commit id is a hash of
# its content, so no copy can hand over anything but the code Kaltura published under it. Each id
# was checked against the Software Heritage archive of the ORIGINAL repository
# (archive.softwareheritage.org/browse/origin/?origin_url=https://github.com/kaltura/<name>):
#   aws-auth      dbbac97 is what the tag 1.1 pointed to
#   secure-token  18b97a8 is what the tag 1.5 pointed to
#   akamai        818d94a is the same master commit as before
ENV NGINX_AWS_AUTH_REPO=xdelirio/nginx-aws-auth-module
ENV NGINX_AWS_AUTH_VERSION=dbbac974f0699328a63f497cd911a4f991faed9b
# A commit, not the 1.1 tag: 1.1 (2017) parses no `ip` field, and the check that refuses a token
# from any other address than the one it names only exists on master (kaltura PR #22, 2021), which
# was never tagged. The panel's "bind to the viewer's IP" setting relies on it.
ENV NGINX_AKAMAI_TOKEN_VALIDATE_REPO=xdelirio/nginx-akamai-token-validate-module
ENV NGINX_AKAMAI_TOKEN_VALIDATE_VERSION=818d94a712e6bbd32d9d086d2c96ccd98f79e438
ENV NGINX_SECURE_TOKEN_REPO=xdelirio/nginx-secure-token-module
ENV NGINX_SECURE_TOKEN_VERSION=18b97a82878e09fef24fe4f6a446eed01dbe28d7

RUN apk add --no-cache \
    wget ca-certificates build-base zlib-dev openssl-dev \
    pcre-dev libxml2-dev libxslt-dev linux-headers libaio-dev

RUN wget https://nginx.org/download/nginx-${NGINX_VERSION}.tar.gz -O nginx.tar.gz && \
    tar zxf nginx.tar.gz && \
    wget https://github.com/${NGINX_AWS_AUTH_REPO}/archive/${NGINX_AWS_AUTH_VERSION}.tar.gz -O aws.tar.gz && \
    tar zxf aws.tar.gz && \
    wget https://github.com/${NGINX_AKAMAI_TOKEN_VALIDATE_REPO}/archive/${NGINX_AKAMAI_TOKEN_VALIDATE_VERSION}.tar.gz -O natvm.tar.gz && \
    tar zxf natvm.tar.gz && \
    wget https://github.com/${NGINX_SECURE_TOKEN_REPO}/archive/${NGINX_SECURE_TOKEN_VERSION}.tar.gz -O nstm.tar.gz && \
    tar zxf nstm.tar.gz

ARG TARGETARCH
RUN if [ "$TARGETARCH" = "amd64" ]; then CC_OPT="-O3 -mpopcnt"; else CC_OPT="-O3"; fi && \
    cd nginx-${NGINX_VERSION} && \
    ./configure \
    --prefix=/usr/local/nginx \
    --add-module=../nginx-aws-auth-module-${NGINX_AWS_AUTH_VERSION} \
    --add-module=../nginx-akamai-token-validate-module-${NGINX_AKAMAI_TOKEN_VALIDATE_VERSION} \
    --add-module=../nginx-secure-token-module-${NGINX_SECURE_TOKEN_VERSION} \
    --conf-path=/usr/local/nginx/conf/nginx.conf \
    --with-file-aio \
    --with-threads \
    --with-http_ssl_module \
    --with-http_secure_link_module \
    --with-http_realip_module \
    --with-cc-opt="$CC_OPT" && \
    make -j"$(nproc)" && make install

FROM alpine:3.20 AS proxy-prod

RUN apk add --no-cache \
    ca-certificates \
    openssl \
    pcre \
    zlib \
    libxml2 \
    libxslt \
    ffmpeg \
    gettext \
    libaio

COPY --from=proxy-builder /usr/local/nginx /usr/local/nginx
RUN mkdir -p /var/cache/nginx/vod

# No cache sizing baked in: the entrypoint sizes the cache to the filesystem it is given.

COPY vod/nginx/nginx.conf.template /usr/local/nginx/conf/nginx.conf.template
COPY vod/nginx/cloudflare-realip.conf /usr/local/nginx/conf/cloudflare-realip.conf
COPY vod/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]


# --- Node base ---
FROM node:24-alpine AS node-base

ENV CI=true
ENV PNPM_HOME="/pnpm"
ENV PATH="$PNPM_HOME:$PATH"
RUN corepack enable && \
    mkdir -p /pnpm && chown node:node /pnpm

WORKDIR /app
RUN chown node:node /app

FROM node-base AS docs

USER node

EXPOSE 5173

CMD ["pnpm", "run", "docs:dev"]

# --- Front dev ---
FROM node-base AS front-dev

USER node

EXPOSE 5173

CMD ["sh", "-c", "pnpm install && pnpm run dev"]

# --- Front build ---
FROM node-base AS front-build

COPY pnpm-lock.yaml package.json ./

RUN --mount=type=cache,target=/pnpm/store,id=pnpm-store \
    pnpm install --frozen-lockfile

COPY . .
RUN pnpm run build

# --- Front prod ---
FROM nginx:stable-alpine AS front-prod

COPY --from=front-build /app/dist /usr/share/nginx/html

CMD ["nginx", "-g", "daemon off;"]