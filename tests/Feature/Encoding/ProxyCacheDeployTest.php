<?php

use App\Models\Node;
use App\Models\User;
use App\Services\NodeService;
use App\Services\ProxyCacheService;
use App\Services\SSHService;
use App\Settings\AppSettings;
use App\Settings\CdnSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

function cacheNode(array $attributes = []): Node
{
    // The panel's one key, as any SSH-backed call reads it ({@see \App\Services\SshKeyService}).
    AppSettings::fake(['ssh_private_key' => 'PRIVATE', 'ssh_public_key' => 'ssh-ed25519 AAAA nukevideo', 'ssh_fingerprint' => 'ff']);

    return Node::create([
        'ip_address' => '10.0.0.99',
        'user' => 'deploy',
        'name' => 'edge-test',
        'type' => 'proxy',
        'hostname' => 'edge.example.com',
        'is_active' => true,
        ...$attributes,
    ]);
}

/**
 * Runs the deploy's own `cache_select_disks` against an inventory, the way the node would: the
 * selection is pure string work, so it can be executed here rather than read for keywords.
 *
 * @param  array<string, string>  $inventory  device => state
 * @param  array<string, string>  $chosen  CHOSEN_DISKS / CHOSEN_SHARED, when the panel sent them
 * @return array{ok: bool, output: string, format: array<int, string>, share: array<int, string>}
 */
function selectDisks(array $inventory, array $chosen = []): array
{
    $lines = collect($inventory)->map(fn (string $state, string $device) => "{$device}\t1000\tDISK\t{$state}\t\t0")->implode("\n");
    $script = 'SUDO=""; . "$SCRIPT"; POOL_DISKS=$(echo "$INVENTORY" | awk -F"\t" \'$4 == "nukevideo" { print $1 }\');'
        .' cache_select_disks; echo "FORMAT=$(echo $FREE_DISKS)"; echo "SHARE=$(echo $SHARED_DISKS)"';

    $result = Process::env(['SCRIPT' => resource_path('deploy/cache-disks.sh'), 'INVENTORY' => $lines, ...$chosen])
        ->run(['bash', '-c', $script]);
    preg_match('/^FORMAT=(.*)$/m', $result->output(), $format);
    preg_match('/^SHARE=(.*)$/m', $result->output(), $share);

    return [
        'ok' => $result->successful(),
        'output' => $result->output(),
        'format' => array_values(array_filter(explode(' ', $format[1] ?? ''))),
        'share' => array_values(array_filter(explode(' ', $share[1] ?? ''))),
    ];
}

/** Production as deployed: served over HTTPS, which is what puts TLS on the edge ({@see Node::proxyScheme}). */
function inProduction(): void
{
    app()->detectEnvironment(fn () => 'production');
    config(['app.url' => 'https://api.example.com']);
}

/** A development panel on plain HTTP — pinned, so the suite never follows the developer's own .env. */
function inDevelopment(): void
{
    app()->detectEnvironment(fn () => 'local');
    config(['app.url' => 'http://api.nukevideo.localhost']);
    // A development deploy pulls `node-dev` from a registry, and is refused without one.
    config(['nuke.registry' => '10.0.0.240:5000']);
}

beforeEach(function () {
    config(['nuke.registry' => null]);
    CdnSettings::fake([
        'provider' => 'self_hosted',
        'providers' => [
            'self_hosted' => ['token_secret' => 'secret'],
            'bunny' => ['host' => 'cdn.example.com', 'token_key' => 'key'],
        ],
    ]);
});

describe('a production proxy deploy', function () {
    beforeEach(fn () => inProduction());

    it('provisions the spare disks into a pool and mounts it into the edge', function () {
        $node = cacheNode();
        $script = app(NodeService::class)->buildDeployScript($node);

        expect($script)->toContain('cache_disk_inventory()')
            ->and($script)->toContain('provision_cache_pool')
            ->and($script)->toContain("CACHE_DIRECTORY='".ProxyCacheService::MOUNT."/nukevideo_proxy_{$node->id}'")
            ->and($script)->toContain('-v "$CACHE_MOUNT:'.ProxyCacheService::CONTAINER_PATH.'"');
    });

    it('stops the old edge gracefully and fails the deploy when the new one never answers', function () {
        // A proxy that does not come up must fail its operation, or a fleet deploy's chain would
        // move on and take the next edge down with the same broken image.
        $script = app(NodeService::class)->buildDeployScript(cacheNode(), []);

        // SIGTERM is nginx's fast shutdown, which drops open connections: only SIGQUIT drains.
        expect($script)->toContain('--stop-signal SIGQUIT')
            ->and($script)->toContain('docker stop "$SERVICE_CONTAINER"')
            ->and($script)->toContain('http://127.0.0.1/healthz')
            ->and($script)->toContain('Edge did not come up');
    });

    it('leaves Traefik alone only when it already runs exactly what it would be replaced with', function () {
        // Replacing it drops TLS for every site on the host: the longest gap of a proxy deploy.
        // But the image alone is not enough: a development panel's Traefik has no TLS, runs under
        // the same name, and a production deploy that kept it would serve no HTTPS at all.
        $node = cacheNode();
        preg_match("/^TRAEFIK_CONFIG='([0-9a-f]+)'$/m", app(NodeService::class)->buildDeployScript($node, []), $production);
        inDevelopment();
        preg_match("/^TRAEFIK_CONFIG='([0-9a-f]+)'$/m", $script = app(NodeService::class)->buildDeployScript($node, []), $development);

        expect($production[1] ?? null)->not->toBeNull()
            ->and($development[1] ?? null)->not->toBe($production[1] ?? null)
            ->and($script)->toContain("nukevideo.config={$development[1]}")
            // The node appends the checksum of the tuning it found the host accepts: a Traefik
            // created without BBR must not be kept once the kernel has it.
            ->and($script)->toContain('= "true $TRAEFIK_CONFIG-$EDGE_TUNING_ID"');
    });

    it('keeps the shell and the application agreeing on the pool\'s names', function () {
        // The constants live twice: in cache-disks.sh, which does the work, and in PHP, which
        // validates the pool and builds the directory. A drift between them is a pool the panel
        // cannot find.
        $shell = NodeService::deployScript('cache-disks');

        expect($shell)->toContain('CACHE_POOL_MOUNT='.ProxyCacheService::MOUNT)
            ->and($shell)->toContain('CACHE_POOL_LABEL='.ProxyCacheService::FS_LABEL)
            ->and($shell)->toContain('CACHE_POOL_MD='.ProxyCacheService::MD_DEVICE)
            ->and($shell)->toContain('CACHE_POOL_NAME=${CACHE_POOL_MD##*/}');
    });

    it('labels the pool with something XFS accepts', function () {
        // mkfs.xfs refuses a label over twelve characters, and the array's name was once used
        // for both: every deploy that had a disk to format died on that line.
        expect(strlen(ProxyCacheService::FS_LABEL))->toBeLessThanOrEqual(12);
    });

    it('keeps an existing pool rather than rebuilding it', function () {
        // A redeploy is how a node is updated. Formatting on every one would throw the cache away
        // each time, so members already carrying the label are mounted as they are.
        $script = app(NodeService::class)->buildDeployScript(cacheNode());

        expect($script)->toContain('$4 == "nukevideo"')
            ->and($script)->toContain('Existing cache pool found — keeping it');
    });

    it('falls back to a capped docker volume when the host has no spare disk', function () {
        $node = cacheNode();
        $script = app(NodeService::class)->buildDeployScript($node);

        expect($script)->toContain("CACHE_VOLUME='nukevideo_proxy_cache_{$node->id}'")
            ->and($script)->toContain("CACHE_FALLBACK_MAX_SIZE='".ProxyCacheService::FALLBACK_MAX_SIZE."'")
            ->and($script)->toContain('${CACHE_MAX_SIZE:+-e "VOD_CACHE_MAX_SIZE=$CACHE_MAX_SIZE"}');
    });

    it('no longer hands the edge a cache size from the settings', function () {
        // The edge sizes its cache to the pool on boot. A size injected from CdnSettings — 10g by
        // default — would silently cap a 10 TB pool at that.
        $script = app(NodeService::class)->buildDeployScript(cacheNode());

        expect($script)->not->toContain("'VOD_CACHE_MAX_SIZE=")
            ->and($script)->not->toContain('VOD_CACHE_INACTIVE=');
    });

    it('leaves a worker\'s disks alone', function () {
        $node = cacheNode(['type' => 'worker']);
        $script = app(NodeService::class)->buildDeployScript($node);

        expect($script)->not->toContain('cache_disk_inventory')
            ->and($script)->not->toContain('mdadm');
    });

    it('caps every container\'s log, the edge\'s tighter than the rest', function () {
        // With the cache surviving redeploys, the edge's per-segment access log was the one file
        // on the OS disk that nothing ever truncated. The edge's log also carries the raw playback
        // token of every request, replayable until it expires, so it keeps a much shorter window.
        $script = app(NodeService::class)->buildDeployScript(cacheNode());

        expect(substr_count($script, '--log-opt max-size=100m --log-opt max-file=5'))
            ->toBe(preg_match_all('/^[A-Z_]*RUN_ARGS=\'--name/m', $script) - 1)
            ->and($script)->toMatch('/^RUN_ARGS=\'--name nukevideo_proxy_\d+ --restart unless-stopped --log-opt max-size=20m --log-opt max-file=3 /m');
    });

    it('tells the edge to expect the pool, so a boot without it caps the cache', function () {
        $script = app(NodeService::class)->buildDeployScript(cacheNode());

        expect($script)->toContain('${CACHE_EXPECT_POOL:+-e "VOD_CACHE_EXPECT_POOL=$CACHE_EXPECT_POOL"}')
            ->and($script)->toContain('CACHE_EXPECT_POOL=1')
            // A directory: nginx's cache loader deletes any file under the cache that is not
            // an entry, and the next restart then took a mounted pool for the OS disk.
            ->and($script)->toContain('mkdir -p "$CACHE_DIRECTORY/$CACHE_POOL_MARKER"')
            ->and($script)->not->toContain('touch "$CACHE_DIRECTORY/$CACHE_POOL_MARKER"');
    });

    it('checks for sudo before anything else runs on the node', function () {
        $script = app(NodeService::class)->buildDeployScript(cacheNode());

        expect(strpos($script, 'cannot sudo without a password'))->toBeLessThan(strpos($script, 'set -e'));
    });

    it('installs every tool through the one helper, which fetches the package lists first', function () {
        $script = app(NodeService::class)->buildDeployScript(cacheNode());

        // Defined before the first section that calls it.
        expect(strpos($script, 'ensure_commands()'))->toBeLessThan(strpos($script, 'ensure_commands "curl=curl ca-certificates"'))
            ->and($script)->toContain('ensure_commands mdadm=mdadm mkfs.xfs=xfsprogs')
            ->and($script)->toContain('ensure_commands sfdisk=fdisk ||');

        // An install of its own, against lists nobody refreshed, is how a first deploy went on
        // without sfdisk.
        foreach (['common', 'cache-disks', 'proxy', 'worker', 'vector'] as $section) {
            expect(NodeService::deployScript($section))->not->toContain('apt-get install -y -qq fdisk')
                ->and(NodeService::deployScript($section))->not->toContain('apt-get install -y -qq mdadm');
        }

        $packages = NodeService::deployScript('packages');
        expect(strpos($packages, ' update -qq'))->toBeLessThan(strpos($packages, 'install -y -qq'));
    });
});

describe('a development proxy deploy', function () {
    beforeEach(fn () => inDevelopment());

    it('lists the disks but ticks none of them', function () {
        $node = cacheNode();
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('run')->once()->andReturn("/dev/sdb\t4000000000000\tDISK\tempty\t\t0\t0");
        app()->instance(SSHService::class, $ssh);
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $this->getJson("/api/nodes/{$node->id}/cache-disks")
            ->assertOk()
            ->assertJsonPath('data.preselect', false)
            ->assertJsonCount(1, 'data.disks');
    });

    it('treats a deploy without a list as "format nothing"', function () {
        $node = cacheNode();
        $captured = '';
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('run')->once()->andReturnUsing(function (...$args) use (&$captured) {
            $captured = $args['input'] ?? $args[5] ?? $captured;   // named when mocked directly, positional otherwise

            return '';
        });
        app()->instance(SSHService::class, $ssh);
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $this->postJson("/api/nodes/{$node->id}/deploy")->assertStatus(202);
        expect(Activity::inLog('node')->latest('id')->first()->properties['status'])->toBe('succeeded');

        expect($captured)->toContain("CHOSEN_DISKS=''")
            ->and($captured)->toContain("CACHE_VOLUME='nukevideo_dev_proxy_cache_{$node->id}'");
    });
});

describe('the disk inventory', function () {
    it('ticks the spare disks for a production panel', function () {
        inProduction();
        $node = cacheNode();
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('run')->once()->andReturn("/dev/sdb\t4000000000000\tDISK\tempty\t\t0");
        app()->instance(SSHService::class, $ssh);
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $this->getJson("/api/nodes/{$node->id}/cache-disks")->assertOk()->assertJsonPath('data.preselect', true);
    });

    it('installs sfdisk before listing, so a first setup is offered its system disks', function () {
        // Nothing has been deployed on a host the first time its disks are listed, so nothing
        // has installed the tool that finds a system disk's unpartitioned space either.
        inProduction();
        $input = '';
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('run')->once()->andReturnUsing(function (...$args) use (&$input) {
            $input = $args['input'] ?? $args[5];

            return '';
        });
        app()->instance(SSHService::class, $ssh);

        $node = cacheNode();
        app(ProxyCacheService::class)->inventory($node);

        // Off stdout, which is the listing; and never able to fail it.
        expect($input)->toContain('ensure_commands sfdisk=fdisk >&2 || true')
            ->and(strpos($input, 'ensure_commands()'))->toBeLessThan(strpos($input, 'ensure_commands sfdisk=fdisk >&2'))
            ->and(strpos($input, 'cannot sudo without a password'))->toBeLessThan(strpos($input, 'ensure_commands()'));
    });

    it('is written by the script in the seven fields the parser reads', function () {
        // The two sides only meet on a node. A field added to one and not the other drops every
        // disk from the listing, and the panel then has nothing to show.
        $shell = NodeService::deployScript('cache-disks');

        expect($shell)->toContain("printf '%s\\t%s\\t%s\\t%s\\t%s\\t%s\\t%s\\n' \"\$dev\"");
    });

    it('parses what the host reports, one disk per line', function () {
        inProduction();
        $node = cacheNode();

        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('run')->once()->andReturn(implode("\n", [
            "/dev/nvme0n1\t512110190592\tSAMSUNG MZVL2512\tsystem\tmounted at /\t0\t1",
            "/dev/sda\t10000831348736\tST10000NM0016\tempty\t\t0\t0",
            "/dev/sdb\t10000831348736\tST10000NM0016\tforeign\text4 \"backup\"\t0\t0",
            "/dev/sdc\t10000831348736\tST10000NM0016\tshared\tmounted at /, with unpartitioned space\t9946070941696\t0",
            'not a disk line',
        ]));
        app()->instance(SSHService::class, $ssh);

        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $this->getJson("/api/nodes/{$node->id}/cache-disks")
            ->assertOk()
            ->assertJsonCount(4, 'data.disks')
            ->assertJsonPath('data.disks.0.state', 'system')
            ->assertJsonPath('data.disks.1.size', 10000831348736)
            ->assertJsonPath('data.disks.1.free', 0)
            ->assertJsonPath('data.disks.2.detail', 'ext4 "backup"')
            ->assertJsonPath('data.disks.3.state', 'shared')
            ->assertJsonPath('data.disks.3.free', 9946070941696)
            ->assertJsonPath('data.disks.0.fast', true)
            ->assertJsonPath('data.disks.1.fast', false)
            ->assertJsonPath('data.tieredPool', false);
    });

    it('says whether the existing pool has a fast tier new SSD/NVMe space can join', function () {
        inProduction();
        $node = cacheNode();
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $listing = fn (string $lines) => tap(Mockery::mock(SSHService::class), fn ($ssh) => $ssh->shouldReceive('run')->andReturn($lines));

        // nv-cdn-3 after its root was shrunk: a tiered pool, and the system SSD's free space.
        app()->instance(SSHService::class, $listing(implode("\n", [
            "/dev/sda\t960197124096\tSAMSUNG MZ7L3960\tshared\tmounted at /boot, with unpartitioned space\t843000000000\t1",
            "/dev/sdb\t8001563222016\tHGST HUS728T8TAL\tnukevideo\tnukevideo cache (raid member)\t0\t0",
            "/dev/sdc\t960197124096\tSAMSUNG MZ7L3960\tnukevideo\tnukevideo cache (tier)\t0\t1",
        ])));
        $this->getJson("/api/nodes/{$node->id}/cache-disks")->assertOk()->assertJsonPath('data.tieredPool', true);

        // The inventory itself, run over the layers nv-cdn-3 really has, not lines written to
        // match the parser: every disk of that pool ends in the same XFS, which used to overwrite
        // the fast tier's "(tier)" — the panel then saw no tier, and offered nothing.
        $stubs = <<<'SH'
            lsblk() {
                case "$*" in
                    *-dn*)
                        [[ " $DISKS " == *" sdb "* ]] && printf 'NAME="sdb" TYPE="disk" RM="0" SIZE="8001563222016" MODEL="HGST"\n'
                        [[ " $DISKS " == *" sdc "* ]] && printf 'NAME="sdc" TYPE="disk" RM="0" SIZE="960197124096" MODEL="SAMSUNG"\n'
                        return 0 ;;
                    *" /dev/sdb") printf 'KNAME="/dev/sdb" MOUNTPOINT=""\nKNAME="/dev/md127" MOUNTPOINT=""\nKNAME="/dev/dm-3" MOUNTPOINT=""\nKNAME="/dev/dm-5" MOUNTPOINT="/var/lib/nukevideo/cache"\n' ;;
                    *" /dev/sdc") printf 'KNAME="/dev/sdc" MOUNTPOINT=""\nKNAME="/dev/dm-2" MOUNTPOINT=""\nKNAME="/dev/dm-5" MOUNTPOINT="/var/lib/nukevideo/cache"\n' ;;
                esac
            }
            blkid() {
                local dev=${!#}
                case "$*" in
                    *"-s TYPE"*) case "$dev" in /dev/sdb) echo linux_raid_member ;; /dev/md127|/dev/sdc) echo LVM2_member ;; /dev/dm-5) echo xfs ;; *) return 2 ;; esac ;;
                    *"-s LABEL"*) case "$dev" in /dev/sdb) echo nv-cdn-3:nukevideo-cache ;; /dev/dm-5) echo nukevideo ;; *) return 2 ;; esac ;;
                    *) return 2 ;;
                esac
            }
            pvs() { echo "  nukevideo_edge"; }
            SH;
        $inventory = function (string $disks) use ($stubs) {
            $result = Process::env(['SCRIPT' => resource_path('deploy/cache-disks.sh'), 'DISKS' => $disks])
                ->run(['bash', '-c', 'SUDO=""; . "$SCRIPT"; '.$stubs.'; cache_disk_inventory']);
            expect($result->successful())->toBeTrue($result->errorOutput());

            return $result->output();
        };

        $both = $inventory('sdb sdc');
        expect($both)->toContain("/dev/sdb\t8001563222016\tHGST\tnukevideo\tnukevideo cache (raid member, tier)\t")
            ->and($both)->toContain("/dev/sdc\t960197124096\tSAMSUNG\tnukevideo\tnukevideo cache (tier)\t");
        app()->instance(SSHService::class, $listing($both));
        $this->getJson("/api/nodes/{$node->id}/cache-disks")->assertOk()->assertJsonPath('data.tieredPool', true);

        // The same pool once its fast tier is gone — a dead SSD dropped, or a grow cut short
        // after the old SSD left the group: only the spinning disks are left to say the pool
        // has tiers, and the panel must still offer SSD space to give it one back.
        $spinningOnly = $inventory('sdb');
        expect($spinningOnly)->toContain("\tnukevideo cache (raid member, tier)\t");
        app()->instance(SSHService::class, $listing($spinningOnly));
        $this->getJson("/api/nodes/{$node->id}/cache-disks")->assertOk()->assertJsonPath('data.tieredPool', true);

        // One RAID 0, no fast tier: nothing can join it.
        app()->instance(SSHService::class, $listing("/dev/sdb\t8001563222016\tHGST\tnukevideo\tnukevideo cache (raid member)\t0\t0"));
        $this->getJson("/api/nodes/{$node->id}/cache-disks")->assertOk()->assertJsonPath('data.tieredPool', false);
    });
});

/**
 * Runs the deploy's own `cache_drop_unreadable_cache` against a faked LVM and device-mapper: the
 * volume's segment type, cache mode and attributes live in files the stubs read and write, so
 * what an activation or an uncache changes is seen by the next command, as on a node.
 *
 * @param  array<string, string>  $env  MODE (writethrough|writeback), ATTR (the volume's
 *                                      attributes to start with), ACTIVATE_ON (the lvchange
 *                                      attempt that succeeds, if any)
 * @return array{exit: int, output: string, log: string}
 */
function dropUnreadableCache(array $env): array
{
    $script = <<<'SH'
        SUDO=""; . "$SCRIPT"
        T=$(mktemp -d)
        echo cache > "$T/seg"; echo "$MODE" > "$T/mode"; echo "$ATTR" > "$T/attr"; : > "$T/log"
        lvs() { case "$*" in *segtype*) echo "  $(cat "$T/seg")" ;; *cache_mode*) echo "  $(cat "$T/mode")" ;; *lv_attr*) echo "  $(cat "$T/attr")" ;; esac; }
        lvchange() {
            local n=$(( $(cat "$T/tries" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$T/tries"; echo "lvchange" >> "$T/log"
            if [ "$n" -ge "${ACTIVATE_ON:-99}" ]; then echo "Cwi-a-C---" > "$T/attr"; return 0; fi
            echo "  Activation of logical volume nukevideo_edge/data is prohibited while logical volume nukevideo_edge/fast_cvol is active." >&2
            return 5
        }
        dmsetup() {
            case "$*" in
                *"-o name"*) printf 'nukevideo_edge-data_corig\nnukevideo_edge--x-other\nvg0-root\n' ;;
                *"-o open"*) echo "    0" ;;
                remove*) echo "remove ${*: -1}" >> "$T/log" ;;
            esac
        }
        lvconvert() { echo "lvconvert $*" >> "$T/log"; echo linear > "$T/seg"; }
        vgs() { echo "  ${MISSING:-0}"; }
        udevadm() { :; }
        cache_has_dm_cache() { return 0; }
        ( cache_drop_unreadable_cache ); echo "EXIT=$?"
        echo "--- log"; cat "$T/log"; rm -rf "$T"
        SH;

    $result = Process::env(['SCRIPT' => resource_path('deploy/cache-disks.sh'), 'ATTR' => 'Cwi---C---', ...$env])
        ->run(['bash', '-c', $script]);
    preg_match('/^EXIT=(\d+)$/m', $result->output(), $exit);
    [$output, $log] = array_pad(explode("--- log\n", $result->output(), 2), 2, '');

    return ['exit' => (int) ($exit[1] ?? -1), 'output' => $output, 'log' => $log];
}

describe('a cache that will not activate', function () {
    // An unclean shutdown left a node's fast tier failing LVM's metadata check: the pool would
    // not activate at boot, and the node served two days off the OS disk.

    it('drops a writethrough cache, clearing only this group\'s leftovers first', function () {
        $run = dropUnreadableCache(['MODE' => 'writethrough']);

        expect($run['exit'])->toBe(0)
            ->and($run['log'])->toContain('remove nukevideo_edge-data_corig')
            // Another group whose name merely starts the same, and the OS's own volumes.
            ->and($run['log'])->not->toContain('nukevideo_edge--x-other')
            ->and($run['log'])->not->toContain('vg0-root')
            ->and($run['log'])->toContain('--uncache')
            // What LVM said is what the operator reads in the deploy log.
            ->and($run['output'])->toContain('is prohibited while logical volume nukevideo_edge/fast_cvol is active');
    });

    it('keeps the cache when releasing the leftovers is all it took', function () {
        $run = dropUnreadableCache(['MODE' => 'writethrough', 'ACTIVATE_ON' => '2']);

        expect($run['exit'])->toBe(0)
            ->and($run['log'])->toContain('remove nukevideo_edge-data_corig')
            ->and($run['log'])->not->toContain('lvconvert')
            ->and($run['output'])->toContain('the cache is kept');
    });

    it('drops nothing while the group is missing a disk: a late spinning disk is not a bad cache', function () {
        $run = dropUnreadableCache(['MODE' => 'writethrough', 'MISSING' => '1']);

        expect($run['exit'])->toBe(1)
            ->and($run['log'])->not->toContain('lvconvert')
            ->and($run['log'])->not->toContain('remove');
    });

    it('leaves a writeback cache alone: it may hold the only copy of recent writes', function () {
        $run = dropUnreadableCache(['MODE' => 'writeback']);

        expect($run['exit'])->toBe(1)
            ->and($run['log'])->not->toContain('lvconvert')
            ->and($run['log'])->not->toContain('remove');
    });

    it('never touches a volume LVM already reports active, /dev links or not', function () {
        // Read off /dev, a symlink udev had not written yet would pass for a volume that will
        // not activate, and a healthy pool would lose its warm cache.
        $run = dropUnreadableCache(['MODE' => 'writethrough', 'ATTR' => 'Cwi-aoC---']);

        expect($run['exit'])->toBe(0)->and(trim($run['log']))->toBe('');
    });

    it('runs between repairing the tiers and building them', function () {
        $script = NodeService::deployScript('cache-disks');
        $assemble = substr($script, strpos($script, 'cache_assemble_pool() {'));

        expect(strpos($assemble, 'cache_repair_tiers'))->toBeLessThan(strpos($assemble, 'cache_drop_unreadable_cache'))
            ->and(strpos($assemble, 'cache_drop_unreadable_cache'))->toBeLessThan(strpos($assemble, 'cache_build_tiers'));
    });

    it('replaces a marker file an older deploy left with a directory nginx will not delete', function () {
        $dir = sys_get_temp_dir().'/marker-'.uniqid();
        mkdir($dir);
        touch("{$dir}/.nukevideo-pool");

        $script = 'SUDO=""; . "$SCRIPT"; CACHE_DIRECTORY="$DIR"; mountpoint() { return 0; }; tee() { cat >/dev/null; };'
            .' df() { echo "Filesystem Size Used Avail Use% Mounted"; echo "dev 15T 1T 14T 7% /x"; };'
            .' cache_mount_pool && cache_mount_pool';
        $result = Process::env(['SCRIPT' => resource_path('deploy/cache-disks.sh'), 'DIR' => $dir])->run(['bash', '-c', $script]);

        expect($result->successful())->toBeTrue($result->errorOutput())
            ->and(is_dir("{$dir}/.nukevideo-pool"))->toBeTrue();
        rmdir("{$dir}/.nukevideo-pool");
        rmdir($dir);
    });
});

describe('the pool at boot', function () {
    // LVM gave up on a pool whose cache failed its metadata check after an unclean shutdown,
    // and the node served two days off the OS disk with nothing to bring the pool back.

    it('is brought up by a unit ordered before docker, installed whenever there is a pool', function () {
        $proxy = NodeService::deployScript('proxy');
        $pool = substr($proxy, strpos($proxy, 'CACHE_EXPECT_POOL=1'), 200);

        expect($pool)->toContain('cache_install_boot_recovery');
    });

    it('writes a boot script from the deploy\'s own functions that cannot format or create anything', function () {
        $dir = sys_get_temp_dir().'/boot-'.uniqid();
        mkdir($dir);
        $script = 'SUDO=""; . "$SCRIPT"; systemctl() { echo "systemctl $*" >> "$DIR/systemctl"; };'
            .' CACHE_BOOT_SCRIPT="$DIR/cache-boot.sh"; CACHE_BOOT_UNIT="$DIR/unit"; cache_install_boot_recovery';
        $result = Process::env(['SCRIPT' => resource_path('deploy/cache-disks.sh'), 'DIR' => $dir])->run(['bash', '-c', $script]);

        $boot = (string) @file_get_contents("{$dir}/cache-boot.sh");
        $unit = (string) @file_get_contents("{$dir}/unit");
        $systemctl = (string) @file_get_contents("{$dir}/systemctl");
        $parses = Process::run(['bash', '-n', "{$dir}/cache-boot.sh"]);
        array_map('unlink', glob("{$dir}/*"));
        rmdir($dir);

        expect($result->successful())->toBeTrue($result->errorOutput())
            ->and($parses->successful())->toBeTrue($parses->errorOutput())
            ->and($boot)->toContain('cache_drop_unreadable_cache ()')
            ->and($boot)->toContain('cache_repair_tiers ()')
            ->and(rtrim($boot))->toEndWith('cache_boot_recover')
            // At boot a disk that is slow can read as blank: nothing there may write a disk.
            ->and($boot)->not->toMatch('/mkfs|cache_format|wipefs|mdadm --create|lvcreate|cache_build_tiers/')
            ->and($unit)->toContain('Before=docker.service')
            ->and($unit)->toContain("ExecStart=/bin/bash {$dir}/cache-boot.sh")
            ->and($systemctl)->toContain('enable nukevideo-cache-boot.service');
    });
});

describe('traefik in front of the edge', function () {
    it('is deployed from a development panel too, without TLS', function () {
        // A development panel deploys to dedicated servers as well as to the developer's own
        // machine; the node decides at run time whether port 80 is already taken.
        inDevelopment();
        $script = app(NodeService::class)->buildDeployScript(cacheNode());

        expect($script)->toContain("TRAEFIK_RUN_ARGS='--name nukevideo_traefik")
            ->and($script)->not->toContain('certificatesresolvers')
            ->and($script)->toContain("grep ':80->'")->and($script)->toContain('if [ -n "$HOST_PROXY" ]; then');
    });

    it('terminates TLS in production', function () {
        inProduction();

        expect(app(NodeService::class)->buildDeployScript(cacheNode()))
            ->toContain('--certificatesresolvers.le.acme.httpchallenge.entrypoint=web');
    });

    it('publishes no dashboard: the unauthenticated API on :8080 is gone', function () {
        inProduction();
        preg_match("/^TRAEFIK_RUN_ARGS='(.*)'$/m", app(NodeService::class)->buildDeployScript(cacheNode()), $traefik);

        expect($traefik[1] ?? '')->toContain('-p 443:443')
            ->not->toContain('8080')
            ->not->toContain('api.insecure');
    });

    it('tunes both containers with only the flags this host accepted', function () {
        // A single flag the host refuses makes `docker run` refuse the container, after the old
        // one was removed: each is tried alone on the node and the refused ones are left out.
        inProduction();
        $script = app(NodeService::class)->buildDeployScript(cacheNode());

        preg_match("/^TRAEFIK_RUN_ARGS='(.*)'$/m", $script, $traefik);
        preg_match("/^RUN_ARGS='(.*)'$/m", $script, $edge);

        foreach ([$traefik[1] ?? '', $edge[1] ?? ''] as $args) {
            expect($args)->toContain('$EDGE_TUNING')
                ->not->toContain('--sysctl')
                ->not->toContain('--ulimit');
        }

        expect($script)->toContain('net.ipv4.tcp_slow_start_after_idle=0')
            ->toContain('net.ipv4.tcp_notsent_lowat=131072')
            ->toContain('net.ipv4.tcp_congestion_control=bbr')
            ->toContain('--ulimit nofile=65535:65535')
            ->toContain('docker run --rm --entrypoint true $flag "$IMAGE"')
            ->toContain('refused by this host, left out')
            ->and(strpos($script, 'EDGE_TUNING_ID=$('))->toBeLessThan(strpos($script, 'run_container "$RUN_ARGS"'));
    });
});

describe('the scripts themselves', function () {
    it('parses, every script on its own and the whole deploy the panel sends', function () {
        // Nothing else here would catch a broken script. A sourced file runs command by command,
        // so a syntax error halfway down still defines every function above it, and the tests
        // that call those functions pass while the deploy itself dies at that line. A quote left
        // open in cache-disks.sh did exactly that.
        foreach (glob(resource_path('deploy/*.sh')) as $file) {
            $result = Process::run(['bash', '-n', $file]);
            expect($result->successful())->toBeTrue(basename($file).': '.$result->errorOutput());
        }

        $script = tempnam(sys_get_temp_dir(), 'deploy');
        file_put_contents($script, app(NodeService::class)->buildDeployScript(cacheNode()));
        $result = Process::run(['bash', '-n', $script]);
        unlink($script);

        expect($result->successful())->toBeTrue($result->errorOutput());
    });
});

describe('pools on mixed hardware', function () {
    it('caches spinning disks with the fast ones instead of striping them together', function () {
        // An SSD in a RAID 0 of HDDs serves at HDD speed; in front of them as dm-cache it serves
        // the hot segments. Writethrough, so the fast tier never holds the only copy.
        $shell = NodeService::deployScript('cache-disks');

        expect($shell)->toContain('queue/rotational')
            ->toContain('CACHE_FAST_MD=/dev/md/nukevideo-fast')
            ->toContain('--type cache --cachevol')
            ->toContain('--cachemode writethrough')
            // The existing array keeps its name, so a pool built before tiers is still found.
            ->toContain('CACHE_POOL_MD=/dev/md/nukevideo-cache');
    });

    it('sizes the cache chunks to the fast tier, under LVM\'s cap of a million', function () {
        // LVM's default 64 KiB chunk makes it refuse any fast tier over ~61 GiB: a 1 TB SSD
        // failed the deploy at the very last step.
        $chunk = fn (int $gib) => (int) Process::env(['SCRIPT' => resource_path('deploy/cache-disks.sh'), 'KIB' => (string) ($gib * 1024 * 1024)])
            ->run(['bash', '-c', 'SUDO=""; . "$SCRIPT"; cache_lvm() { echo "  $KIB.00"; }; cache_chunk_kib vg/fast'])
            ->output();

        expect($chunk(1))->toBe(64)
            ->and($chunk(894))->toBe(1024)
            ->and($chunk(1800))->toBe(2048)
            ->and($chunk(15000))->toBe(16384);

        foreach ([1, 61, 62, 894, 960, 1800, 3600, 15000] as $gib) {
            expect(intdiv($gib * 1024 * 1024, $chunk($gib)))->toBeLessThan(1000000)
                ->and($chunk($gib) % 32)->toBe(0);
        }

        expect(NodeService::deployScript('cache-disks'))->toContain('--chunksize "$(cache_chunk_kib "$CACHE_VG/fast")k"');
    });

    it('tunes a chain with device-mapper volumes in it without ending the deploy', function () {
        // Recent kernels give a dm volume no scheduler file, and under `set -e` reading it ended
        // the deploy silently right after the tiered pool was mounted.
        $sys = sys_get_temp_dir().'/nukevideo-sysblock-'.uniqid();
        foreach (['dm-0' => null, 'md127' => '[none]', 'sdb' => '[none] mq-deadline'] as $device => $scheduler) {
            mkdir("{$sys}/{$device}/queue", 0777, true);
            file_put_contents("{$sys}/{$device}/queue/read_ahead_kb", "128\n");
            $scheduler === null || file_put_contents("{$sys}/{$device}/queue/scheduler", "{$scheduler}\n");
        }

        $result = Process::env(['SCRIPT' => resource_path('deploy/cache-disks.sh'), 'CACHE_SYS_BLOCK' => $sys])
            ->run(['bash', '-c', 'set -e; SUDO=""; . "$SCRIPT"; for q in dm-0 md127 sdb; do cache_tune_queue "$q"; done; echo DONE']);
        Process::run(['rm', '-rf', $sys]);

        expect($result->successful())->toBeTrue()
            ->and($result->output())->toContain('dm-0: readahead 4096 KB')
            ->toContain('sdb: readahead 4096 KB')
            ->toContain('DONE');
    });

    it('adds new SSD/NVMe space to the fast tier of an existing pool, and only that', function () {
        // The free space of a system SSD, or an SSD installed later, joins the cache the pool
        // already has — it is a writethrough cache, so rebuilding it loses nothing but warmth.
        // A spinning disk would mean rebuilding the pool itself, and stays a warning.
        $shell = NodeService::deployScript('cache-disks');
        $grow = substr($shell, strpos($shell, 'cache_grow_fast_tier() {'));

        expect($shell)->toContain('if [ -n "$FREE_DISKS$SHARED_DISKS" ]; then cache_grow_fast_tier; fi')
            ->and($grow)->toContain('a spinning disk is not added to an existing pool')
            ->and($grow)->toContain('[ -z "$CACHE_BULK_DEVICE" ]')
            // Carved, the one step that can still refuse, before the cache is taken apart.
            ->and(strpos($grow, 'cache_carve_partitions'))->toBeLessThan(strpos($grow, '--uncache'))
            // Rebuilt from the old fast devices and the new ones, never from the new ones alone.
            ->and($grow)->toContain('cache_build_array "$CACHE_FAST_MD" "$CACHE_FAST_NAME" $members $fast_free $CARVED_PARTS')
            // A rebuilt array whose deploy died before it joined is attached by the next one.
            ->and($shell)->toContain('The fast tier was rebuilt but never attached');
    });

    it('never leaves a disk out: a kernel without dm-cache gets one pool of every disk', function () {
        $shell = NodeService::deployScript('cache-disks');

        expect($shell)->toContain('cache_has_dm_cache')
            ->toContain('the SSD/NVMe disks join the spinning ones in a single pool');
    });

    it('stops rather than formats a volume that will not activate', function () {
        // An inactive volume has no device node, and a missing device reads as one with no
        // filesystem: the one answer that is never wrong is to stop.
        $shell = NodeService::deployScript('cache-disks');

        $guard = strpos($shell, 'if [ ! -b "$CACHE_LV_DEVICE" ]; then');
        $format = strpos($shell, 'cache_format "$CACHE_LV_DEVICE"');

        expect($guard)->not->toBeFalse()
            ->and($guard)->toBeLessThan($format)
            ->and($shell)->toContain('Nothing was formatted.');
    });

    it('never builds an array inside a command substitution, where set -e does not reach', function () {
        // A failed `mdadm --create` there went unnoticed, and the next line force-formatted
        // whatever already had the array's name.
        $shell = NodeService::deployScript('cache-disks');

        expect($shell)->not->toMatch('/\$\(cache_build_array/')
            ->toContain('ERROR: could not create $md over $* (see above). Nothing was formatted.');
    });

    it('drops a dead fast tier but never the spinning one', function () {
        // `vgreduce --removemissing` deletes what lived on the missing device: run only when that
        // is the cache, which is writethrough and so holds nothing the spinning tier does not.
        $shell = NodeService::deployScript('cache-disks');

        $check = strpos($shell, 'the spinning tier of the cache pool is missing');
        $detached = strpos($shell, 'the missing fast tier could not be detached');
        // With the devices-file override: a host whose devices file leaves the spinning array
        // out would read it as missing too, and the pool would go with the cache.
        $reduce = strpos($shell, 'vgreduce -q --config "$CACHE_LVM_CONFIG" --removemissing');

        expect($check)->not->toBeFalse()
            ->and($reduce)->not->toBeFalse()
            ->and($check)->toBeLessThan($reduce)
            // And only once the cache is positively detached.
            ->and($detached)->toBeLessThan($reduce)
            ->and($shell)->toContain('lvconvert -q -y --config "$CACHE_LVM_CONFIG" --uncache --force');
    });

    it('leaves alone an LVM disk whose volume group it cannot read', function () {
        // `foreign` is wiped when ticked, and an unreadable PV may be a tier of this very cache.
        expect(NodeService::deployScript('cache-disks'))
            ->toContain('devices { use_devicesfile = 0 }')
            ->toContain('an LVM member whose volume group could not be read');
    });

    it('needs the tools a cached volume needs to come back at boot', function () {
        // LVM refuses to activate a cached volume without cache_check.
        expect(NodeService::deployScript('cache-disks'))
            ->toContain('cache_check=thin-provisioning-tools');
    });
});

describe('reaching the edge', function () {
    it('joins the network of a reverse proxy of the host\'s own, and names it', function () {
        // A development machine's Traefik holds port 80 and is not on nukevideo_default, so it
        // picked the edge's address there and timed out on every request.
        $script = app(NodeService::class)->buildDeployScript(cacheNode());
        preg_match("/^RUN_ARGS='(.*)'$/m", $script, $edge);

        expect($edge[1] ?? '')->toContain('${EDGE_PROXY_NETWORK:+-l')
            ->toContain('traefik.docker.network=$EDGE_PROXY_NETWORK')
            ->and($script)->toContain('docker network connect "$EDGE_PROXY_NETWORK" "$SERVICE_CONTAINER"')
            // One fixed network, never one guessed off the proxy, decided before the edge exists.
            ->and($script)->toContain("HOST_PROXY_NETWORK='traefik'")
            ->and(strpos($script, 'EDGE_PROXY_NETWORK=$HOST_PROXY_NETWORK'))->toBeLessThan(strpos($script, 'run_container "$RUN_ARGS"'));
    });
});

describe('tuning the host for a disk-backed cache', function () {
    it('tunes the pool and the host before it creates the containers', function () {
        // A new network namespace copies the host's TCP buffer sizes when it is created, so a
        // container started before the sysctls would keep the old ones.
        $script = app(NodeService::class)->buildDeployScript(cacheNode());

        $tuned = strpos($script, '/etc/sysctl.d/90-nukevideo-edge.conf');
        $pool = strpos($script, "    cache_tune_pool\n");
        $deployed = strpos($script, 'echo "=== Deploying proxy ==="');

        expect($tuned)->not->toBeFalse()
            ->and($pool)->not->toBeFalse()
            ->and($pool)->toBeLessThan($deployed)
            ->and($tuned)->toBeLessThan($deployed);
    });

    it('writes the settings an IOPS-capped pool needs, and never fails the deploy over one', function () {
        $script = app(NodeService::class)->buildDeployScript(cacheNode());

        // Sized from the host's RAM on the node, never a fixed figure: proxies differ.
        expect($script)->toContain('vm.dirty_bytes = $DIRTY_BYTES')
            ->toContain('vm.vfs_cache_pressure = 50')
            ->toContain('net.ipv4.tcp_wmem = 4096 65536 33554432')
            ->toContain('sysctl -q -e -p /etc/sysctl.d/90-nukevideo-edge.conf || echo')
            ->toContain('CACHE_READ_AHEAD_KB=4096')
            ->toContain('ATTR{queue/scheduler}="mq-deadline"')
            // Past 60-persistent-storage and 63-md-raid-arrays, which set the labels it matches.
            ->toContain('/etc/udev/rules.d/99-nukevideo-cache.rules');
    });
});

describe('choosing the disks', function () {
    beforeEach(fn () => inProduction());

    it('formats only the disks the operator ticked and says which it left alone', function () {
        $script = app(NodeService::class)->buildDeployScript(cacheNode(), ['/dev/sdb', '/dev/sdc']);

        expect($script)->toContain("CHOSEN_DISKS='/dev/sdb /dev/sdc'")
            ->and($script)->toContain('Left untouched, not selected');
    });

    it('hands the node one list of disks to format and another of disks to share', function () {
        // A name alone does not say which fate was agreed to, and the node reads the state again.
        $script = app(NodeService::class)->buildDeployScript(cacheNode(), ['/dev/sdb'], ['/dev/sda']);

        expect($script)->toContain("CHOSEN_DISKS='/dev/sdb'")
            ->and($script)->toContain("CHOSEN_SHARED='/dev/sda'");
    });

    it('never shares a disk nobody named, even when it formats every spare one', function () {
        $none = app(NodeService::class)->buildDeployScript(cacheNode());
        expect($none)->toContain("CHOSEN_SHARED=''");

        $picked = selectDisks(['/dev/sda' => 'shared', '/dev/sdb' => 'empty', '/dev/sdc' => 'foreign', '/dev/nvme0n1' => 'system']);

        expect($picked['ok'])->toBeTrue()
            ->and($picked['format'])->toBe(['/dev/sdb', '/dev/sdc'])
            ->and($picked['share'])->toBe([]);
    });

    it('gives each ticked disk the fate it was ticked for', function () {
        $picked = selectDisks(
            ['/dev/sda' => 'shared', '/dev/sdb' => 'empty', '/dev/sdc' => 'foreign'],
            ['CHOSEN_DISKS' => '/dev/sdb', 'CHOSEN_SHARED' => '/dev/sda'],
        );

        expect($picked['ok'])->toBeTrue()
            ->and($picked['format'])->toBe(['/dev/sdb'])
            ->and($picked['share'])->toBe(['/dev/sda'])
            ->and($picked['output'])->toContain('Left untouched, not selected: /dev/sdc');
    });

    it('refuses to deploy when a disk ticked as shared no longer is one', function () {
        // Its filesystem got unmounted between the panel and the deploy: the disk reads as
        // `foreign` now, and a single list would have sent it, whole, to the wipe.
        $picked = selectDisks(
            ['/dev/sda' => 'foreign', '/dev/sdb' => 'empty'],
            ['CHOSEN_DISKS' => '/dev/sdb', 'CHOSEN_SHARED' => '/dev/sda'],
        );

        expect($picked['ok'])->toBeFalse()
            ->and($picked['output'])->toContain('ERROR: /dev/sda was to be shared')
            ->and($picked['format'])->toBe([]);
    });

    it('never selects a system disk, whichever list names it', function () {
        $format = selectDisks(['/dev/sda' => 'system', '/dev/sdb' => 'shared'], ['CHOSEN_DISKS' => '/dev/sda /dev/sdb', 'CHOSEN_SHARED' => '']);
        $share = selectDisks(['/dev/sda' => 'system'], ['CHOSEN_DISKS' => '', 'CHOSEN_SHARED' => '/dev/sda']);

        // Ticked to be formatted, a system or shared disk is simply not among the spare ones.
        expect($format['ok'])->toBeTrue()
            ->and($format['format'])->toBe([])
            ->and($format['share'])->toBe([])
            ->and($share['ok'])->toBeFalse();
    });

    it('accepts the same lists again once the disk carries the pool', function () {
        // An API client redeploying with the body of its first deploy is asking for what it has.
        $picked = selectDisks(['/dev/sda' => 'nukevideo', '/dev/sdb' => 'nukevideo'], ['CHOSEN_DISKS' => '/dev/sdb', 'CHOSEN_SHARED' => '/dev/sda']);

        expect($picked['ok'])->toBeTrue()
            ->and($picked['format'])->toBe([])
            ->and($picked['share'])->toBe([]);
    });

    it('carries the shared list from the request to the node', function () {
        $node = cacheNode();
        $captured = '';
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('run')->once()->andReturnUsing(function (...$args) use (&$captured) {
            $captured = $args['input'] ?? $args[5] ?? $captured;

            return '';
        });
        app()->instance(SSHService::class, $ssh);
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $this->postJson("/api/nodes/{$node->id}/deploy", ['disks' => ['/dev/sdb'], 'sharedDisks' => ['/dev/sda']])->assertStatus(202);

        expect($captured)->toContain("CHOSEN_DISKS='/dev/sdb'")
            ->and($captured)->toContain("CHOSEN_SHARED='/dev/sda'");
    });

    it('refuses a disk named in both lists, and a shared path that is not one', function () {
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));
        $node = cacheNode();

        $this->postJson("/api/nodes/{$node->id}/deploy", ['disks' => ['/dev/sda'], 'sharedDisks' => ['/dev/sda']])->assertUnprocessable();
        $this->postJson("/api/nodes/{$node->id}/deploy", ['disks' => [], 'sharedDisks' => ['/dev/sda; reboot']])->assertUnprocessable();
    });

    it('formats nothing when the list is empty, and everything when there is none', function () {
        $none = app(NodeService::class)->buildDeployScript(cacheNode(), []);
        $all = app(NodeService::class)->buildDeployScript(cacheNode());

        expect($none)->toContain("CHOSEN_DISKS=''")
            ->and($all)->not->toContain('CHOSEN_DISKS=');
    });

    it('refuses a production proxy deploy that does not say which disks', function () {
        // The service reads a missing list as "every spare disk". The panel omitted the field
        // whenever it had nothing to show — including after failing to read the host's disks —
        // and that silence formatted every disk it had not been able to list.
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));
        $node = cacheNode();

        $this->postJson("/api/nodes/{$node->id}/deploy")->assertUnprocessable();
        $this->postJson("/api/nodes/{$node->id}/deploy", ['disks' => null])->assertUnprocessable();
    });

    it('accepts an empty list as "format none"', function () {
        $node = cacheNode();
        $captured = '';
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('run')->once()->andReturnUsing(function (...$args) use (&$captured) {
            $captured = $args['input'] ?? $args[5] ?? $captured;

            return '';
        });
        app()->instance(SSHService::class, $ssh);
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $this->postJson("/api/nodes/{$node->id}/deploy", ['disks' => []])->assertStatus(202);
        expect(Activity::inLog('node')->latest('id')->first()->properties['status'])->toBe('succeeded');
        expect($captured)->toContain("CHOSEN_DISKS=''");
    });

    it('still deploys a worker without a list', function () {
        $node = cacheNode(['type' => 'worker']);
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('run')->once()->andReturn('');
        app()->instance(SSHService::class, $ssh);
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $this->postJson("/api/nodes/{$node->id}/deploy")->assertStatus(202);
        expect(Activity::inLog('node')->latest('id')->first()->properties['status'])->toBe('succeeded');
    });
});

describe('the pool check', function () {
    beforeEach(fn () => inProduction());

    it('reports a host that never had a pool as a notice, and an unmounted pool as an error', function () {
        $node = cacheNode();
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('run')->andReturnUsing(function (...$args) {
            $command = $args['command'] ?? $args[3];
            if (str_contains($command, 'mountpoint -q')) {
                expect($command)->toContain('blkid -L '.ProxyCacheService::FS_LABEL)
                    ->and($command)->toContain('no cache pool provisioned')
                    ->and($command)->toContain('NOT mounted');

                return 'no cache pool provisioned, edge caches on a docker volume';
            }

            return 'ok';
        });
        app()->instance(SSHService::class, $ssh);

        $checks = collect(app(NodeService::class)->runValidation($node))->keyBy('key');

        expect($checks['cache']['status'])->toBe('ok')
            ->and($checks['cache']['output'])->toContain('docker volume');
    });
});

describe('the node env overrides', function () {
    beforeEach(fn () => inProduction());

    it('cannot replace what the deploy owns', function () {
        // A node's env is a free-text field. Merged last, one line in it re-pointed the edge at
        // another API, another bucket, or a token secret of the editor's choosing.
        $node = cacheNode(['env' => "VOD_TOKEN_SECRET=mine\nINTERNAL_API_URL=http://evil.example\nAWS_SECRET_ACCESS_KEY=mine\nVOD_CACHE_INACTIVE=7d"]);

        $env = app(NodeService::class)->getEnvironmentVariables($node);

        expect($env)->toContain('VOD_TOKEN_SECRET=secret')
            ->and($env)->not->toContain('VOD_TOKEN_SECRET=mine')
            ->and($env)->not->toContain('INTERNAL_API_URL=http://evil.example')
            ->and($env)->not->toContain('AWS_SECRET_ACCESS_KEY=mine')
            // Tuning knobs still override.
            ->and($env)->toContain('VOD_CACHE_INACTIVE=7d');
    });

    it('refuses a device path that is not one', function () {
        // The path lands in a shell script on the node.
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $this->postJson('/api/nodes/'.cacheNode()->id.'/deploy', ['disks' => ['/dev/sda; rm -rf /']])
            ->assertUnprocessable();
    });
});
