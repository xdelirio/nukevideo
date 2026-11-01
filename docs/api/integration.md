# Integrating

NukeVideo is built to sit behind another backend. This page is the map: what your integration can
read and do, and where. Everything here works with a [project API key](/api/authentication), which
authenticates as the project and narrows every read to it.

## Identify your own things

Two ids of yours ride along on every upload ([upload metadata](/api/videos#upload-metadata)) and come
back on every read:

| Id | What it is for |
|----|----------------|
| `externalUserId` | Your customer, user or tenant. Groups videos and usage per customer |
| `externalResourceId` | Your own record for the video. Turns on the `video.*` webhooks for it |
| `trackingId` | Set per playback or download link. Groups delivered bytes per viewer |

## Where each piece of data lives

| You want | Endpoint |
|----------|----------|
| The catalogue, filtered by customer, status, name | [`GET /api/videos`](/api/videos#list-videos) |
| What changed since your last poll | [`GET /api/videos?updatedSince=…`](/api/videos#keeping-a-copy-in-sync) |
| One video: outputs, streams, sizes, template version | [`GET /api/videos/{ulid}`](/api/videos#get-video) |
| Status changes as they happen | [Webhooks](/api/webhooks) |
| Playback and download links | [Playback URLs](/api/videos#playback-urls), [Download Tracks](/api/videos#download-tracks) |
| Your customers, and what each holds | [`GET /api/external-users`](/api/external-users#list-external-users) |
| Everything about one customer | [`GET /api/external-users/{id}`](/api/external-users#get-external-user) |
| Usage to bill, per customer | [`POST /api/external-users/usage`](/api/external-users#usage-for-many-external-users) |
| Usage to bill, per viewer (tracking id) | [`POST /api/analytics/tracking-ids`](/api/analytics#batch-bandwidth-by-tracking-id) |
| Bytes per video | [`POST /api/analytics/videos`](/api/analytics#batch-bandwidth-by-video) |
| Storage the project holds | [`GET /api/storage`](/api/storage) |
| Any breakdown: by day, video, country, tracking id… with requests and distinct viewers | [`POST /api/metrics`](/api/analytics#metrics-query) |
| The dashboard figures, whole or per panel | [Delivery Dashboard](/api/analytics#delivery-dashboard) |
| One person behind many accounts | [Shared addresses](/api/analytics#shared-addresses), [Addresses of a tracking id](/api/analytics#addresses-of-a-tracking-id) |
| What happened to the project's videos | `GET /api/activity-log` |

## Act on your things

| You want to | Endpoint |
|-------------|----------|
| Upload | [S3 multipart upload](/api/videos#s3-multipart-upload) |
| Rename, relabel, re-prioritise | [`PATCH /api/videos/{ulid}`](/api/videos#update-video) |
| Retry a failed video | [`POST /api/videos/{ulid}/retry`](/api/videos#retry-video) |
| Add an audio or subtitle track to a published video | [Add a Track](/api/streams#add-a-track) |
| Delete one video | [`DELETE /api/videos/{ulid}`](/api/videos#delete-video) |
| Delete everything of a customer who left | [`DELETE /api/external-users/{id}/videos`](/api/external-users#delete-an-external-user-s-videos) |

## What is not here

- **The operator's infrastructure** — nodes, edge caches, origin egress. It is the operator's, and
  lives on the admin-only endpoints.
- **User agents, ASNs, timestamps finer than a day.** The edge does not keep them: usage is folded
  into one row per day and dimension.
