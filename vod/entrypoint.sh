#!/bin/sh
set -e

# ---- Upstreams ----
#
# The edge talks to two servers it is told about by URL: the bucket and the API. nginx wants each
# taken apart, and this does it the same way for both:
#
#   <NAME>_SCHEME   http or https
#   <NAME>_HOST     as written in the URL, port included, for the Host header
#   <NAME>_NAME     the host alone, which is what a TLS certificate names
#   <NAME>_SERVER   host:port for the `upstream`. Its `server` takes no scheme and defaults to
#                   port 80 whatever the scheme, so an https URL with no port would otherwise be
#                   dialled in TLS on port 80
#   <NAME>_RESOLVE  `resolve` when the host is a name, so nginx follows it when its addresses
#                   move; empty for an IP address, on which nginx refuses the flag
#
# A path after the host is dropped. A port is what follows the last colon, except inside an IPv6
# literal's brackets.
split_url() {
    scheme=$(echo "$2" | sed -n 's|^\([A-Za-z]*\)://.*|\1|p' | tr 'A-Z' 'a-z')
    scheme=${scheme:-$3}
    host=$(echo "$2" | sed 's|^[A-Za-z]*://||; s|/.*$||')

    case "$host" in
        \[*\]:*|[!\[]*:*) server=$host ;;
        *) [ "$scheme" = "https" ] && server="$host:443" || server="$host:80" ;;
    esac

    name=${server%:*}

    if echo "$name" | grep -qE '^[0-9.]+$|^\['; then
        resolve=""
    else
        resolve="resolve"
    fi

    export "$1_SCHEME=$scheme" "$1_HOST=$host" "$1_NAME=$name" "$1_SERVER=$server" "$1_RESOLVE=$resolve"
}

# Who answers those `resolve`s while nginx runs: the container's own nameserver (Docker's embedded
# DNS on the deploy's network). An IPv6 one needs brackets in `resolver`.
EDGE_RESOLVER=$(awk '/^nameserver/ { print $2; exit }' /etc/resolv.conf 2>/dev/null)
case "$EDGE_RESOLVER" in
    "") EDGE_RESOLVER=127.0.0.11 ;;
    *:*) EDGE_RESOLVER="[$EDGE_RESOLVER]" ;;
esac
export EDGE_RESOLVER

# The bucket (`s3_origin`).
split_url AWS_ENDPOINT "$AWS_ENDPOINT" https

# The API, which builds master manifests (`manifest_origin`): the internal URL and secret Vector
# ships the access log with. Without both, the edge serves manifests from the bucket as it always
# did — an edge run by hand, or deployed by a panel that predates this, keeps working. The secret
# is written into nginx config inside a quoted string, so one that could end the string or start a
# variable counts as missing rather than crashlooping the edge. `manifest_api` still has to name
# a server for nginx to load; unconfigured, it is a port nothing listens on and nothing routes to.
MANIFEST_API_URL="${INTERNAL_API_URL:-}"
export INTERNAL_API_SECRET="${INTERNAL_API_SECRET:-}"
case "$INTERNAL_API_SECRET" in
    *[!A-Za-z0-9._~+/=-]*) INTERNAL_API_SECRET="" ;;
esac

if [ -n "$MANIFEST_API_URL" ] && [ -n "$INTERNAL_API_SECRET" ]; then
    export MANIFEST_UPSTREAM=manifest_origin
    echo "manifest API: $MANIFEST_API_URL"
else
    export MANIFEST_UPSTREAM=edge_origin
    MANIFEST_API_URL=http://127.0.0.1:9
    echo "manifest API: not configured, serving manifests from the bucket"
fi

split_url MANIFEST_API "$MANIFEST_API_URL" http
# What follows the host, e.g. `/api/internal`: the manifest route hangs off it.
export MANIFEST_API_PATH=$(echo "$MANIFEST_API_URL" | sed 's|^[A-Za-z]*://[^/]*||; s|/*$||')

# The query argument carrying the token. Must match what the API signs with; the default is the
# Akamai name the signer also defaults to, so a node that predates this variable keeps working.
export VOD_TOKEN_NAME="${VOD_TOKEN_NAME:-__hdnea__}"

# Whether the edge verifies an https S3 endpoint's certificate. On unless a node says otherwise,
# for an endpoint with a private certificate. Anything but `off` means on: nginx refuses other
# values, and a typo here should neither crashloop the edge nor turn the check off.
if [ "${VOD_S3_TLS_VERIFY:-on}" = "off" ]; then
    export VOD_S3_TLS_VERIFY=off
else
    export VOD_S3_TLS_VERIFY=on
fi

# ---- Allowed domains ----
#
# The sites whose pages this edge serves (CDN Settings, comma-separated), as the body of the `map`
# that sets $origin_refused in the nginx config. A separate file rather than envsubst because the
# number of lines depends on the list. The map's key is "<the page's URL>|<this edge's host>".
#
# A request naming no page is $anonymous_refused's to decide: anything outside a browser passes,
# and so does a tab opened directly on the link. An Origin of `null` (a sandboxed iframe, a file://
# page) matches no line and is refused.
#
# Unless CDN Settings allows a null origin (VOD_ALLOW_NULL_ORIGIN=1), for an app that is a page on
# file://: a webOS or Tizen app, a WebView. Its player's requests then pass, whether the Origin
# reads `null` or `file://`, which is how a WebView allowed to read local files writes it; and so
# do its images, which name no page at all (see $anonymous_asset_refused). The Referer is not
# asked for a second opinion: a document with an opaque origin never sends one, and that is the
# page on file:// and the sandboxed iframe alike. Which is the price: any website can send `null`
# from a sandboxed iframe, so the list is left stopping only the sites that do not hide.
# Anything but `1` leaves it off, so a typo cannot open the edge. With no list there is nothing to
# exempt from, and the variable is not read.
#
# The edge's own host passes because a player may name the manifest it read as the Referer of the
# segments. The trailing `(/[^|]*)?\|` is what stops `example.com.evil.example`, and a Referer that
# merely mentions an allowed site in its path or query, from matching.
#
# An entry that is not a hostname is skipped rather than written: this file is nginx config, and
# nginx -t failing on it would crashloop the edge over one bad line. The API validates the list
# already, so this only guards against a hand-set variable. Skipping fails closed, since the
# `default 1` still refuses that domain.
ALLOWED_ORIGINS_CONF=/usr/local/nginx/conf/allowed-origins.conf
if [ -z "${VOD_ALLOWED_DOMAINS:-}" ]; then
    echo 'default 0;' > "$ALLOWED_ORIGINS_CONF"
else
    {
        echo 'default 1;'
        if [ "${VOD_ALLOW_NULL_ORIGIN:-}" = "1" ]; then
            echo '"~^\|" $anonymous_asset_refused;'
            echo '"~^(null|file://)\|" 0;'
        else
            echo '"~^\|" $anonymous_refused;'
        fi
        echo '"~*^https?://([^/:|]+)(:[0-9]+)?(/[^|]*)?\|\1$" 0;'
        echo "$VOD_ALLOWED_DOMAINS" | tr ',' '\n' | while IFS= read -r domain; do
            if ! echo "$domain" | grep -qE '^(\*\.)?[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$'; then
                echo "WARNING: ignoring VOD_ALLOWED_DOMAINS entry: $domain" >&2
                continue
            fi
            case "$domain" in
                \*.*) subdomains='([a-z0-9-]+\.)+' ;;
                *) subdomains='' ;;
            esac
            host=$(echo "${domain#\*.}" | sed 's/\./\\./g')
            echo "\"~*^https?://${subdomains}${host}(:[0-9]+)?(/[^|]*)?\\|\" 0;"
        done
    } > "$ALLOWED_ORIGINS_CONF"
fi

# ---- Cache sizing ----
#
# The cache is sized to the filesystem it sits on, not configured: a proxy node's deploy hands
# this container a pool of disks that exist for nothing else. Three numbers come out of `df`:
#
#  - max_size:  everything but a reserve. nginx's cache manager evicts by LRU to stay under it.
#  - min_free:  the reserve itself. max_size only counts what the manager knows about — it is
#               blind to misses still streaming into temp files (use_temp_path=off puts those in
#               the cache dir) and, for hours after a restart of a big cache, to everything the
#               loader has not indexed yet. min_free is measured with statfs, so it holds in both
#               windows. Running the filesystem out of space is the one thing that must not
#               happen: a failed write truncates the viewer's segment, and everyone waiting on
#               the same miss behind proxy_cache_lock gets the same truncated bytes.
#  - keys_zone: ~8k keys per MB (nginx docs). A segment is a few MB, so a 10 TB pool holds
#               millions of them, and the stock 20m would force-expire entries long before the
#               disk was used. Sized at 2 MB per object on average; a cache of smaller objects
#               simply fills the zone first and evicts from there, which is still LRU.
#
# VOD_CACHE_MAX_SIZE set is the exception: the deploy sets it when the cache has no pool and is a
# docker volume on the OS disk, which the edge must not be allowed to fill. Sizes use `g`/`m`:
# nginx does not parse a `t` suffix.
CACHE_DIR=/var/cache/nginx/vod

# The deploy that mounted a pool into CACHE_DIR leaves a marker at its root and says so
# with VOD_CACHE_EXPECT_POOL=1. The pool is mounted `nofail`, so a host can boot without it and
# the bind mount then hands this container the empty mount point directory on the OS disk — and
# sizing the cache to *that* filesystem would let the edge fill the OS disk. Missing marker with
# the flag set means exactly that: say so loudly and cap the cache at the fallback size instead.
# The marker is a hidden directory, not a file: nginx's cache loader deletes every file under
# the cache that is not a cache entry, so a marker file would be gone by the next restart.
# The cap mirrors App\Services\ProxyCacheService::FALLBACK_MAX_SIZE, what a host with no pool
# at all gets.
POOL_MARKER="$CACHE_DIR/.nukevideo-pool"
POOL_FALLBACK_MAX_SIZE=10g
if [ "${VOD_CACHE_EXPECT_POOL:-}" = "1" ] && [ ! -e "$POOL_MARKER" ]; then
    echo "WARNING: cache pool NOT mounted: $POOL_MARKER is missing, so $CACHE_DIR is the OS disk. Capping the cache at $POOL_FALLBACK_MAX_SIZE; mount the pool and restart the edge." >&2
    export VOD_CACHE_MAX_SIZE="$POOL_FALLBACK_MAX_SIZE"
fi
TOTAL_KB=$(df -Pk "$CACHE_DIR" | awk 'NR==2 { print $2 }')
TOTAL_MB=$((TOTAL_KB / 1024))

RESERVE_MB=$((TOTAL_MB * 3 / 100))
[ "$RESERVE_MB" -lt 20480 ] && RESERVE_MB=20480

if [ -z "$VOD_CACHE_MAX_SIZE" ]; then
    if [ "$TOTAL_MB" -gt $((RESERVE_MB * 2)) ]; then
        export VOD_CACHE_MAX_SIZE="$((TOTAL_MB - RESERVE_MB))m"
    else
        # A filesystem too small for the reserve to make sense: cap at half and keep the rest.
        export VOD_CACHE_MAX_SIZE="$((TOTAL_MB / 2))m"
        RESERVE_MB=$((TOTAL_MB / 4))
    fi
fi
export VOD_CACHE_MIN_FREE="${VOD_CACHE_MIN_FREE:-${RESERVE_MB}m}"

KEYS_MB=$((TOTAL_MB / 2 / 8000))
[ "$KEYS_MB" -lt 20 ] && KEYS_MB=20
export VOD_CACHE_KEYS_ZONE="${VOD_CACHE_KEYS_ZONE:-${KEYS_MB}m}"

# Not "30 days since it was last played" by accident: inactive is the only thing besides
# max_size that deletes, and a cache that is meant to hold the long tail must not be emptied by
# the calendar. Eviction is the LRU's job.
export VOD_CACHE_INACTIVE="${VOD_CACHE_INACTIVE:-30d}"

echo "cache: $CACHE_DIR ${TOTAL_MB}MB total, max_size=$VOD_CACHE_MAX_SIZE min_free=$VOD_CACHE_MIN_FREE keys_zone=$VOD_CACHE_KEYS_ZONE inactive=$VOD_CACHE_INACTIVE"

# The node id only identifies the edge to the health probe; an edge run by hand has none.
export NODE_ID="${NODE_ID:-0}"

FILTER_VARS='$NODE_ID $AWS_ACCESS_KEY_ID $AWS_SECRET_ACCESS_KEY $AWS_DEFAULT_REGION $AWS_ENDPOINT_SCHEME $AWS_ENDPOINT_HOST $AWS_ENDPOINT_SERVER $AWS_ENDPOINT_RESOLVE $EDGE_RESOLVER $AWS_BUCKET $VOD_CACHE_MAX_SIZE $VOD_CACHE_MIN_FREE $VOD_CACHE_KEYS_ZONE $VOD_CACHE_INACTIVE $VOD_TOKEN_SECRET $VOD_TOKEN_NAME $VOD_S3_TLS_VERIFY $MANIFEST_API_SCHEME $MANIFEST_API_HOST $MANIFEST_API_NAME $MANIFEST_API_SERVER $MANIFEST_API_RESOLVE $MANIFEST_API_PATH $MANIFEST_UPSTREAM $INTERNAL_API_SECRET'
envsubst "$FILTER_VARS" < /usr/local/nginx/conf/nginx.conf.template > /usr/local/nginx/conf/nginx.conf

/usr/local/nginx/sbin/nginx -t

exec /usr/local/nginx/sbin/nginx -g "daemon off;"
