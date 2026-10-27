# Edits to released migrations

Released migrations are immutable (P6-38, `MigrationChecksums`): schema changes go in a NEW migration plus a manifest
entry from `php artisan juwri:migrations:checksums`. An in-place edit is allowed only when a new migration cannot do the
job, and every such edit is listed here with its reason. The manifest entries of edited files are refreshed with
`php artisan juwri:migrations:checksums --rebuild`, and the resulting diff must touch exactly the files listed here.

Machine check (P6-171, `MigrationEditsGuardTest`): every `MEn` entry has one `files:` line,
`files: <path>@<sha256>, <path>@<sha256>` (paths relative to `core/`, fingerprint = the new manifest value). The test
compares `core/database/migration-checksums.json` with the frozen `core/database/migration-checksums.baseline.json`:
each released migration whose fingerprint differs from the baseline must be named by an ME entry, and the newest ME
entry naming it must carry exactly the current fingerprint (so a second edit needs a new entry). An ME file that did
not change fails too. New migrations are always allowed: `juwri:migrations:checksums` adds new keys to both files.
Never edit an existing baseline value - that file only ever grows.

## ME1 — P6-135: MariaDB `DROP CHECK` (2026-10-14, schema review lane R)

Files (5):
files: app/Modules/Agents/Database/Migrations/2026_10_12_200000_add_voice_channel_to_message_costs.php@0054bea1e051aa59b3c5c7a4c0dcfe6b65a56036dd6d479c11d5bc81daca33b8, app/Modules/Integrations/Database/Migrations/2026_10_13_100000_add_queued_progress_to_switch_imports.php@8a0b6dbb4967bf333836d0f3e0824cdc8c084c3233fcd78cd5742ded1098d0d7, app/Modules/Menu/Database/Migrations/2026_10_07_100000_add_grocery_to_menu.php@11ff66e1043374f8d09bc95b82be550b5c06b6527789a3dcfd1c7f0c574f2bdb, app/Modules/Ordering/Database/Migrations/2026_10_06_100000_add_hosted_payment_columns_to_orders.php@c1937dad7dfaf65d96e49888f339c1d83f7b052a731871576095c096ccc2db6e, app/Modules/Ordering/Database/Migrations/2026_10_07_100100_create_grocery_ordering_tables.php@02a1b7897338e1837da6cf7aab48359e75a1874a74c459d1e3ac4b9b015a9b43

- `app/Modules/Agents/Database/Migrations/2026_10_12_200000_add_voice_channel_to_message_costs.php` (up)
- `app/Modules/Integrations/Database/Migrations/2026_10_13_100000_add_queued_progress_to_switch_imports.php` (up)
- `app/Modules/Menu/Database/Migrations/2026_10_07_100000_add_grocery_to_menu.php` (down)
- `app/Modules/Ordering/Database/Migrations/2026_10_06_100000_add_hosted_payment_columns_to_orders.php` (down)
- `app/Modules/Ordering/Database/Migrations/2026_10_07_100100_create_grocery_ordering_tables.php` (down)

Why an edit and not a new migration: `ALTER TABLE .. DROP CHECK` is MySQL-only; MariaDB (the usual cPanel database)
rejects it, so a fresh `migrate` on MariaDB stopped half-way inside these files. A later migration never runs when an
earlier one fails, so only fixing the file itself unblocks fresh installs (and rollbacks).

Why it is safe:
- MySQL: `MySqlDialect::dropCheckSql()` returns `ALTER TABLE <t> DROP CHECK <name>` — byte-identical to the old SQL.
- PostgreSQL / SQLite: their branches were not touched.
- Installs that already ran these migrations never run them again; nothing stores fingerprints in the database, so
  there is no runtime drift. Only the repo manifest changed (5 entries, nothing else).
- Guard: `MariaDbMigrationSyntaxTest` fails if a migration spells `DROP CHECK` itself.

Verified on the runner: migrate:fresh + migrate:reset on MySQL 8.4.11, PostgreSQL 18.6, MariaDB 11.4.13; migrate:fresh
on SQLite (SQLite reset fails earlier in an unrelated, unedited Loyalty migration — pre-existing).

## ME2 — P6-162: SQLite `migrate:reset` stops at `dropForeign` (2026-10-14, lane D)

Files (1):
files: app/Modules/Loyalty/Database/Migrations/2026_10_01_719000_add_loyalty_deferred_foreign_keys.php@54589a5fc83817b592025ac48e07b64fb6114b0e838aab2f533abdce581f01de

- `app/Modules/Loyalty/Database/Migrations/2026_10_01_719000_add_loyalty_deferred_foreign_keys.php` (down)

Why an edit and not a new migration: the failure is in this file's own `down()`. A rollback runs the newest migration
first and stops at the first error, so no other migration can step around it; only the file itself can.

Why it is safe:
- `up()` is unchanged, so no install's schema changes.
- MySQL / MariaDB / PostgreSQL: `down()` is byte-identical for them (they still drop `orders_coupon_id_foreign`).
- SQLite: `down()` returns early. SQLite cannot drop one named foreign key (Laravel throws); the constraint goes with
  the `orders` table when its own migration drops it later in the same reset.
- Only the repo manifest changed (this 1 entry, nothing else), rebuilt with `juwri:migrations:checksums --rebuild`.
