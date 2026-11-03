#!/bin/sh
set -e

# The S3 endpoint, taken apart for the `s3_origin` upstream: a `server` there takes host:port
# with no scheme, and its default port is 80 whatever the scheme — so an https endpoint with no
# port would have been dialled in TLS on port 80. A trailing path (`https://s3.host/`) is dropped.
AWS_ENDPOINT_SCHEME=$(echo "$AWS_ENDPOINT" | sed -n 's|^\([A-Za-z]*\)://.*|\1|p' | tr 'A-Z' 'a-z')
export AWS_ENDPOINT_SCHEME="${AWS_ENDPOINT_SCHEME:-https}"
export AWS_ENDPOINT_HOST=$(echo "$AWS_ENDPOINT" | sed 's|^[A-Za-z]*://||; s|/.*$||')
# A port is what follows the last colon — except inside an IPv6 literal's brackets.
case "$AWS_ENDPOINT_HOST" in
    \[*\]:*|[!\[]*:*) export AWS_ENDPOINT_SERVER="$AWS_ENDPOINT_HOST" ;;
    *) [ "$AWS_ENDPOINT_SCHEME" = "https" ] && PORT=443 || PORT=80
       export AWS_ENDPOINT_SERVER="$AWS_ENDPOINT_HOST:$PORT" ;;
esac

# Who re-resolves the endpoint while nginx runs: the container's own nameserver (Docker's
# embedded DNS on the deploy's network). An IPv6 one needs brackets in `resolver`. An endpoint
# that is an IP address has nothing to re-resolve, and nginx refuses `resolve` on it.
EDGE_RESOLVER=$(awk '/^nameserver/ { print $2; exit }' /etc/resolv.conf 2>/dev/null)
case "$EDGE_RESOLVER" in
    "") EDGE_RESOLVER=127.0.0.11 ;;
    *:*) EDGE_RESOLVER="[$EDGE_RESOLVER]" ;;
esac
export EDGE_RESOLVER
ENDPOINT_NAME=${AWS_ENDPOINT_SERVER%:*}
if echo "$ENDPOINT_NAME" | grep -qE '^[0-9.]+$|^\['; then
    export AWS_ENDPOINT_RESOLVE=""
else
    export AWS_ENDPOINT_RESOLVE="resolve"
fi

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
        echo '"~^\|" $anonymous_refused;'
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

FILTER_VARS='$NODE_ID $AWS_ACCESS_KEY_ID $AWS_SECRET_ACCESS_KEY $AWS_DEFAULT_REGION $AWS_ENDPOINT_SCHEME $AWS_ENDPOINT_HOST $AWS_ENDPOINT_SERVER $AWS_ENDPOINT_RESOLVE $EDGE_RESOLVER $AWS_BUCKET $VOD_CACHE_MAX_SIZE $VOD_CACHE_MIN_FREE $VOD_CACHE_KEYS_ZONE $VOD_CACHE_INACTIVE $VOD_TOKEN_SECRET $VOD_TOKEN_NAME $VOD_S3_TLS_VERIFY'
envsubst "$FILTER_VARS" < /usr/local/nginx/conf/nginx.conf.template > /usr/local/nginx/conf/nginx.conf

/usr/local/nginx/sbin/nginx -t

exec /usr/local/nginx/sbin/nginx -g "daemon off;"
