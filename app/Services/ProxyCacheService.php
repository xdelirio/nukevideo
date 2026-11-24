<?php

namespace App\Services;

use App\Data\Node\DeployNodeData;
use App\Models\Node;

/**
 * The edge cache disks of a proxy node: finding them, formatting them and mounting them.
 *
 * Nodes are not alike, and the pool adapts to what each one has (see `cache_create_pool` in the
 * script): disks of one kind make the RAID 0 described below, and a mix of spinning disks with
 * SSD/NVMe is built in two tiers — the spinning RAID 0 for capacity and the fast disks as an LVM
 * dm-cache in front of it — so the fast disks serve the hot segments instead of being striped
 * down to the speed of the slow ones.
 *
 * The bash itself is `resources/deploy/cache-disks.sh`; this class holds the constants the
 * rest of the application needs to agree with it, and the panel's read-only listing.
 *
 * A proxy node is dedicated hardware: a small NVMe (or a mirrored pair) carrying the OS and every
 * other disk meant for the segment cache, whole. So the deploy provisions those disks itself —
 * one mdadm RAID 0 across all of them (plain XFS when there is only one), mounted at a fixed path —
 * instead of asking the operator to prepare a filesystem and point the node at it.
 *
 * RAID 0 on purpose. The cache is a copy of what S3 holds, so redundancy would buy nothing but
 * lost capacity and write amplification; what it needs is throughput, and one spindle cannot
 * feed a 10 Gbps port. Linux md stripes unequal members by zones, so mixed sizes lose nothing.
 * A dead member loses the whole cache of that node, which the node survives: every miss is an
 * origin fetch until the disk is replaced and the node redeployed.
 *
 * What it must never do is format the wrong disk. Every block device that holds a mountpoint,
 * swap, or a RAID/LVM member whose assembled volume is mounted is the system's and is excluded
 * — which is how the OS disks are recognised, rather than by "NVMe means OS", because a cache
 * disk may be NVMe too. A disk already carrying this cache is assembled and mounted as it is: a
 * redeploy, which is how a node is updated, keeps the cache. Anything else among the remaining
 * disks is wiped, which is why the panel lists them and asks before a deploy runs, and why a
 * development panel ticks none of them — its deploy target is usually the developer's own
 * machine, where an unmounted disk is a backup, not a spare.
 *
 * Not every host has a disk to give whole, though: a server with nothing but two HDDs has the
 * OS on one of them, and most of that disk is the capacity it was bought for. A system disk
 * with unpartitioned space left on it is therefore offered as `shared`: ticked, the deploy
 * appends one partition over that space and makes it a pool member like any whole disk (or the
 * whole pool, on a host with a single disk). It never shrinks, moves or reformats what is
 * there, so the space has to be left unpartitioned when the OS is installed — a root
 * filesystem spanning the disk leaves nothing to carve, and no deploy is going to resize one.
 * GPT only, and never a disk that is whole a member of a mirror: see the script for both.
 *
 * Sharing is asked for by name, in a list of its own ({@see DeployNodeData}),
 * and refused when the disk no longer is what the panel showed.
 */
class ProxyCacheService
{
    /** Host mount point of the cache pool. Fixed: every proxy node looks the same. */
    public const MOUNT = '/var/lib/nukevideo/cache';

    /** Path the edge nginx caches into ({@see vod/nginx/nginx.conf.template}). */
    public const CONTAINER_PATH = '/var/cache/nginx/vod';

    /**
     * Filesystem label and md array name; they are how a redeploy recognises its own pool. Two
     * names rather than one because XFS takes a label of twelve characters and mkfs.xfs refuses
     * a longer one outright. mdadm stores the array's as `{homehost}:{name}`, which is why the
     * RAID member check matches on the suffix — and why the name carries no colon of its own,
     * or udev would publish the array under a different `/dev/md/` path on every host whose
     * name is not the one in the superblock.
     */
    public const FS_LABEL = 'nukevideo';

    public const MD_DEVICE = '/dev/md/nukevideo-cache';

    /**
     * `max_size` handed to the edge when it is caching into a docker volume on the OS disk —
     * development, or a production host found without a single spare disk — rather than a pool
     * it may fill. The entrypoint sizes the cache to the filesystem only when this is absent.
     */
    public const FALLBACK_MAX_SIZE = '10g';

    public function __construct(private SSHService $ssh, private SshKeyService $sshKeys) {}

    /**
     * The host directory this node's edge caches into. One sub-directory per node and per
     * environment under the shared mount, like the container names, so a development proxy
     * deployed beside a production one on the same host never reads or evicts its cache.
     */
    public static function directoryFor(Node $node): string
    {
        return self::MOUNT.'/'.$node->serviceContainerName();
    }

    /** The docker volume used when there is no pool; removed with the node. */
    public static function volumeFor(Node $node): string
    {
        return Node::containerPrefix()."_proxy_cache_{$node->id}";
    }

    /**
     * What a deploy would do to the node's disks, read over SSH without changing any of them.
     *
     * It does install sfdisk when the host lacks it. The listing used to install nothing, and
     * on a first setup — the one time nothing has been deployed there yet — that meant a system
     * disk with room to share was never offered, with a note asking the operator to install a
     * package by hand and come back. A short apt lock wait rather than the deploy's: somebody
     * is looking at a spinner. Failing to install it is not failing to list.
     *
     * @return array<int, array{device: string, size: int, model: string, state: string, detail: string, free: int, fast: bool}>
     */
    public function inventory(Node $node): array
    {
        $output = $this->ssh->run(
            ip: $node->sshHost(),
            user: $node->user,
            privateKey: $this->sshKeys->privateKey(),
            command: 'bash -s',
            timeout: 120,
            // `blkid -p` reads the raw device, which needs root; without it a partitioned disk
            // with no filesystem would pass for an empty one. The deploy's own sudo preamble
            // goes first, so a user whose sudo wants a password fails here, loudly and with the
            // same fix message, instead of the inventory quietly calling that disk `empty`.
            input: implode("\n", [
                NodeService::deployScript('sudo'),
                'APT_LOCK_TIMEOUT=20',
                NodeService::deployScript('packages'),
                NodeService::deployScript('cache-disks'),
                // Onto stderr: stdout is the listing, and only the listing.
                'ensure_commands sfdisk=fdisk >&2 || true',
                // What tells a tier of this cache from someone else's LVM disk: without it every
                // LVM member is listed as unreadable, and so untouchable.
                'ensure_commands lvcreate=lvm2 >&2 || true',
                'cache_disk_inventory',
                '',
            ]),
        );

        $disks = [];
        // Newlines only: a field in the middle may be empty (the detail of a blank disk), and
        // a plain trim() once ate the trailing tab of the last line with it, dropping exactly
        // the disk that matters.
        foreach (explode("\n", trim($output, "\r\n")) as $line) {
            $fields = explode("\t", rtrim($line, "\r"));
            if (count($fields) !== 7) {
                continue;
            }
            [$device, $size, $model, $state, $detail, $free, $fast] = $fields;
            $disks[] = ['device' => $device, 'size' => (int) $size, 'model' => $model, 'state' => $state, 'detail' => $detail, 'free' => (int) $free, 'fast' => $fast === '1'];
        }

        return $disks;
    }

    /**
     * Whether the pool on these disks has a fast tier, the one thing new SSD/NVMe space next to
     * an existing pool can join (`cache_grow_fast_tier`). Read off the inventory's own words for
     * a member of a tiered pool: a physical volume of its group, or a member of its fast array.
     *
     * @param  array<int, array{state: string, detail: string}>  $disks
     */
    public static function hasTieredPool(array $disks): bool
    {
        foreach ($disks as $disk) {
            if ($disk['state'] === 'nukevideo' && str_contains($disk['detail'], 'tier')) {
                return true;
            }
        }

        return false;
    }
}
