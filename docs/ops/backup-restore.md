# Backups and restore

Published promise: daily backups, kept 30 days.

## What runs

| What | How | Retention |
|---|---|---|
| Database dump | `php artisan platform:db-backup`, scheduled daily at 02:15 (`app/Console/Kernel.php`). MySQL/MariaDB use `mysqldump`, PostgreSQL uses `pg_dump`. Written to `storage/app/backups/platform/db-YYYY-MM-DD_HHMMSS.sql.gz`, validated (`gzip -t` plus the dump's completion trailer) before it counts. | `BACKUP_KEEP_DAYS`, default 30. Older dumps are deleted after each successful run, locally and in the off-box copy. A failed off-box copy skips the prune. |
| Off-box copy | The same command mirrors to S3 when `AWS_ACCESS_KEY_ID` / `AWS_BUCKET` are set. Without them every dump sits on the disk it protects and an alert says so. | Same 30 days. |
| Uploaded files | `php artisan platform:media-backup`, scheduled 02:45, mirrors uploads to S3 once enabled in Settings. | Mirror, not versioned. |

SQLite installs are skipped by the command: copy the database file instead.

## Encryption (P6-96)

Set `BACKUP_ENCRYPTION_KEY` and every dump (local and off-box) is encrypted after validation with libsodium
secretstream (XChaCha20-Poly1305), streamed in 64 KiB frames. The file keeps its `.sql.gz` name; it starts with a
`JWBK` header holding the format version and a key id (a fingerprint, not the key). Without the key, dumps stay plain
gzip, as before. `ops:preflight --cloud` fails without a key; on self-host it warns.

```bash
php artisan backup:generate-key        # prints BACKUP_ENCRYPTION_KEY=base64:... and its key id
# or: php -r 'echo "base64:".base64_encode(random_bytes(32)), PHP_EOL;'
```

Keep a copy of the key OFF the server (password manager). A lost key means the encrypted dumps are lost too.

Key rotation: put the new key in `BACKUP_ENCRYPTION_KEY` and move the old one into `BACKUP_ENCRYPTION_PREVIOUS_KEYS`
(comma-separated). An old dump restores only with the key it was made with, so keep the old key there at least
`BACKUP_KEEP_DAYS` (and as long as any off-site copy made with it is kept).

P6-96b — the same key and `JWBK` writer also cover:

- **Media mirror** (`platform:media-backup`): with the key set, every file is sealed before upload (streamed, 64 KiB
  frames) on top of S3 SSE. The run stays incremental (the sealed size is exact); files mirrored before the key was
  set are re-uploaded encrypted on the next run. A malformed key stops the run with an alert. Restore:
  `aws s3 sync s3://BUCKET/backups/platform/media /secure/tmp/media`, then
  `php artisan backup:decrypt /secure/tmp/media /secure/tmp/media-plain` (decrypts and authenticates every file with
  the current or previous keys, copies plain legacy files, stops at the first failure), then copy into `storage/`.
- **Per-store backup archives** (Backup & export) and **account exports** ("download all your data"): encrypted at
  rest while they wait on the server; the owner's download decrypts and authenticates into a temp copy that is
  deleted after sending. Wrong/missing key or tampering → HTTP 500 and a reported error, never a partial archive.
  An optional owner password still applies inside the archive (AES zip). Without a key, archives are stored as before.

`ops:preflight --cloud` fails when the media mirror is on and the key is not usable, and warns when the mirror is
off; self-host production warns when media is mirrored without a key.

## Off-box copy (S3 or S3-compatible)

A server that dies takes its local dumps with it, so set a second provider. Everything is in `.env` (never in the
database or the admin pages; Admin → Backup health shows the bucket name only):

```dotenv
AWS_ACCESS_KEY_ID=...            # a key that can only reach the backup bucket
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=us-east-1     # the provider's region
AWS_BUCKET=my-juwri-backups
AWS_ENDPOINT=                    # empty for AWS S3; the provider's URL otherwise (see below)
AWS_USE_PATH_STYLE_ENDPOINT=false  # true for MinIO
BACKUP_S3_SSE=AES256             # server-side encryption header; set it empty if the provider rejects it
```

| Provider | AWS_ENDPOINT | Notes |
|---|---|---|
| AWS S3 | (empty) | SSE-S3 (AES256) works as is. |
| Backblaze B2 | `https://s3.<region>.backblazeb2.com` | Create an application key limited to the bucket. If uploads fail with an encryption error, turn on bucket default encryption and set `BACKUP_S3_SSE=`. |
| Wasabi | `https://s3.<region>.wasabisys.com` | Data is encrypted at rest by default; set `BACKUP_S3_SSE=` if the header is refused. |
| MinIO | `http://host:9000`, plus `AWS_USE_PATH_STYLE_ENDPOINT=true` | SSE-S3 needs a KMS (`MINIO_KMS_SECRET_KEY`); without one set `BACKUP_S3_SSE=`. |

Bucket rules: private (no public ACL, no anonymous policy, "block public access" on), versioning on, and a lifecycle
rule that deletes old versions after a few days so deleted dumps really go (the app already deletes dumps older than
`BACKUP_KEEP_DAYS`). Use a key that can only list, read, write and delete in that bucket. Set `BACKUP_ENCRYPTION_KEY`
too: the dump is encrypted before it leaves the server, so the provider never sees plain data.

What the command checks: the upload must succeed AND the stored object must have the same size as the local dump;
anything else (wrong keys, missing bucket, network cut, encryption header refused) fails the night's run with the
provider's reason, raises a backup alert, and skips retention, so no local or off-box dump is deleted that night.
`ops:preflight` and `juwri:preflight` warn when no target is set; Admin → Backup health shows a warning.

Test the off-box copy, not only the local one:

```bash
php artisan platform:db-restore-check --force --from-s3                 # newest dump in the bucket
php artisan platform:db-restore-check --force --from-s3 --file=backups/platform/db-2026-10-07_021500.sql.gz
```

It downloads the object, checks its size, decrypts it, restores it into the scratch database and verifies it, like
the local drill. Manual restore from the bucket: download the object with your provider's tool, then follow the next
section.

## Restore (database)

Always restore into a new, empty database (or after dropping the old schema).

Encrypted dump first: `php artisan backup:decrypt db-2026-10-07_021500.sql.gz /secure/tmp/plain.sql.gz` (needs the
key it was made with; a wrong key or a modified file fails and writes nothing), then use `plain.sql.gz` below and
delete it after. `head -c 4 FILE` prints `JWBK` for an encrypted dump.

```bash
# MySQL / MariaDB
gunzip < db-2026-10-07_021500.sql.gz | mysql -h HOST -u USER -p DATABASE

# PostgreSQL (create the empty database first; ON_ERROR_STOP stops at the first problem)
gunzip < db-2026-10-07_021500.sql.gz | psql -v ON_ERROR_STOP=1 -h HOST -U USER -d DATABASE

php artisan migrate --force      # only matters when the dump is older than the running release
```

PostgreSQL note: the dump creates the row-level-security helper functions. If the target database already holds them
(for example after `php artisan db:wipe`, which leaves functions behind), the restore stops with "already exists".
Use a fresh database, or `DROP SCHEMA public CASCADE; CREATE SCHEMA public;` first.

## Restore (uploads)

Copy the files back from the S3 mirror (or from your own tar/rsync of the public `storage/` folder) into the public
`storage/` folder, then check one image on a store page.

## Drill

The weekly in-app drill (`platform:db-restore-check`, Sunday 05:00) is ON by default when `APP_ENV=production`
and OFF elsewhere; Settings → General overrides it. Admin → Backup health shows the last result and its time.
`ops:preflight` warns when it is off in production. Encrypted dumps are decrypted and authenticated before anything
loads.


`backup_restore_drill.sh` (maintainer tools) runs the whole cycle on a throw-away test
database, never on production: seed a store with 25 orders and an uploaded file, run `platform:db-backup`, check the
retention (a 31-day-old dump is deleted, a 29-day-old one stays), wipe the database and the file, restore, then compare
the row count of every table, one order's totals and the file's SHA-256. Exit code 0 means it matched.

```bash
bash backup_restore_drill.sh mysql    # or pgsql; run from the app folder (core/)
```

Run it after any change to the backup command, the dump tools or the schema, and before a major release.
