# A proxy node: the cache pool, the edge container, and Traefik in front of it in production.

echo "=== Proxy image ==="
pull_image "$IMAGE"

# The per-container tuning of Traefik and the edge (NodeService::EDGE_TUNING_FLAGS says what
# each flag is for), kept to what THIS host accepts. Any one of them can be refused — a kernel
# without BBR, an old one where a sysctl is not per namespace yet, a daemon whose hard limit is
# under the ulimit — and a refused flag makes `docker run` refuse the whole container: an edge
# or a Traefik that does not start, after the old one was already removed. So each is tried
# alone with the edge's own image first, and one the host refuses is left out and said so.
# The RUN_ARGS read EDGE_TUNING; Traefik's label carries its checksum, so a host whose answer
# changes gets a Traefik that matches.
echo "=== Container tuning ==="
$SUDO modprobe tcp_bbr 2>/dev/null || true
EDGE_TUNING=""
while IFS= read -r flag; do
    [ -n "$flag" ] || continue
    # shellcheck disable=SC2086 # the flag is two words on purpose
    if docker run --rm --entrypoint true $flag "$IMAGE" </dev/null >/dev/null 2>&1; then
        EDGE_TUNING="$EDGE_TUNING $flag"
        echo "  $flag"
    else
        echo "  $flag — refused by this host, left out"
    fi
done <<< "$EDGE_TUNING_CANDIDATES"
case "$EDGE_TUNING" in
    # Loaded again at boot, so a container restarted after a reboot still finds it.
    *congestion_control=bbr*) echo tcp_bbr | $SUDO tee /etc/modules-load.d/nukevideo-bbr.conf >/dev/null 2>&1 || true ;;
esac
EDGE_TUNING_ID=$(printf '%s' "$EDGE_TUNING" | cksum | cut -d' ' -f1)

# Where the edge caches: the pool's directory, or the fallback docker volume when the host has
# no pool — and only then is the cache capped, because a volume shares the OS disk. With a
# pool the entrypoint sizes the cache to it, and CACHE_EXPECT_POOL tells it a pool is what it
# was given, so a container restarted on a host that booted without the pool (`nofail`) caps
# itself instead of sizing the cache to the OS disk. All three are read by RUN_ARGS.
CACHE_MOUNT=""
CACHE_MAX_SIZE=""
CACHE_EXPECT_POOL=""
provision_cache_pool
if [ -z "$CACHE_MOUNT" ]; then
    CACHE_MOUNT=$CACHE_VOLUME
    CACHE_MAX_SIZE=$CACHE_FALLBACK_MAX_SIZE
    cache_remove_boot_recovery
else
    CACHE_EXPECT_POOL=1
    cache_tune_pool
    cache_install_boot_recovery
fi

# What the host needs for an edge whose cache lives on disk. Written to one file of ours, so
# it is replaced whole on every deploy and removed by deleting it. Applied before the
# containers are created: a new network namespace copies the host's TCP buffer sizes when it is
# made, so a container started earlier keeps the old ones.
#
#  - dirty_*_bytes: writers block at 10 % of RAM capped at 1 GB, and writeback starts at a
#    quarter of that, instead of at 20 and 10 % of RAM. Scaled down on a small host, where a
#    fixed 1 GB would be more than the default; capped on a big one, where 20 % of 128 GB is
#    25 GB of dirty pages. A cache filling from S3 otherwise piles them up and flushes
#    them in one burst, which an IOPS-capped disk serves by stalling every read behind it.
#  - vfs_cache_pressure 50: a pool holds millions of small files; keeping their inodes in RAM
#    saves a disk operation on every open of a segment the page cache no longer has.
#  - TCP buffers: a 10 s segment of a top rung is several MB sent as one burst, and the default
#    4 MB send ceiling caps a viewer 100 ms away at ~300 Mbit/s. Containers copy these when
#    their namespace is made; `notsent_lowat` they do not, so it is set per container
#    (NodeService::EDGE_TUNING_FLAGS).
#  - fq: the qdisc BBR is designed to pace through. Takes effect for qdiscs created after it is
#    set — the NIC's own at the next boot — and BBR paces internally until then.
echo "=== Host tuning ==="
MEM_BYTES=$(( $(awk '/^MemTotal:/ { print $2 }' /proc/meminfo) * 1024 ))
DIRTY_BYTES=$(( MEM_BYTES / 10 ))
[ "$DIRTY_BYTES" -gt 1073741824 ] && DIRTY_BYTES=1073741824
$SUDO tee /etc/sysctl.d/90-nukevideo-edge.conf >/dev/null <<SYSCTL
# Written by the nukevideo deploy (resources/deploy/proxy.sh); see the comment there.
vm.dirty_background_bytes = $(( DIRTY_BYTES / 4 ))
vm.dirty_bytes = $DIRTY_BYTES
vm.vfs_cache_pressure = 50
net.core.rmem_max = 67108864
net.core.wmem_max = 67108864
net.ipv4.tcp_rmem = 4096 131072 67108864
net.ipv4.tcp_wmem = 4096 65536 33554432
net.core.default_qdisc = fq
SYSCTL
# One bad line must not cost the rest, and none of them is worth failing a deploy over.
$SUDO sysctl -q -e -p /etc/sysctl.d/90-nukevideo-edge.conf || echo "WARNING: some host sysctls were not applied"

# A reverse proxy of the host's own on port 80 (a development machine's Traefik, say) routes to
# the edge by its labels, but only reaches it over a network both are on — and the edge is
# created on nukevideo_default alone, which that proxy has no reason to be in. It then picked the
# edge's address there and timed out on every request. Such a proxy is expected on one fixed
# network, HOST_PROXY_NETWORK (`traefik`, the one the getting-started guide creates), rather
# than whichever one the deploy could guess from it: the edge joins it, and a label names it,
# because with two networks a proxy otherwise picks either. Only in that case — our own
# Traefik shares nukevideo_default with the edge, and the label would send it elsewhere.
HOST_PROXY=$(docker ps --format '{{.Names}} {{.Ports}}' | grep -v '^nukevideo_traefik ' | grep ':80->' | awk '{ print $1; exit }')
EDGE_PROXY_NETWORK=""
# Our Traefik gets certificates from its `le` resolver, which the edge's router has to name: an
# entrypoint's default resolver never reaches a router that sets TLS itself. A host's own proxy
# keeps whatever it is configured with.
EDGE_CERT_RESOLVER=""
[ -z "$HOST_PROXY" ] && EDGE_CERT_RESOLVER=le
if [ -n "$HOST_PROXY" ]; then
    if docker network inspect "$HOST_PROXY_NETWORK" &>/dev/null; then
        EDGE_PROXY_NETWORK=$HOST_PROXY_NETWORK
    else
        echo "WARNING: $HOST_PROXY holds port 80 but there is no '$HOST_PROXY_NETWORK' network — create it, connect $HOST_PROXY to it and redeploy, or it will not reach the edge"
    fi
fi

echo "=== Deploying proxy ==="
# Stop before removing, so nginx finishes the requests it is serving instead of cutting them.
# The image is already pulled, so the gap is nginx's startup: a second or two, which players
# absorb with their buffer and segment retries.
docker stop "$SERVICE_CONTAINER" 2>/dev/null || true
docker rm -f "$SERVICE_CONTAINER" 2>/dev/null || true
run_container "$RUN_ARGS"
if [ -n "$EDGE_PROXY_NETWORK" ]; then
    # Not fatal: the edge is up either way, and the warning says what to connect by hand.
    if docker network connect "$EDGE_PROXY_NETWORK" "$SERVICE_CONTAINER"; then
        echo "Joined $EDGE_PROXY_NETWORK, for $HOST_PROXY, which holds port 80"
    else
        echo "WARNING: could not join $EDGE_PROXY_NETWORK — $HOST_PROXY may not reach the edge"
    fi
fi

# A proxy that does not answer /healthz fails the deploy — and with it a fleet deploy's chain,
# before the next proxy is touched. wget is busybox's, the image is alpine.
echo "Waiting for the edge to answer..."
n=0
until docker exec "$SERVICE_CONTAINER" wget -q -O /dev/null http://127.0.0.1/healthz 2>/dev/null; do
    n=$((n+1)); [ $n -ge 30 ] && { echo "Edge did not come up"; docker logs --tail 50 "$SERVICE_CONTAINER"; exit 1; }
    sleep 2
done
echo "Edge is up"

# Traefik fronts the edge unless something else already holds port 80 on this host — a reverse
# proxy of the operator's own, which then routes by the same labels. Ours is recognised by name
# and left alone when it already runs with exactly this configuration (the hash the API puts in
# its `nukevideo.config` label): replacing it drops TLS on the host for a few seconds, the
# longest gap of any proxy deploy.
echo "=== Traefik ==="
if [ -n "$HOST_PROXY" ]; then
    echo "Port 80 is held by $HOST_PROXY — leaving the host's own reverse proxy in front"
elif [ "$(docker inspect -f '{{.State.Running}} {{index .Config.Labels "nukevideo.config"}}' nukevideo_traefik 2>/dev/null)" = "true $TRAEFIK_CONFIG-$EDGE_TUNING_ID" ]; then
    echo "Traefik already running this configuration — kept"
else
    pull_image "$TRAEFIK_IMAGE"
    docker rm -f nukevideo_traefik 2>/dev/null || true
    run_container "$TRAEFIK_RUN_ARGS"
    echo "Traefik deployed"
fi
