# Nodes

Manage worker and proxy nodes. All node endpoints require **admin** privileges.

See the [Nodes guide](/guide/nodes) for architecture details.

## List Nodes

```
GET /api/nodes
```

**Response:** `{ "data": { "nodes": [/* Node objects */] } }` — the whole fleet, unpaginated.

## Get Node

```
GET /api/nodes/{id}
```

**Response:**

```json
{
  "data": {
    "id": 1,
    "uuid": "9b2f…",
    "name": "edge-1",
    "user": "root",
    "ipAddress": "203.0.113.10",
    "type": "proxy",
    "accel": null,
    "hostname": "edge-1.example.com",
    "isActive": true,
    "isDraining": false,
    "isHealthy": true,
    "healthFailures": 0,
    "lastHealthyAt": "2026-09-30T12:00:00+00:00",
    "services": [{ "name": "nukevideo_proxy_1", "running": 1, "desired": 1, "state": "running" }],
    "log": null,
    "env": null,
    "lastSeenAt": "5 minutes ago"
  }
}
```

`isActive` is only changed by deploy, start and stop (see [Operations](#operations)).
`lastSeenAt` is human-readable ("5 minutes ago"), not a timestamp.

## Create Node

```
POST /api/nodes
```

**Request Body:**

```json
{
  "name": "worker-us-east-1",
  "ipAddress": "203.0.113.10",
  "user": "root",
  "type": "worker"
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `name` | string | Yes | Display name, unique |
| `ipAddress` | string | Yes | Server IP address, used for SSH |
| `type` | string | Yes | `worker` or `proxy` |
| `user` | string | No | SSH username, a POSIX login name. Defaults to `root` |
| `hostname` | string | No | DNS name the edge is served at. Needed for a proxy to receive playback links |
| `cloudflareProxied` | boolean \| null | No | A proxy's own choice for its [Cloudflare DNS](#cloudflare-dns) record: `true` proxied, `false` DNS only, `null` (the default) follows the installation's setting |
| `accel` | string | No | `intel` or `nvidia` for a GPU worker; omit (or `null`) for CPU |

**Response:** `{ "data": { /* Node */ } }`. Creating a node does not deploy it: that is a
separate [deploy](#deploy-a-node).

## Update Node

```
PUT /api/nodes/{id}
```

`PATCH` works too. Every field is optional: `name`, `user`, `ipAddress`, `hostname`,
`cloudflareProxied`, `accel`,
plus:

| Field | Type | Description |
|-------|------|-------------|
| `isDraining` | boolean | Stop sending new playback links to this proxy (see [Scaling delivery](/guide/nodes#scaling-delivery)) |
| `env` | string | `KEY=value` lines for this node alone, applied at its next deploy on top of the [node environment](#node-environment) |

**Response:** `{ "data": { /* Node */ }, "message": "Node updated successfully" }`.

## Delete Node

Deletes the node and removes its Docker containers from the remote server.

```
DELETE /api/nodes/{id}
```

Answers `409` while the node has an operation queued or running: a deploy finishing after the
delete would recreate the container.

## Validate a Node

```
POST /api/nodes/{id}/validate
```

Runs its checks over SSH: `docker`, `network`, `containers` and `disk`, plus `gpu` (a test encode)
on a node with `accel` and `cache` (the cache pool) on a proxy.

**Response:**

```json
{
  "checks": [
    { "key": "docker", "label": "Docker", "status": "ok", "output": "Docker version 27.3.1 …" },
    { "key": "disk", "label": "Disk Space", "status": "error", "output": "Connection refused" }
  ]
}
```

## Cache Disks

What a deploy would do to a proxy's disks, so they can be picked before deploying (see
[Cache disks](/guide/nodes#cache-disks)).

```
GET /api/nodes/{id}/cache-disks
```

**Response:**

```json
{
  "data": {
    "preselect": true,
    "disks": [
      { "device": "/dev/sda", "size": 4000787030016, "model": "…", "state": "shared", "detail": "mounted at /, with unpartitioned space", "free": 3946026762240 },
      { "device": "/dev/sdb", "size": 4000787030016, "model": "…", "state": "empty", "detail": "", "free": 0 }
    ]
  }
}
```

`state` is `system` (never touched), `nukevideo` (already the cache, mounted as is), `empty` /
`foreign` (formatted into the pool if listed in the deploy's `disks`), or `shared`: a system disk
with `free` bytes still unpartitioned, which gets one partition over that space if listed in
`sharedDisks` and is
otherwise as untouched as `system`. `free` is `0` for every other state. `preselect` is `false` on
a development panel, and never covers a `shared` disk: those go in the deploy's `sharedDisks`,
not in `disks`. A worker answers `{ "preselect": false, "disks": [] }`.

## Bootstrap Command

For a worker the panel cannot reach over SSH (behind NAT, say): a one-line command that installs the
worker when run on the machine itself.

```
POST /api/nodes/{id}/bootstrap-token
```

**Response:** `{ "command": "curl -fsSL \"https://…/api/nodes/1/bootstrap?…\" | bash" }`. Workers
only (`422` for a proxy).

The URL (`GET /api/nodes/{id}/bootstrap`, no auth — it is a signed URL) serves the deploy script.
It is valid for **15 minutes** and **once**: a second fetch answers `410`. The script carries the
instance's credentials in cleartext, so treat the command like a password.

## Node Environment

`KEY=value` lines injected into every node's containers at deploy time. A node's own `env` is
applied on top; variables the deploy owns cannot be overridden by either.

```
GET   /api/node-environment
PATCH /api/node-environment
```

Both answer `{ "data": { "environment": "…", "chunkStoreAddress": "10.0.0.20" } }`. `PATCH` takes
`environment` (required, empty allowed, up to 10000 characters) and, optionally,
`chunkStoreAddress`: the [chunk store](/guide/nodes#chunk-store)'s private `host` or `host:port`,
or empty. Both take effect at each worker's next deploy.

## Operations

Deploy, start and stop run in the background: each call answers `202` at once with the operation
it queued, and `409` when the node already has one queued or running. An operation is an
activity-log entry (`logName: "node"`) whose `properties.status` goes `queued` → `running` →
`succeeded`, `failed` or `cancelled`.

### Deploy a Node

```
POST /api/nodes/{id}/deploy
```

```json
{
  "force": false,
  "disks": ["/dev/sdb"],
  "sharedDisks": ["/dev/sda"]
}
```

`force` skips the drain: a worker's running jobs are killed instead of finished, and redeliver
about 31 minutes later. It does nothing on a proxy. `disks` is required for a production proxy,
`[]` included (see [Cache disks](/guide/nodes#cache-disks)): the spare disks to format whole.
`sharedDisks` is optional and names the `shared` disks that give the pool a partition over their
unpartitioned space; a disk may be in one list only, and the deploy fails without changing
anything if one named there is no longer `shared` when it runs. A successful deploy activates the node.

**Response** `202`:

```json
{
  "data": {
    "id": 42,
    "logName": "node",
    "description": "Deploy worker-01",
    "subjectType": "App\\Models\\Node",
    "subjectId": 1,
    "causerType": "App\\Models\\User",
    "causerId": 1,
    "event": "node_deploy",
    "properties": { "action": "deploy", "force": false, "disks": null, "shared_disks": [], "batch": null, "status": "queued" },
    "createdAt": "2026-09-30T12:00:00+00:00",
    "updatedAt": "2026-09-30T12:00:00+00:00"
  }
}
```

### Deploy Several Nodes

```
POST /api/nodes/deploy
```

```json
{
  "nodes": [1, 2, 3],
  "force": false
}
```

Workers deploy in parallel. Proxies deploy one at a time, and the first one that fails cancels the
rest. A fleet deploy never formats a disk. Busy and stopped nodes are skipped: a stopped node stays
stopped until it is started or deployed on its own. **Response** `202`:
`{ "data": [/* one operation per node */], "skipped": [3] }`. The operations share a
`properties.batch`.

### Start a Node

```
POST /api/nodes/{id}/start
```

Starts the containers of a node that was deployed and stopped, and activates it.

### Stop a Node

```
POST /api/nodes/{id}/stop
```

```json
{ "force": false }
```

Deactivates the node first, so it takes no new work, then stops its containers. A worker's running
jobs are finished first (up to 11 minutes) unless `force` is set.

### List Operations

```
GET /api/node-operations?node={id}
```

Newest first, 20 per page, `node` optional. Admins also see these in the activity log
(`GET /api/activity-log`), as `node_deploy`, `node_start` and `node_stop` events.

### Read an Operation's Output

```
GET /api/node-operations/{operation}/lines?after={n}
```

```json
{ "lines": ["=== Worker image ===", "..."], "next": 57, "status": "running" }
```

Poll with `after` set to the previous `next` until `status` is no longer `queued` or `running`.
Output is kept for seven days.

### Stream an Operation's Output

```
GET /api/node-operations/{operation}/stream
```

The same output pushed as [Server-Sent Events](https://developer.mozilla.org/docs/Web/API/Server-sent_events), which is what the panel uses:

```
id: 57
event: lines
data: {"lines":["=== Worker image ===","..."],"status":"running"}

event: done
data: {"status":"succeeded"}
```

A `lines` event arrives whenever there is new output, at most once a second; its `id` is the cursor. `done` carries the final status, and the stream ends after it: close the `EventSource` there, or it reconnects. The server also ends every stream after 30 seconds, and an `EventSource` reconnects on its own with `Last-Event-ID`, resuming where it stopped (`?after=` does the same for a client that is not a browser).

## SSH Key

The panel connects to every node with one SSH key, kept in the application settings. Install its
public half in `~/.ssh/authorized_keys` of the SSH user on each node.

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/app-settings` | The public key and its fingerprint (`null` before a key exists) |
| `PUT` | `/api/app-settings/ssh-key` | Generate a key, or import one |
| `POST` | `/api/app-settings/ssh-key/rotate` | Swap the fleet to a new key |

### Set the SSH Key

```
PUT /api/app-settings/ssh-key
```

**Request Body:**

```json
{ "privateKey": "-----BEGIN OPENSSH PRIVATE KEY-----\n..." }
```

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `privateKey` | string | No | The private half of a pair you already have. **Omit it (or send `{}`) to have the server generate an Ed25519 pair.** The public key is always derived from the private one, never accepted |

This replaces the current key **without touching the nodes**: use it for the first key, or when
the new public key already reached the nodes by other means (cloud-init, a provisioning tool).
The response is the `GET` shape, with the new `sshPublicKey` to install.

### Rotate the SSH Key

```
POST /api/app-settings/ssh-key/rotate
```

Generates a new key and swaps the fleet to it in the only order that cannot lock the panel out:
the new public key is installed on every node **with the current key**, the new private key is
verified to log in, and only then does the panel switch — after which the old public line is
removed from the nodes. If any **active** node refuses, nothing is switched. An inactive node is
tried but cannot block the rotation, since it may be gone for good.

**Response:**

```json
{
  "data": {
    "rotated": true,
    "nodes": [
      { "id": 1, "name": "worker-1", "ok": true, "error": null },
      { "id": 2, "name": "edge-1", "ok": false, "error": "Connection refused" }
    ]
  }
}
```

::: warning
The private key is stored encrypted and never returned by any endpoint.
:::

## Cloudflare DNS

Optional. With a Cloudflare API token stored, deploying a proxy points its `hostname` at its
`ipAddress` in Cloudflare, and deleting the node removes that record — see
[DNS with Cloudflare](/guide/nodes#dns-with-cloudflare). `GET /api/app-settings` reports it as
`cloudflareConfigured` and `cloudflareProxied`.

| Method | Endpoint | Description |
|--------|----------|-------------|
| `PUT` | `/api/app-settings/cloudflare` | Store the token and the record mode |
| `DELETE` | `/api/app-settings/cloudflare` | Forget the token |
| `GET` | `/api/app-settings/cloudflare/zones` | The zones the stored token can see |

### Set the Cloudflare Token

```
PUT /api/app-settings/cloudflare
```

**Request Body:**

```json
{ "apiToken": "…", "proxied": false }
```

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `apiToken` | string | The first time | Needs `Zone → Zone → Read` and `Zone → DNS → Edit`. Checked against Cloudflare before it is stored; a token Cloudflare refuses is a `422` carrying its reason. Omit it to keep the stored one |
| `proxied` | boolean | No | Create the records behind Cloudflare's proxy instead of DNS only. Defaults to `false`; applies to each proxy at its next deploy. A node's own `cloudflareProxied` overrides it |

The response is the `GET /api/app-settings` shape.

### Remove the Cloudflare Token

```
DELETE /api/app-settings/cloudflare
```

Forgets the token and resets `proxied`. The records already created are left in place.

### List the Zones

```
GET /api/app-settings/cloudflare/zones
```

```json
{ "data": ["example.com", "example.org"] }
```

Asked of Cloudflare on every call, so it doubles as a check that the stored token still works:
a token that no longer does is a `502` with Cloudflare's reason in `message`. Empty when no
token is stored.

::: warning
The token is stored encrypted and never returned by any endpoint.
:::
