# Where toffee keeps its data

Toffee follows Torii's contract for apps with data (`torii_edge_cloud/docs/DATA-APPS.md`).
So it can run as one copy (today) or as several copies on several Pis (when Torii turns it on).

## Where data lives

| What | Where |
|---|---|
| Profiles, posts, reactions, notifications | SQLite: `/data/toffee.db` (WAL mode) |
| Settings: the key that signs sign-in cookies, the phone-notification keys | The `settings` table |
| Who is signed in | A signed cookie (`tf_auth`). Sign out adds a row to `signed_out` |
| Uploaded files | `/data/uploads`, or S3 (Garage) when `TORII_S3_ENDPOINT` is set |

No session files. No cache files. GET and HEAD never write anything.

## Settings

| Setting | Meaning |
|---|---|
| `TOFFEE_DATA` | The data folder (default `/data`) |
| `TORII_ROLE` | `writer`, `reader`, or unset (one copy, as before) |
| `TORII_S3_ENDPOINT`, `TORII_S3_REGION`, `TORII_S3_BUCKET` | Where files go. Unset: `/data/uploads` |
| `TORII_S3_ACCESS_KEY_ID`, `TORII_S3_SECRET_ACCESS_KEY` | The key for that bucket |
| `TORII_FILES_URL` | Public address of the bucket's files |

## What each role does

- **Unset (today):** everything, as before.
- **writer:** everything. Only the writer upgrades tables and sends phone notifications.
- **reader:** opens the database read-only. Answers GETs only. A POST gets 503.

## Files in S3

- Keys look like `u/<user id>/<random>`. Only the key is stored.
- **Public files** (profile pictures, backgrounds, files in public posts):
  `api.php?a=file` sends the browser to `<TORII_FILES_URL>/<key>`.
- **Private files** (in private messages): toffee checks who is asking,
  then passes the file on from S3. The key is never shown.
- Documents are stored as downloads (`application/octet-stream`, `attachment`), never as pages.
- Deleting a post or a profile deletes its files from S3.

## Turning on S3 for files from before

Run once on the writer, with the `TORII_S3_*` settings:

    php tools/move-uploads-to-s3.php

It copies `/data/uploads` to S3 and points the database at the copies.
Safe to run again. Delete `/data/uploads` by hand once all is well.

## Tests

- `php tests/api_test.php`: today's mode. Also checks that a dozen kinds of GET change nothing.
- `php tests/replica_test.php`: a writer and a reader with a local Garage
  (`brew install garage`). Every page works on the reader. Its database isn't touched.
  Files move to Garage, are served from it, and are deleted from it.

## On Torii today (2026-10-24)

- **The database:** Litestream streams it to Garage. Read copies get a fresh copy every ~2 s.
  On a read copy `/data/toffee.db` is swapped for the new copy, so toffee opens it per
  request (read-only) and never keeps a connection open for long.
- **The rest of `/data`** (old uploads, old session files, `vapid.json`): Torii copies changes
  to Garage every 5 s, and read copies pull them.
- **New uploads:** in toffee's own Garage bucket, served from any Pi.
- Cleaner, once: `php tools/move-uploads-to-s3.php` on the writer moves the old uploads into the
  bucket too. Then every file is served the same way.

## Requests to Torii

1. Serve `/_files/` with `X-Content-Type-Options: nosniff` and
   `Content-Security-Policy: sandbox`. Garage doesn't send them.
2. `/_files/` must never list the bucket. (Garage's web endpoint doesn't. Please keep it that way.)
3. Give the reader a database file it can open (not chmod 444). With a file nobody may write,
   SQLite's read-only WAL mode is much slower.

`data: replicated` is not in `.torii.yml` yet. Add it only when Torii says it's ready.
