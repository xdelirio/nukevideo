# Storage

What the project's catalogue holds on storage now, summed live from its streams. Requires project
context.

```
GET /api/storage
```

```json
{
  "data": {
    "videos": 1240,
    "storage": { "totalBytes": 32347270441222, "servedBytes": 29001234567890, "sourceBytes": 3346035873332 },
    "byStatus": [
      { "status": "completed", "videos": 1201, "storage": { "totalBytes": 31000000000000, "servedBytes": 29001234567890, "sourceBytes": 1998765432110 } },
      { "status": "failed", "videos": 39, "storage": { "totalBytes": 1347270441222, "servedBytes": 0, "sourceBytes": 1347270441222 } }
    ]
  }
}
```

The three figures mean the same as on [an external user](/api/external-users#get-external-user):
everything held, what playback serves, and the originals while they are kept. Failed videos still
holding their originals are usually what is worth seeing in `byStatus`.

Per external user, the same figures are in [Get External User](/api/external-users#get-external-user).
