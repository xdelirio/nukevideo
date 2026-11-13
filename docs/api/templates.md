# Templates

Templates define encoding configurations for videos. See the [Templates guide](/guide/templates) for more details.

## List Templates

```
GET /api/templates
```

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `enabled` | boolean | Only templates in that state. Omit it to list all of them, retired included. |

Pass `enabled=true` when building an upload picker: those are the templates the upload endpoints
still accept.

**Response:**

```json
{
  "data": [
    {
      "ulid": "01HX...",
      "name": "720p + 1080p",
      "query": { "outputs": [ ... ] },
      "enabled": true,
      "keepProcessedFiles": true,
      "keepOriginal": false,
      "commands": ["ffmpeg -hide_banner -y -i \"input\" -c:v libx264 -vf scale=1920:1080 -crf 23 ... \"output\""],
      "createdAt": "2025-01-15T10:30:00+00:00",
      "updatedAt": "2025-01-15T10:30:00+00:00"
    }
  ]
}
```

`query` is passed through as stored, so its keys stay snake_case (`video_codec`, `audio_codec`).
`commands` is a read-only preview of the FFmpeg command each variant renders to, one per variant.
It is illustrative: the pipeline builds its real commands per chunk and per track.

Templates come back in the order stored for the project (see [Reorder](#reorder-templates)), not
newest first.

## Get Template

```
GET /api/templates/{ulid}
```

Returns one template under `data`, in the same shape as a listing row.

## Create Template

```
POST /api/templates
```

**Request Body:**

```json
{
  "name": "My Template",
  "keepProcessedFiles": false,
  "query": { "outputs": [ ... ] }
}
```

| Field | Type | Notes |
|-------|------|-------|
| `name` | string | **Required.** Up to 255 characters. |
| `query` | object | **Required.** `outputs` needs 1 to 10 entries, each with a `video_codec`, 1 to 20 `variants` and an `audio` object with an `audio_codec` and at least one `channels` entry. The codec is set on the output and nowhere else: a variant carrying `video_codec` or `audio_codec`, or a `channels` entry carrying anything but `channels` and `audio_bitrate`, is a `422`. Every parameter is validated against the codec's catalogue (see [Encoding Configuration](#encoding-configuration)). The structure is described in the [Templates guide](/guide/templates#template-structure). |
| `enabled` | boolean | Optional, default `true`. |
| `keepProcessedFiles` | boolean | Optional, default `true`. |
| `keepOriginal` | boolean | Optional, default `false`. |

Returns the new template under `data`.

## Update Template

```
PUT|PATCH /api/templates/{ulid}
```

**Request Body:**

```json
{
  "name": "Updated Template",
  "query": { "outputs": [ ... ] }
}
```

Same fields as [Create](#create-template), all optional on both verbs: only what you send is
changed. A `query` that is sent is validated in full and replaces the stored one. Changing a template
does not re-encode the videos already processed with it. Returns `message` and the updated template
under `data`.

### Enable / Disable

A disabled template stays in the list and keeps serving the videos already encoded with it, but new
uploads may no longer select it — the upload endpoints reject it. This is how a template that videos
reference (and therefore cannot be deleted) is retired.

```
PATCH /api/templates/{ulid}
```

```json
{ "enabled": false }
```

## Duplicate Template

Create an independent copy, named `<name> (copy)` (then `(copy 2)` and up), with the same query,
`enabled` state and retention flags. It is placed at the end of the order:

```
POST /api/templates/{ulid}/duplicate
```

## Reorder Templates

Store the order templates are listed in for this project. Send the **complete** list of ULIDs in the
order you want; every ULID must belong to the calling project, or the request is rejected with a
`422` and nothing is written.

```
POST /api/templates/reorder
```

**Request Body:**

```json
{
  "ulids": ["01HX...", "01HY...", "01HZ..."]
}
```

**Response:** the full template list in the new order.

## Delete Template

```
DELETE /api/templates/{ulid}
```

Refused with a `422` while any video references the template. Disable it instead.

## Presets

### List Presets

Get built-in encoding presets:

```
GET /api/template-presets
```

Each preset has a `slug`, `name`, `description`, `category` and `query`.

### Adopt Preset

Create a template from a preset:

```
POST /api/template-presets/{slug}/adopt
```

Returns the new template under `data`. An unknown slug is a `404`.

## Encoding Configuration

Get the codec catalogue and every encoding parameter a template can use:

```
GET /api/templates-config
```

Returns `data.codecs` (each with `codec`, `type`, `label`, `family`, `protocols`, and `accel` for GPU
encoders) and `data.parameters`, keyed by the parameter name used in `query` (`crf`,
`audio_bitrate`, …), each with its `type`, `inputType`, `label`, options or bounds, validation
`rules` and the codecs it is `availableFor`.
