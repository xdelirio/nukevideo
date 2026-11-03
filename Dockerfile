ARG PHP_FPM_IMAGE=serversideup/php:8.5-fpm-nginx
ARG S5CMD_IMAGE=peakcom/s5cmd:v2.3.0
ARG SHAKA_PACKAGER_VERSION=v3.7.2

FROM ${S5CMD_IMAGE} AS s5cmd

FROM alpine:3.20 AS shaka-builder

ARG TARGETARCH
ARG SHAKA_PACKAGER_VERSION

RUN apk add --no-cache curl && \
    if [ "$TARGETARCH" = "arm64" ]; then SHAKA_ARCH="arm64"; else SHAKA_ARCH="x64"; fi && \
    curl -fSL "https://github.com/shaka-project/shaka-packager/releases/download/${SHAKA_PACKAGER_VERSION}/packager-linux-${SHAKA_ARCH}" \
      -o /usr/local/bin/packager && \
    chmod +x /usr/local/bin/packager

FROM alpine:3.20 AS ffmpeg-builder

ARG TARGETARCH
ARG FFMPEG_URL=""
# Rolling build; pin with --build-arg FFMPEG_BUILD_TAG=autobuild-… FFMPEG_BUILD_ID=N-…
ARG FFMPEG_BUILD_TAG=latest
ARG FFMPEG_BUILD_ID=master-latest

RUN apk add --no-cache curl tar xz && \
    if [ -z "$FFMPEG_URL" ]; then \
      if [ "$TARGETARCH" = "arm64" ]; then \
        FFMPEG_ARCH="linuxarm64"; \
      else \
        FFMPEG_ARCH="linux64"; \
      fi && \
      FFMPEG_URL="https://github.com/BtbN/FFmpeg-Builds/releases/download/${FFMPEG_BUILD_TAG}/ffmpeg-${FFMPEG_BUILD_ID}-${FFMPEG_ARCH}-gpl.tar.xz"; \
    fi && \
    curl -fSL "$FFMPEG_URL" -o /tmp/ffmpeg.tar.xz && \
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
ENV NGINX_AWS_AUTH_VERSION=1.1
# A commit, not the 1.1 tag: 1.1 (2017) parses no `ip` field, and the check that refuses a token
# from any other address than the one it names only exists on master (kaltura PR #22, 2021), which
# was never tagged. The panel's "bind to the viewer's IP" setting relies on it.
ENV NGINX_AKAMAI_TOKEN_VALIDATE_VERSION=818d94a712e6bbd32d9d086d2c96ccd98f79e438
ENV NGINX_SECURE_TOKEN_VERSION=1.5

RUN apk add --no-cache \
    wget ca-certificates build-base zlib-dev openssl-dev \
    pcre-dev libxml2-dev libxslt-dev linux-headers libaio-dev

RUN wget https://nginx.org/download/nginx-${NGINX_VERSION}.tar.gz -O nginx.tar.gz && \
    tar zxf nginx.tar.gz && \
    wget https://github.com/kaltura/nginx-aws-auth-module/archive/${NGINX_AWS_AUTH_VERSION}.tar.gz -O aws.tar.gz && \
    tar zxf aws.tar.gz && \
    wget https://github.com/kaltura/nginx-akamai-token-validate-module/archive/${NGINX_AKAMAI_TOKEN_VALIDATE_VERSION}.tar.gz -O natvm.tar.gz && \
    tar zxf natvm.tar.gz && \
    wget https://github.com/kaltura/nginx-secure-token-module/archive/${NGINX_SECURE_TOKEN_VERSION}.tar.gz -O nstm.tar.gz && \
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