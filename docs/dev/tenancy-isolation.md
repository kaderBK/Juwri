# Tenant isolation (P1-04, J29)

juwri is shared-schema multi-tenant: every tenant-owned row carries `account_id` (+ `store_id` / `branch_id`
where it applies). Account → Store → Branch. Two isolation layers:

1. **App scoping** (every database): `AccountScope` on every `BelongsToAccount` model, active only inside a
   tenant context (owner/staff/API requests; see `TenantContext`). The only layer on MySQL/SQLite self-hosts.
2. **PostgreSQL row-level security** (Cloud safety net): policies on `account_id` generated from
   `TenantTables`, so a query that skips the app scope (raw `DB::table`, a join, `withoutGlobalScopes`) still
   cannot read or write another account's rows.

## Row-level security

- Migration `app/Core/Database/Migrations/2026_10_01_900000_enable_tenant_row_level_security.php` calls
  `RowLevelSecurity::sync()`: for every tenant table `ENABLE ROW LEVEL SECURITY` (never `FORCE`) and one policy
  `juwri_tenant FOR ALL USING (…) WITH CHECK (…)`:

  ```sql
  juwri_rls_bypass() OR account_id = juwri_rls_account() [OR account_id IS NULL]   -- IS NULL on nullable-account tables
  ```

  `juwri_rls_bypass()` / `juwri_rls_account()` read the session settings `app.tenant_bypass` / `app.account_id`.
  No-op on MySQL/SQLite and when `TENANCY_RLS=false`.
- `php artisan tenancy:rls status|sync|drop` — status exits 1 when a tenant table is unprotected or FORCEd; run
  `sync` (or add a migration that calls it) after `TenantTables` is regenerated for a new tenant table.
- `TenantDatabaseSession` keeps the two settings equal to the tenant context **before every query** on each
  pgsql connection (`beforeExecuting` hook; the SET is sent only when the wanted state changes):
  - tenant context → `app.account_id = <account>`, bypass off (owner without an account: empty → only
    platform/NULL rows);
  - no tenant context (public storefront, marketplace, platform admin, reseller portal, console, queued jobs
    without a tenant) or `TenantContext::bypass()` → `app.tenant_bypass = on` — the explicit platform flag;
  - a session that never set them (psql, a BI tool, a foreign pooled client) sees **no** tenant rows.

### Why session `set_config` and not `SET LOCAL` (pool safety)

`SET LOCAL` only lives inside a transaction; most queries run outside one, and wrapping every request/job in a
transaction would change locking and `DB::transaction()` semantics everywhere. So the settings are
session-level but reconciled before every query: a reused (Octane / persistent) connection never carries the
previous request's tenant into the next query, a ROLLBACK (which reverts a set_config made inside it)
invalidates the cache, a reconnect gets a new PDO and is re-applied, and the end of a tenant request resets the
session to the fail-closed state. External poolers must use **session pooling** (PgBouncer
`pool_mode = session`, `server_reset_query = DISCARD ALL`); transaction pooling is not supported with RLS.

### Database roles

PostgreSQL never applies RLS to superusers or `BYPASSRLS` roles, nor — without `FORCE` — to the table owner.

| Role | Who | RLS |
|---|---|---|
| `juwri_owner` (owner / migration role) | `php artisan migrate`, ops | exempt (owner, no FORCE) |
| `juwri_app` (application role, non-owner, `NOBYPASSRLS`) | web + queue workers in Cloud | **enforced** |
| optional `juwri_platform` (`BYPASSRLS`) | reporting / support tooling | exempt |

```sql
CREATE ROLE juwri_owner LOGIN PASSWORD '…';
CREATE ROLE juwri_app LOGIN PASSWORD '…' NOSUPERUSER NOBYPASSRLS NOCREATEROLE NOCREATEDB;
-- run migrations as juwri_owner with TENANCY_RLS_APP_ROLE=juwri_app → tenancy:rls sync grants DML
-- (tables + sequences + default privileges) to juwri_app
```

The web/queue `.env` then connects with `DB_USERNAME=juwri_app`; migrations run with the owner credentials.
Self-hosters who connect as the owner keep working unchanged (RLS present but inert).

Cross-account writes the app does on purpose run under an explicit `TenantContext::bypass()`: ownership
transfer re-key (`TenantFill::rekeyStore`), account adoption of store-less rows (`AccountProvisioner`), the
transfer accept/decline/incoming screens. A new owner's first store works in the same request: the provisioner
moves the context to the new account before the store row is inserted.

Scope of the policies: account level only. The staff "own store only" restriction stays an app-layer rule.

Tests: `tests/Modules/Core/Tenancy/RowLevelSecurityTest.php` (PostgreSQL; run with
`DB_CONNECTION=pgsql`) and `RowLevelSecurityDefinitionTest.php` (any DB).

## Tenant-aware infrastructure (slice 7)

| Area | What | Where |
|---|---|---|
| Queue | A job / queued mail / notification dispatched inside a tenant context carries `{account_id, store_ids}` in the Laravel Context payload (hidden key `juwri.tenant`). `JobProcessing` pins it (`TenantContext::actAs`), `JobAttempted` restores the previous context (sync jobs inside a request). Dispatched outside a tenant → unscoped, as before. | `TenantJobs` |
| Platform jobs | `TenantJobs::PLATFORM` always run unscoped (webhooks, platform blog/SEO/themes, reseller, auth/support mail); `TenantJobs::TENANT` restore the dispatcher's tenant. `tests/Architecture/TenantJobsTest` fails on an unclassified queued class. | `TenantJobs` |
| Cache | `TenantCache::store()` / `key()` / `scopedKey()` → `tenant:<account>:<key>`; fails closed outside a tenant without an explicit account. Store-keyed caches of public data (storefront menu, progress keys) keep their store-id keys. Applied to the dashboard notification counts. | `TenantCache` |
| Storage | New tenant uploads that store their full path go under `accounts/<account>/…` (staff documents, expense receipts, proof-of-delivery photos); existing files are not moved. `image_upload()` stores bare filenames rebuilt from fixed per-feature dirs in ~100 places — stays flat until the P1-08 module moves. `TenantStorage::disk()` maps an account's region to a disk (`TENANCY_REGION_DISKS` JSON, J27-A1). | `TenantStorage` |
| Session | The session remembers its account (`juwri.tenant_account`); when the subject's account differs, `tenancy.session.tenant_keys` (selected store/branch, OAuth states) are dropped. | `TenantContext` |
| Rate limits | `owner-api`: per-token budget plus one per-account budget across all of the account's tokens (`TENANCY_OWNER_API_ACCOUNT_RATE`, default 600/min). | `RouteServiceProvider`, `TenantRateLimits` |
| Logs | `account_id` (+ `store_id` for a one-store subject) added to the Laravel Context once the tenant is resolved → on every later log line and carried into jobs; removed when the context ends. On PostgreSQL the DB hook resolves the tenant on the first query after authentication; elsewhere on the first tenant-model query. | `TenantContext` |
| Search | Laravel Scout, database driver (P1-17): menu items + customers use `Juwri\Core\Search\StoreSearchable` — callers search through `searchInStores($term, $storeIds)` (store filter ANDed outside the text OR group; empty list = nothing) or the `whereSearch()` scope on an already store-scoped query; the AccountScope applies because the engine starts from `newQuery()`. An external engine (Meilisearch) must add store/account filters + index sync before a model may use it. | `StoreSearchTest`, `MysqlLaneFullTextSearchTest` |
| Silo DB | `TenantConnectionResolver` (interface) + `SharedDatabaseConnectionResolver` (default). `BelongsToAccount::getConnectionName()` asks it (explicit model connection wins). An Enterprise or Cloud package binds a resolver returning a dedicated connection for enterprise accounts. | `Contracts/Tenancy`, `TenantConnections` |

### Per-tenant queue fairness (design note, not built)

One noisy account must not starve others. Plan: (1) `Queue::route()` (P1-17) sends heavy tenant jobs (imports,
AI images, campaigns, backups) to a `tenant-heavy` queue; (2) a job middleware `RateLimited('tenant-jobs')`
keyed by the carried `account_id` (e.g. N concurrent / M per minute per account, released with backoff);
(3) Horizon `balance=auto` across queues in Cloud. The account key is already on every job payload
(`juwri.tenant`), so this needs no payload change.
