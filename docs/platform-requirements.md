# What toffee needs from Torii Edge Cloud

toffee (app `braincandy-toffee`) is a social app where people share photos, videos, voice notes
and documents, so it needs more room and bigger uploads than a typical Torii app.
Everything below is for toffee only.

## 1. Storage: a persistent volume at `/data`

| What | Why |
|---|---|
| **Persistent**: kept across deploys, restarts and image rebuilds | Holds every post, profile, upload and sign-in. Losing it means losing the app's data. |
| **Mounted at `/data`** (or set `TOFFEE_DATA` to the mount path) | toffee keeps everything under that one folder. |
| **Local disk or block storage, not a network share** (no NFS/SMB) | The database is SQLite, whose file locking isn't safe on network filesystems. |
| **Size: 20 GB to start**, able to grow | Each person may store up to 1 GB of uploads (see below). Expect a few GB per 10 active people; 20 GB gives room for ~50 active users. |
| **Alert at 80% full** | If the disk fills, uploads and new posts fail. |

What's in `/data`:

```
/data/toffee.db (+ toffee.db-wal, toffee.db-shm)  the database (SQLite, WAL mode)
/data/uploads/                                    photos, videos, voice notes, documents
/data/sessions/                                   sign-ins (so a redeploy doesn't sign people out)
/data/vapid.json                                  the key for phone notifications: must never be lost
```

**Run one copy (replica) only.** SQLite and the local files can't be shared between copies.

## 2. Uploads: bigger requests and longer timeouts

The largest file is a 50 MB video, so a single request can be up to **60 MB**.

| Where | Setting needed |
|---|---|
| **PHP** (inside the app) | Already handled by toffee's own `.torii.Dockerfile`: `upload_max_filesize=55M`, `post_max_size=60M`. **Torii must build with that file** (as `admin-phptest` does). |
| **Traefik / ingress** | Request body **at least 60 MB**. If a buffering middleware is used: `maxRequestBodyBytes: 62914560` or more. |
| **Traefik timeouts** | A 50 MB upload on slow mobile data can take minutes. **Read timeout at least 300 s** for toffee (Traefik v3's default `readTimeout` is 60 s). |

Per-file limits in toffee: photos 10 MB, videos 50 MB, voice notes 10 MB, documents 20 MB.
Each person can store **1 GB** in total (`UPLOAD_QUOTA` in `api.php`).

## 3. Compute

| What | Value |
|---|---|
| Memory | **512 MB** for the container (4 PHP workers) |
| CPU | 0.5–1 core is plenty |
| Env | `PHP_CLI_SERVER_WORKERS=4` (set in `.torii.Dockerfile`): 4 requests at once, so one slow upload doesn't block everyone |

## 4. Network

- **HTTPS** with a valid certificate (already in place). It's required for installing the app,
  phone notifications and the service worker.
- Traefik must pass `X-Forwarded-For`, `X-Forwarded-Proto` and `X-Forwarded-Host` (it does by
  default). toffee uses them for sign-in rate limits, secure cookies and link previews.
- **Outgoing HTTPS (port 443)** to the phone notification services:
  `fcm.googleapis.com` (Android / Chrome), `web.push.apple.com` (iPhone),
  `updates.push.services.mozilla.com` (Firefox), `*.notify.windows.com` (Edge on Windows).

## 5. Backups

- Back up **`/data` daily** and keep at least 7 days.
- For a consistent copy of the database while the app runs:
  `sqlite3 /data/toffee.db ".backup /backups/toffee-$(date +%F).db"`, then copy `uploads/`,
  `sessions/` and `vapid.json` as plain files.
- `vapid.json` matters most: if it's lost, every phone has to turn notifications on again.

## Summary for the admin

> For **braincandy-toffee** only: a persistent **20 GB** volume at `/data` (local or block storage,
> not NFS), **one replica**, **512 MB** memory, request bodies up to **60 MB** and a **300 s** read
> timeout in Traefik, outgoing HTTPS to the push services above, daily backups of `/data`, and
> please build with the repo's `.torii.Dockerfile`.
