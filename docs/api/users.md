# Users

Manage user accounts. All user management endpoints require **admin** privileges, and a project
API key gets `403` on them even when the project's owner is an admin.

## List Users

```
GET /api/users
```

**Response:**

```json
{
  "data": [
    {
      "id": 1,
      "name": "Admin",
      "email": "admin@nukevideo.local",
      "isAdmin": true,
      "projects": null
    }
  ]
}
```

## Get User

```
GET /api/users/{id}
```

Returns `{ "data": { ... } }` with the same user object.

## Create User

```
POST /api/users
```

**Request Body:**

```json
{
  "name": "New User",
  "email": "user@example.com",
  "password": "secure_password",
  "isAdmin": false
}
```

`password` needs at least 8 characters and `email` must be unused. `isAdmin` is optional and
defaults to `false`.

## Update User

```
PUT /api/users/{id}
```

**Request Body:**

```json
{
  "name": "Updated Name",
  "email": "updated@example.com",
  "isAdmin": true
}
```

Every field is optional, and `password` (at least 8 characters) can be sent here too to reset it.

## Delete User

```
DELETE /api/users/{id}
```

::: warning
Deleting a user deletes **everything they own**: each of their projects, with its videos (and their
files in storage), templates and API key. It responds `409` while any of their videos is still
processing, and `403` if you try to delete yourself.
:::

## Activity Log

Get the activity log of the current project — it needs project context (`X-Project-Ulid`, or a
project API key) and responds `400` without it. Unlike the rest of this page it is not admin-only:

```
GET /api/activity-log
```

This returns the events recorded against the project's videos (queued for processing, failures,
and so on), newest first, 20 per page (`?page=`). An admin also sees the node operations, which
belong to no project.

Two filters narrow it, alone or together:

| Parameter | Description |
|-----------|-------------|
| `event` | Only this event, as recorded: `video_failed`, `thumbnail_failed`, `storyboard_failed`… |
| `video` | Only the events of this video, by its ULID. Node operations are left out, being no video's. |

Why a video failed is recorded on its `video_failed` event, in `properties.reason`, so
`?event=video_failed&video={ulid}` answers that for one video. URLs in a reason are replaced with
`<url>`, and a failed command is reduced to its program name.

A response:

```json
{
  "data": [
    {
      "id": 42,
      "logName": "video",
      "description": "Video queued for processing: intro.mp4",
      "subjectType": "App\\Models\\Video",
      "subjectId": 7,
      "causerType": "App\\Models\\User",
      "causerId": 1,
      "event": "video_processing_started",
      "properties": {},
      "createdAt": "2026-07-13T00:00:00+00:00",
      "updatedAt": "2026-07-13T00:00:00+00:00"
    }
  ],
  "currentPage": 1,
  "perPage": 20,
  "total": 1
}
```
