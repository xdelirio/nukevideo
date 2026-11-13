# Vector only earns its keep on an edge: it ships the `ip=/bytes=/video=` lines the vod nginx
# writes into the bandwidth pipeline, and nothing else. On a worker the deploy removes it,
# including one a previous deploy left running. The API decides by leaving VECTOR_RUN_ARGS empty.

if [ -z "$VECTOR_RUN_ARGS" ]; then
    echo "=== Vector not needed on this node — removing any leftover ==="
    docker rm -f "$VECTOR_CONTAINER" 2>/dev/null || true
else
    echo "=== Writing Vector config ==="
    printf '%s' "$VECTOR_CONFIG" > "$WORKDIR/config/vector.yaml"

    echo "=== Deploying Vector ==="
    pull_image "$VECTOR_IMAGE"
    docker rm -f "$VECTOR_CONTAINER" 2>/dev/null || true
    run_container "$VECTOR_RUN_ARGS"
fi
