<?php

/**
 * Which supervisors a node's Horizon starts, read straight from config/horizon.php under a given
 * environment. Set in $_SERVER, $_ENV and putenv alike: env() reads all three and the first one
 * that carries the variable wins, so a value the container itself was started with would
 * otherwise shadow the one under test.
 */
function horizonSupervisors(array $env): array
{
    $keys = ['NODE_TYPE', 'NODE_ACCEL', 'CHUNKS_ONLY', 'DISABLE_PACKAGING'];
    $saved = [];

    foreach ($keys as $key) {
        $saved[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null, getenv($key)];
        $value = $env[$key] ?? null;

        if ($value === null) {
            unset($_SERVER[$key], $_ENV[$key]);
            putenv($key);
        } else {
            $_SERVER[$key] = $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    try {
        return array_keys((require base_path('config/horizon.php'))['defaults']);
    } finally {
        foreach ($saved as $key => [$server, $envValue, $put]) {
            $server === null ? $_SERVER = array_diff_key($_SERVER, [$key => true]) : $_SERVER[$key] = $server;
            $envValue === null ? $_ENV = array_diff_key($_ENV, [$key => true]) : $_ENV[$key] = $envValue;
            $put === false ? putenv($key) : putenv("{$key}={$put}");
        }
    }
}

it('runs every supervisor on a plain worker', function () {
    expect(horizonSupervisors(['NODE_TYPE' => 'worker']))
        ->toEqualCanonicalizing(['video-worker', 'heavy-worker', 'orchestration-worker', 'packaging-worker']);
});

it('keeps packaging off with DISABLE_PACKAGING and orchestration on', function () {
    expect(horizonSupervisors(['NODE_TYPE' => 'worker', 'DISABLE_PACKAGING' => 'true']))
        ->toEqualCanonicalizing(['video-worker', 'heavy-worker', 'orchestration-worker']);
});

it('runs only the chunk encoders on a CHUNKS_ONLY worker', function () {
    // A node far from the chunk store must not take a preparation: it would mirror the whole
    // source to the store over its own uplink, and every sidecar pass would read it back.
    expect(horizonSupervisors(['NODE_TYPE' => 'worker', 'CHUNKS_ONLY' => 'true']))
        ->toEqualCanonicalizing(['video-worker', 'heavy-worker']);
});

it('runs only the GPU encoder on a CHUNKS_ONLY GPU worker', function () {
    expect(horizonSupervisors(['NODE_TYPE' => 'worker', 'NODE_ACCEL' => 'nvidia', 'CHUNKS_ONLY' => 'true']))
        ->toEqual(['gpu-worker']);
});

it('reads CHUNKS_ONLY=false as off', function () {
    expect(horizonSupervisors(['NODE_TYPE' => 'worker', 'CHUNKS_ONLY' => 'false']))
        ->toContain('orchestration-worker', 'packaging-worker');
});

it('ignores CHUNKS_ONLY on the API host', function () {
    expect(horizonSupervisors(['CHUNKS_ONLY' => 'true']))
        ->toEqualCanonicalizing(['supervisor-1', 'node-ops']);
});
