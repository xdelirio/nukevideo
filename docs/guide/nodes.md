# Nodes

Nodes are remote servers that handle video encoding (workers) or content delivery (proxies). NukeVideo manages nodes via SSH and deploys Docker containers to them.

## Node Types

| Type | Purpose |
|------|---------|
| `worker` | Video encoding with FFmpeg |
| `proxy` | CMAF delivery from S3 with token validation |

## Worker Nodes

Each worker node runs one container (Horizon) that pulls encoding jobs from Redis queues. Sources are split into keyframe-aligned chunks and encoded in parallel across every worker of the fleet (x264, x265, SVT-AV1 on CPU; QSV or NVENC on a node whose `accel` is `intel` or `nvidia`), then packaged into static CMAF.

### Chunk store

The workers share a scratch S3 store on their private network — RustFS, the `chunks` disk — for
everything between the primary bucket and the final package: the mirrored source, every encoded
chunk and the staged audio and subtitles. It runs on one worker, and all of them must reach it
over a private network: that traffic is the bulk of an encode, so put the store and the workers
in the same network (with a VPN such as WireGuard or Tailscale if they span providers) rather than
paying egress between them.

Its address is one setting for the whole fleet, under **Nodes → Environment → Chunk store**: an IP
or a DNS name, with `:port` when 9000 is taken on that host. The first worker you create fills it
in with its own IP. At each worker's deploy the node checks whether the address resolves to one of
its own interfaces; the one where it does runs the store, published on that address only — never
on every interface — and every worker is pointed at it. A redeploy of that worker leaves a running
store as it is, unless its address, credentials or image changed, so the other workers' transfers
are not cut.

The images the hosts share — the chunk store, Traefik and Vector — are pinned to a release in
`NodeService` and pulled at deploy. Updating one is a version bump there: each host picks it up at
its next deploy, recreating the container once. To recreate one without a new version (a container
in a bad state), remove it on the host (`docker rm -f <name>`) and redeploy the node. A worker's deploy fails when no address is
set, and when the store does not answer there within two minutes (so an address that is no
worker's own is caught at deploy time, not on the first chunk). Proxies never use it. The API host does,
to clear and check the mirror when a video is retried: set `CHUNKS_S3_ENDPOINT=http://<address>`
in its `.env` (with the store's port), since it is not deployed by the panel.

The store holds the videos in flight, so it is not meant to move: change the address only with no
video processing, then redeploy every worker. Deleting the node that runs it clears the address;
the next worker created claims it, or set it by hand.

### Job Distribution

Workers are not assigned videos: they pull. `videos:dispatch` (every five seconds on the API host) admits pending videos while each hardware family — CPU, Intel, NVIDIA — has fewer videos in flight than twice its active worker nodes plus one, and a video's chunk jobs then go to its family's queue, drained by every worker of that family. GPU nodes do not drain the CPU queue. Software renditions above 1080p go to a queue of their own, `video-processing-heavy`, which every CPU worker drains with one or two processes that wait for free memory before encoding. Admission pauses for ten minutes whenever a job runs out of attempts against the primary store, so an outage there costs a wait rather than videos. How many chunks a node encodes at once is sized from its CPU cores and RAM when the container starts; `VIDEO_WORKER_PROCESSES` and `HEAVY_WORKER_PROCESSES` (CPU) or `GPU_WORKER_PROCESSES` in the node's environment override it.

## Proxy Nodes

Proxy nodes run a custom nginx build that delivers the **pre-packaged CMAF** from S3. They do not repackage anything at request time.

They handle:
- Validating Akamai-style stream tokens
- Reading packaged segments from S3 using AWS authentication
- Local segment caching (manifests for 30 seconds, so a playback start skips the trip to S3)
- Cloudflare real-IP resolution
- Shipping access-log bandwidth to the API via Vector.dev


### Cache disks

A proxy node is dedicated hardware: a small NVMe (or a mirrored pair) for the operating system, and every other disk for the segment cache. The deploy provisions those disks itself — you do not prepare a filesystem or point the node at one.

What a deploy does with the host's disks:

- **Disks holding the system** — anything with a mountpoint or swap on it, including the members of a RAID or LVM volume that is mounted — are never formatted, and nothing on them is ever touched (the last point below is the one thing a deploy may add to one). This is how the OS disks are recognised, not by their bus: a cache disk may be NVMe too.
- **Disks already carrying the cache** (the `nukevideo` filesystem label, or a member of the `nukevideo-cache` array) are mounted as they are. A redeploy — which is how a node is updated — keeps the cache warm.
- **Every other disk** is formatted into the cache pool, mounted at `/var/lib/nukevideo/cache`, and how depends on the kinds of disk the node has:
    - **One kind** — all spinning, or all SSD/NVMe: one mdadm **RAID 0** across all of them (plain XFS when there is only one). RAID 0 because the cache is a copy of what S3 holds — redundancy would cost capacity and write amplification for nothing — and because one spindle cannot feed a 10 Gbps port. Disks of different sizes lose nothing: Linux md stripes them by zones.
    - **A mix** — say 2 SSDs and 2 HDDs, or 15 HDDs and 2 NVMe: two tiers. The spinning disks make a RAID 0 that is the pool's capacity; the SSD/NVMe disks make a second one that LVM's **dm-cache** puts in front of it, so the blocks read most — the segments being watched — are served from flash and the long tail from disk. Striping the two kinds together would make every read wait for the slowest disk, which is why they are not. The cache is *writethrough*: everything is also on the spinning tier, so losing the fast tier costs speed, never the pool. Its capacity holds copies, so the pool's size is the spinning tier's. A kernel without dm-cache (some stripped cloud kernels) gets one RAID 0 of every disk instead, and the deploy says so.
    - The kind is what the kernel reports for the disk (`/sys/block/*/queue/rotational`); a partition shared with the OS is as fast as its disk.
- **A system disk with unpartitioned space** is offered as *shared*. This is the host with nothing but HDDs — two of them, say, one free and the other holding the OS on a fraction of its capacity. Ticked, the deploy appends one partition over that space and uses it as a pool member next to the whole disks (or as the whole pool, on a host with a single disk). Nothing that exists on the disk is moved, shrunk or reformatted, which is also the requirement: **leave the space unpartitioned when you install the OS** — a 50 GB root and the rest of the disk untouched. What is not offered: a root filesystem that spans the disk, free space inside an LVM volume group, less than 50 GB (the edge would use only half of it), an MBR partition table (GPT only), and a disk that is whole a member of a mirror — software or firmware RAID over entire disks, where the partition table belongs to the array and not to the disk. A mirror built from *partitions* of both disks, with space left after them, is offered on both.

The panel lists those disks, with whatever it found on them, and asks before the deploy runs. In production everything the cache can use comes ticked — the spare disks and the free space of the system disks — so a node's whole storage goes to work; untick one to leave it alone. A shared disk's partition takes all the space the OS could still have grown into, and the cache's I/O lands on the disk the system runs from: untick it if that disk should stay quiet. The node looks at its disks again when the deploy runs, and refuses to go on if one ticked as shared no longer is a system disk with room to spare — it is never formatted instead. A host that already has a pool keeps it, and is offered one thing only: **SSD/NVMe space for its fast tier**, when the pool has one — a new SSD, or the free space left on a system SSD after shrinking its root. The fast tier is a cache, so it is rebuilt with the new space without touching the pool: the deploy detaches it, makes one RAID 0 of the old fast devices and the new ones, and attaches it again. Nothing the pool holds is lost; the fast cache starts empty and refills from it over the next hours. Nothing is ticked for this by default — every redeploy of the node shows the list, and growing the fast tier carves the OS disk and starts the SSD cache cold, so it is a choice made on purpose. The same offer stands when the pool has lost its fast tier (a dead SSD, a grow cut short): ticking SSD space then gives it one back. Every other disk is left alone: a spinning disk would mean rebuilding the pool. A host without a single spare disk, and with no room on its system disks, caches into a docker volume on the OS disk, capped at 10 GB, and the deploy says so.

The edge then sizes its cache to the pool when it starts: everything but a reserve of 3 % (at least 20 GB) becomes `max_size`, the reserve becomes `min_free`, and the keys zone is sized for the number of segments the pool can hold. Nothing about the cache is configured by hand, and beyond dropping a segment nobody has played for 30 days, nothing expires by the calendar — eviction is the LRU's job.

**Reading the numbers.** The nodes page shows, per proxy and for the last week, the cache hit ratio by bytes, what the node served and what it fetched from S3 to do so (`GET /api/analytics/edges?from&to`, admin only). Downloads and manifests never enter the cache and are left out of the ratio. A ratio that holds while the pool fills is the cache doing its job; a ratio that falls with a full pool means the working set outgrew the disk — add disk, or add a node so each one holds less of the catalogue. A low ratio with an empty pool is a cold cache: a fresh deploy, a rebuilt pool, or a node that just joined the ring.

**When a node's pool is full** nothing breaks: nginx evicts the least recently played segments to make room, and the hit ratio is the only thing that drops. Grow the pool or add another proxy node; do not deactivate the full one, which would only move its videos, cold, onto the others.

**A dead disk** takes the whole pool with it. The node keeps serving — every request is a miss until the disk is replaced and the node redeployed, which builds a new pool. A dead disk of the fast tier only slows the node down; it is replaced the same way. An unclean shutdown can leave the fast tier's cache metadata failing LVM's check, and LVM then refuses to activate the pool at boot. The pool itself is intact — the cache is writethrough, so the spinning tier holds every block — and the deploy installs `nukevideo-cache-boot.service`, which runs before docker on every boot: it waits up to a minute for the disks, drops a cache that will not activate (or a fast tier whose SSD is gone) and mounts the pool off the spinning tier, so the edge starts on its pool rather than on the OS disk. It never formats or attaches anything: the node serves without its SSD cache until the next deploy attaches a fresh one. Its log is `journalctl -u nukevideo-cache-boot`; when it could not bring the pool up, the edge starts capped at 10 GB on the OS disk and says so in its own log. To rebuild a pool on purpose (to add a spinning disk, for instance), stop the edge, `umount /var/lib/nukevideo/cache`, `vgremove -f nukevideo_edge` if the pool is tiered, `mdadm --stop` each array (`/dev/md/nukevideo-cache`, `/dev/md/nukevideo-fast`), wipe the label from each member with `wipefs -a` — the partition, for a shared disk, never the disk — and redeploy; the panel will offer them again. A deploy that was interrupted halfway is picked up where it stopped: a partition it carved but never formatted is offered again, an array it created but never formatted is formatted, and a tiered pool missing its volume group, its volume or its cache gets the steps that are missing.

**From a development panel** the list comes with nothing ticked: a development deploy's target is often the developer's own machine, where an unmounted disk is a backup, not a spare. Tick what you mean to wipe; with nothing ticked the edge caches into a docker volume.

### Cloudflare Real IP

The proxy includes built-in support for resolving real client IPs when behind Cloudflare or other reverse proxies. The `cloudflare-realip.conf` file is included in the Nginx config and trusts:

- Docker internal networks (`172.16.0.0/12`, `10.0.0.0/8`)
- All Cloudflare IPv4 and IPv6 ranges

The `X-Forwarded-For` header is used with `real_ip_recursive on`, which correctly resolves the client IP regardless of how many trusted proxies are in the chain.

### Scaling delivery

Every proxy node shares the token secret, so any of them can serve any video. What decides which one a viewer is sent to is a **consistent hash ring** over the routable proxies: each video lands on one node, whose cache is the only one that ever holds its segments. Adding a node moves about 1/(N+1) of the catalogue onto it — those videos arrive cold and the rest stay warm — and the fleet's traffic to S3 stays roughly what the catalogue churns, however many nodes there are. Do not put a load balancer or a round-robin DNS record in front of the proxies: it defeats the sharding and every node ends up caching everything.

A proxy is **routable** when it is active, has a hostname, is not draining and is answering the health probe.

**Draining.** Toggle it on a node before maintenance or before taking it out for good. New playback links go to its ring neighbours; the node goes on serving the sessions it has. Stopping a node, by contrast, takes its containers down. Playback links stay valid for the token window (an hour by default), and the segments a session fetches expire with its link: wait that long after draining before stopping it, and nobody notices.

**Health.** `nodes:probe` runs every minute on the API host and fetches `/healthz` on every active proxy with a hostname. The edge answers with a header carrying its own node id, and only that counts as alive — a 404 from Traefik, a 403 from Cloudflare or somebody else's site at the hostname all count as silence. After three consecutive failures the node is taken out of new links and marked **unhealthy** in the panel; the first good probe puts it back, as does a successful deploy or start. Failures in the first 10 minutes after a deploy or an edit of the node are not counted, which covers a certificate still being issued or DNS still settling. Sessions already on a node that dies are lost; this is for the next ones. Should every proxy look dead at once, the resolver assumes the probe is wrong rather than the fleet and keeps linking to all active nodes.

An edge from before `/healthz` existed fails the probe and drops out of rotation: redeploy it.

Behind Cloudflare, `/healthz` must reach the edge: exempt it from any WAF, bot or Access rule, or the probe sees Cloudflare's answer instead of nginx's and marks the node unhealthy.

## Managing Nodes

### Prerequisites

**The SSH user.** The deploy runs over SSH with no terminal, so whatever it needs root for
(installing Docker, enabling the service, the cache-disk setup on a proxy) has to work without a
password prompt. Either:

- connect as `root` (with `PermitRootLogin prohibit-password` and the key below in
  `/root/.ssh/authorized_keys`) — the usual choice on a server, and what production is expected
  to use; or
- a dedicated user with passwordless sudo:
  `echo 'nukevideo ALL=(ALL) NOPASSWD: ALL' > /etc/sudoers.d/nukevideo` (mode `0440`).
  Membership of the `sudo` group alone is not enough: that rule asks for a password.

The deploy adds the user to the `docker` group itself. Note that this group is root-equivalent —
anyone in it can start a privileged container over the host filesystem — so a restricted user
buys no isolation over root here; it only satisfies a "no root login" policy. The script checks
`sudo -n` first and aborts with the fix if the host would have prompted.

**The packages.** The deploy installs what it needs and the host lacks — Docker, `curl`, and on a
proxy `mdadm`, `xfsprogs` and `fdisk` for the cache disks — with `apt-get`, refreshing the package
lists first, so a fresh Debian or Ubuntu image needs nothing prepared. On any other distribution
install those yourself: the deploy stops and names what is missing rather than going on without it.

**The images.** A node never builds; it pulls `nukevideo-api` / `nukevideo-proxy` at the tag the
panel decides: the released version in production, `node-dev` from `DOCKER_REGISTRY` in
development. For a development node, build and push that tag from the checkout first —
`bin/push-node-dev` — then deploy; repeat after every change you want the node to run.

**The SSH key.** The panel connects to every node with one key, kept under Settings → App
(`PUT /api/app-settings/ssh-key`). Generate it there, copy the public key it shows and add it to
`~/.ssh/authorized_keys` of the SSH user on each node — the same line on every node. A pair made
elsewhere can be imported by its private half alone; the public key is derived from it. To
replace the key on a running fleet, **Rotate** installs the new key on every node with the old
one before switching, so the panel never loses access — see [Rotate the SSH Key](/api/nodes#rotate-the-ssh-key).

### Creating a Node

```
POST /api/nodes
{
  "name": "worker-us-east-1",
  "ipAddress": "203.0.113.10",
  "user": "root",
  "type": "worker"
}
```

A proxy also needs a `hostname`: it is the address playback links point at. See [Create Node](/api/nodes#create-node) for every field.

### DNS with Cloudflare

That hostname has to resolve to the node. If the domain is on Cloudflare the panel can do it for
you: store an API token under **Settings → App → Cloudflare DNS** and every proxy deploy creates,
or corrects, an address record `hostname → ipAddress` before it touches the node, so the record
is there when the certificate is requested. Deleting the node, or changing its hostname, removes
the record it had.

- **The token** needs `Zone → Zone → Read` and `Zone → DNS → Edit` on the zones your proxies
  use. It is stored encrypted in the application settings — not in `.env` — and never shown
  again. No zone is configured: the one a hostname belongs to is found among those the token
  can see, so proxies may live under several domains.
- **Only its own records.** Every record it creates carries the comment `Managed by NukeVideo`,
  and nothing without that comment is ever changed or deleted. If the hostname already has a
  record pointing somewhere else, the deploy stops and says so; one that already points at the
  node is left as it is.
- **What it skips.** A hostname in no zone the token can see, and a node whose `ipAddress` is a
  private one, are deployed as before with a line in the log saying the record is yours to
  create. The address published is the one the panel reaches the node by over SSH.
- **DNS only or proxied.** Records are DNS only by default: viewers reach the edge directly,
  which is already the cache. Switching **Proxy through Cloudflare** on creates them proxied
  instead, from each proxy's next deploy. That switch is the fleet's default: each proxy has
  its own **Cloudflare proxy** choice (`cloudflareProxied` — *Installation default*, *Proxied*
  or *DNS only*), so one edge can sit behind Cloudflare while the rest are reached directly.
  A proxied node's Traefik is deployed trusting Cloudflare's address ranges for
  `X-Forwarded-For`, so the edge still logs and geolocates the viewer rather than Cloudflare.
  Before you proxy one: check that Cloudflare's terms cover
  your video traffic; exempt `/healthz` from WAF, bot and Access rules (see Health, above); and
  mind the certificate — the node still obtains it with an HTTP challenge, which a zone on
  *Full (strict)* with *Always Use HTTPS* can keep from ever completing the first time.

A worker that cannot be reached over SSH (behind NAT, say) can install itself instead: **Setup → Install**
in the node's menu generates a one-time `curl … | bash` command to run on the machine — see
[Bootstrap Command](/api/nodes#bootstrap-command).

### Deploying, starting and stopping

A node has three actions, in its menu on the nodes page: **Deploy**, **Start** and **Stop**. They
run in the background — close the tab and nothing stops. **Logs** (next to Add Node, or in a
node's menu) shows the output of the latest operation, of any node or of the one picked; the
history of every operation is in the **Activity Log**. A node runs one operation
at a time.

- **Deploy** installs what the node needs, pulls the image and recreates the containers. It is also
  how a node is updated. On a production worker it waits for the running jobs to finish (up to 11
  minutes; development and staging do not wait); **force** kills them instead, and they are picked
  up again about 31 minutes later. On a proxy the gap is the edge's own startup, a second or two
  that players ride out on their buffer; the deploy fails if the new edge does not answer
  `/healthz`, and Traefik is only replaced when it is not running or its image or flags changed.
  Deploying a stopped node starts it.
- **Stop** takes the node out first — no new videos, no new playback links — then stops its
  containers, gracefully or, with force, at once. The containers stay stopped across reboots.
- **Start** brings a stopped node back.

To update the fleet, select the nodes and **Deploy selected**. Stopped nodes are left out. Workers deploy all at once (encoding
waits meanwhile); proxies deploy one at a time, and the first one that fails cancels the rest, so a
broken image never takes more than one edge down. A fleet deploy never formats a disk: adding one to
a proxy's cache pool is a deploy of that node alone.

### Checking a node

**Setup → Validate** (`POST /api/nodes/{id}/validate`) runs a set of checks over SSH — Docker, the Docker
network, the node's running containers, free disk, plus a test encode on a GPU node and the cache
pool on a proxy — and reports each one as `ok` or `error` with its output. How many videos are
waiting, running or failed is `GET /api/analytics/queue`.

## API Endpoints

| Method | Endpoint | Description | Auth |
|--------|----------|-------------|------|
| `GET` | `/api/nodes` | List all nodes | Admin |
| `POST` | `/api/nodes` | Create a node | Admin |
| `GET` | `/api/nodes/{id}` | Get a node | Admin |
| `PUT`/`PATCH` | `/api/nodes/{id}` | Update a node | Admin |
| `DELETE` | `/api/nodes/{id}` | Delete a node and its containers | Admin |
| `POST` | `/api/nodes/{id}/validate` | Run the health checks | Admin |
| `GET` | `/api/nodes/{id}/cache-disks` | What a deploy would do to a proxy's disks | Admin |
| `POST` | `/api/nodes/{id}/bootstrap-token` | One-time install command (workers) | Admin |
| `POST` | `/api/nodes/{id}/deploy` | Deploy a node | Admin |
| `POST` | `/api/nodes/{id}/start` | Start a node | Admin |
| `POST` | `/api/nodes/{id}/stop` | Stop a node | Admin |
| `POST` | `/api/nodes/deploy` | Deploy several nodes | Admin |
| `GET` | `/api/node-operations` | List operations | Admin |
| `GET` | `/api/node-operations/{id}/lines` | An operation's output | Admin |
