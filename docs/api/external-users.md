# External Users

An external user is the `externalUserId` your integration uploads with — your own customer, user or
tenant id. NukeVideo keeps no record of them: the id lives on each video and on every usage row it
caused, and these endpoints read it back from there.

Every endpoint here **requires project context** (`X-Project-Ulid`, or a project API key). The id is
yours and unique per project only: two projects may use the same id for different people, so it is
never read across projects.

An id containing `/` is fine in the path: `GET /api/external-users/tenant/7/user`.

## List External Users

Every id the project's videos carry, with how many videos it has and what they hold.

```
GET /api/external-users
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `search` | string | A prefix of the id. `%` and `_` are taken literally |
| `sort` | string | `last_upload_at` (default), `external_user_id`, `videos` or `storage_bytes` |
| `direction` | string | `desc` (default) or `asc` |
| `page`, `perPage` | integer | Pagination. `perPage` defaults to 25, maximum 100 |

```json
{
  "data": [
    { "externalUserId": "user-42", "videos": 18, "storageBytes": 5368709120, "lastUploadAt": "2026-04-16T10:30:00+00:00" }
  ],
  "currentPage": 1,
  "perPage": 25,
  "total": 1
}
```

Read from the videos: an id whose videos were all deleted drops out of the list, while its past
usage stays reportable through the usage endpoints below.

## Get External User

Everything the project knows about one external user, in one read.

```
GET /api/external-users/{externalUserId}?from=2026-04-01&to=2026-04-30
```

`from` and `to` are required and bound the usage only; `videos` and `storage` are what exists now.

```json
{
  "data": {
    "externalUserId": "user-42",
    "videos": { "total": 18, "byStatus": { "completed": 17, "failed": 1 } },
    "storage": { "totalBytes": 5368709120, "servedBytes": 4294967296, "sourceBytes": 1073741824 },
    "usage": {
      "from": "2026-04-01",
      "to": "2026-04-30",
      "deliveredBytes": 943718400,
      "uniqueIps": 37,
      "uniqueTrackingIds": 12,
      "metrics": [
        { "metric": "streaming_bytes", "unit": "bytes", "value": 891289600, "requests": 10422 },
        { "metric": "download_bytes", "unit": "bytes", "value": 52428800, "requests": 3 },
        { "metric": "upload_bytes", "unit": "bytes", "value": 1073741824, "requests": 18 },
        { "metric": "encoding_cpu", "unit": "seconds", "value": 1820.5, "requests": 240 }
      ]
    }
  }
}
```

| Field | Meaning |
|-------|---------|
| `storage.totalBytes` | Everything the videos hold: packaged renditions plus any kept file |
| `storage.servedBytes` | What playback serves: the packaged renditions |
| `storage.sourceBytes` | The originals as uploaded, while they are kept |
| `usage.deliveredBytes` | Every delivery metric added up |
| `usage.uniqueIps`, `usage.uniqueTrackingIds` | Distinct viewers across that delivery — one viewer who streamed and downloaded counts once |

An id nothing carries answers with zeros, not a `404`: there is no record to be missing.

## Usage for Many External Users

What each named external user consumed, by metric — the read to bill your own customers from, one
request for all of them.

```
POST /api/external-users/usage
```

```json
{
  "from": "2026-04-01",
  "to": "2026-04-30",
  "externalUserIds": ["user-42", "user-43"],
  "granularity": "total"
}
```

| Field | Type | Notes |
|-------|------|-------|
| `externalUserIds` | string[] | Required, up to 1000 |
| `metric` | string | One metric only. Omit for all of them (`origin_bytes` excluded: it carries no customer) |
| `granularity` | string | `total` (default) or `daily`, which adds `date` to every row |

```json
{
  "data": [
    { "externalUserId": "user-42", "metric": "streaming_bytes", "unit": "bytes", "value": 891289600, "requests": 10422, "date": null },
    { "externalUserId": "user-42", "metric": "encoding_cpu", "unit": "seconds", "value": 1820.5, "requests": 240, "date": null }
  ]
}
```

An id with no usage in the range has no row. The unit follows the metric: never sum across metrics.
`GET` works too, with the same fields as query parameters.

## Delete an External User's Videos

Deletes every video of the external user — what to call when one of your users closes their
account. Destructive and irreversible, so the body must repeat the id from the path.

```
DELETE /api/external-users/{externalUserId}/videos
```

```json
{ "confirm": "user-42" }
```

**Response** `202 Accepted`:

```json
{ "data": { "deleting": 17, "inProgress": 1 } }
```

The deletion is queued. Each video goes through the same path as [Delete Video](/api/videos#delete-video),
`video.deleted` webhook included. Videos still being processed are left alone and counted in
`inProgress`: call again once they finish. The usage already recorded for the id is kept — it is
what was consumed, and billing for it stays possible.
