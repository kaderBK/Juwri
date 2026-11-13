# Accounts that differ only in letter case (P6-98)

Since P6-98, email and username are unique **case-insensitively**:

| Table | Column | Scope |
|---|---|---|
| `users` | `email`, `username` | whole install |
| `staff_members` | `email` | whole install (same as the existing unique) |
| `customers` | `email` | per store |

PostgreSQL / SQLite get functional unique indexes on `lower(col)`. MySQL / MariaDB need nothing new: the
default `utf8mb4_unicode_ci` collation already makes the existing unique indexes case-insensitive. Only if a
column was changed to a case-sensitive collation (`_bin`, `_cs`) does the migration add a stored generated
`<col>_lower` column with a unique index.

## If `php artisan migrate` stops with "differ only in letter case"

Older PostgreSQL / SQLite installs can hold rows such as `Ann@x.com` and `ann@x.com`. The migration
`2026_10_13_200000_make_case_insensitive_identity_indexes_unique` then changes nothing and stops.

1. `php artisan accounts:find-case-duplicates` (or `--json`). It lists each group with ids, created date and
   last login (staff only); it never prints the addresses. Exit code 1 while any group remains.
2. For each group decide by hand, with the account holders if needed: usually keep the most recently used
   account and give the other a different address (or delete it if it is an empty duplicate). Do not merge
   orders, stores or customers by SQL without a backup (`docs/ops/backup-restore.md`).
3. Re-run step 1 until it says "No case-only duplicates found", then `php artisan migrate`.

Until then, sign-in still works: the exact-case address wins; if the typed address matches more than one
account but none exactly, sign-in and password reset are refused with "contact support" instead of picking
one at random.
