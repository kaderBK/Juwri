---
title: "Queues and jobs"
audience: internal
group: Internal
updated: 2026-08-06
---

# Queues and jobs

How background work runs in the app: the queue driver, the job classes, the no-worker fallback that keeps side effects flowing on cron-only hosts, and the scheduled commands (including the subscription lifecycle tick).

Grounding files: `core/app/Jobs/`, `core/app/Console/Kernel.php`, `core/config/queue.php`, `core/app/Http/Helpers/Helper.php`, `core/app/Providers/AppServiceProvider.php`.

## Queue setup

`config/queue.php` reads the connection from `QUEUE_CONNECTION`, defaulting to `sync` in code but set to `database` in both `.env` and `.env.example`. The `database` connection uses the `jobs` table with `retry_after = 90` and `after_commit = false`. Failed jobs land in `failed_jobs` (`QUEUE_FAILED_DRIVER`, default `database-uuids`).

Tables ship via migrations dated `2026_07_02`: `create_jobs_table`, `create_failed_jobs_table`, `create_job_batches_table`. Run `php artisan migrate` after pulling.

A worker is optional (see the fallback below). On hosts that run a real daemon:

```
php artisan queue:work
```

All job classes live in `App\Jobs` and implement `ShouldQueue`. Almost everything flows through the single `default` queue; the exception is `GenerateStoreBackupJob`, which sets `onQueue('heavy')` (see below). The recommended production worker is therefore `php artisan queue:work --queue=heavy,default`.

### Worker heartbeat

`AppServiceProvider::boot()` registers `Queue::looping(...)`, which calls `queue_worker_stamp_heartbeat()` (in `Helper.php`). A running worker writes the cache key `queue_worker_alive` (current unix time) at most once every 60s, with a 180s TTL. Long-running jobs (`TranslateMenuJob`, `GenerateMenuItemImagesJob`) refresh the same key from inside `handle()` because `Queue::looping` does not fire while a job is executing — without that, concurrent web requests would wrongly conclude no worker exists.

A DEDICATED daemon (`queue:work` **without** `--stop-when-empty`, i.e. the shipped supervisor program) additionally stamps `queue_worker_daemon_alive` — the key ONLY the scheduler fallback drains gate on. The split matters: `queue_worker_alive` is also stamped by the scheduler's own fallback drains, so a drain gating on it would be starved by the *other* drain's heartbeat (2026-09-01 prod incident: the default drain's stamp kept the heavy drain's `when()` gate closed forever, and a `TranslateMenuJob` sat `attempts=0` for 5h). Pinned by `QueueWorkerHeartbeatTest`.

- `queue_worker_alive()` (in `Helper.php`) returns `true` when the connection is `sync`, otherwise `true` only when the cached heartbeat is newer than `time() - 180`. Audience: web requests / `dispatch_job_reliably()`.
- `queue_daemon_alive()` — same freshness rule for the daemon key. Audience: only the fallback drains' `->when(fn () => ! queue_daemon_alive())` gates in `Console\Kernel`.

## No-worker queue fallback

Many hosts (shared hosting, local dev) run only the scheduler cron with no dedicated worker. Two mechanisms keep side effects working there.

**1. `dispatch_job_reliably($job)`** (helper). If a worker heartbeat is live it calls `dispatch($job)` (normal enqueue); otherwise it calls `dispatch($job)->afterResponse()` so the job runs in the same PHP process after the HTTP response is flushed. Used for webhook fan-out and the AI menu jobs:

- `WebhookService::dispatch()` → `DeliverWebhookJob`
- `ApiTokenController` test-ping → `DeliverWebhookJob`
- `GeneratesMenuWithAi` → `GenerateMenuItemImagesJob`, `TranslateMenuJob`

`TranslateMenuJob::handle()` calls `@set_time_limit($this->timeout)` when `PHP_SAPI !== 'cli'`, because in the after-response path the web `max_execution_time` (not the queue `$timeout`) would otherwise kill it mid-run.

**2. Scheduler-drained queue.** `Kernel::schedule()` runs, every minute and `withoutOverlapping()`:

```php
$schedule->command('queue:work --stop-when-empty --max-time=50')
    ->everyMinute()->withoutOverlapping(2)
    ->when(fn () => !queue_worker_alive());
```

The `when()` guard is important: it drains the queue only while no live worker exists. Running this alongside a real daemon would create a second consumer racing the daemon for jobs and interleaving the shared progress-cache writes. So on a cron-only host, queued jobs are picked up within ~1 minute; on a host with a real worker, this scheduled drainer never fires.

The `withoutOverlapping(2)` argument is the self-heal window: the framework default is 1440 minutes, so a drain process killed without releasing its mutex (a deploy restart, an OOM kill, a cron reaper) would otherwise disable this — the only queue consumer on a worker-less host — for 24 hours (WI-H7). Two minutes comfortably clears the 50 s run budget and self-heals within two minutes of a killed run.

A second, separate drainer covers the `heavy` queue (`queue:work --queue=heavy --stop-when-empty --max-time=1800`, same every-minute + no-heartbeat guard, `withoutOverlapping(35)`). The default drain omits `--queue=heavy`, so without it store backups would sit pending forever on cron-only hosts; the 1800 s budget exists so a large backup is not killed mid-run by the default drainer's 50 s cap. Its mutex is 35 minutes — its own 30 min budget plus 5 min headroom — so a killed heavy run self-heals in ~35 minutes rather than a day, while never expiring under a legitimate long run.

## GenerateStoreBackupJob (heavy queue)

`App\Jobs\GenerateStoreBackupJob` (`tries 1`, `timeout 1800`, queue `heavy`) builds a per-restaurant export ZIP for a `StoreBackup` row via `App\Services\Backup\StoreBackupService` (dataset allowlist, secret scrubbing, CSV formula-injection neutralisation, one-row-at-a-time streaming through `DatasetStreamWriter`, try/finally workdir cleanup). One try only — a half-written archive is never marked ready; on failure the row goes `failed` and the transient encryption password (`password_enc`) is cleared. Success emails `BackupReadyMail`. Companion pieces: `StoreImportService` (restore/import), `backups:run-scheduled` (owner backup schedules, `store_backup_schedules`) and `backups:cleanup-store` (retention).

## DeliverWebhookJob

`App\Jobs\DeliverWebhookJob` delivers one webhook event to one endpoint. Constructor args: `endpointId`, `event`, `payload`, `messageId`, `timestamp`. `WebhookService::dispatch()` fans an event out to every active endpoint whose `events` list contains the event (or `*`), giving each its own job and its own `msg_...` id so retries are dedupable by receivers.

- **Retries:** `$tries = 5` (1 initial + 4 retries). `backoff()` returns roughly `[1m, 5m, 15m, 1h]` in seconds, plus a deterministic jitter (0–29s) derived from `md5($messageId)` — deterministic per event, but spread across endpoints during an outage.
- **Signing:** Standard Webhooks. Sends `webhook-id`, `webhook-timestamp`, and `webhook-signature: v1,<sig>` where the signature is HMAC-SHA256 over `{id}.{timestamp}.{body}` (`$endpoint->signStandard(...)`). Legacy `X-Webhook-Event` / `X-Webhook-Signature` headers are kept for compatibility.
- **SSRF guard:** before sending, `SsrfGuard::isSafePublicUrl($endpoint->url)` must pass. A blocked URL records a failed `webhook_deliveries` row and returns without retrying. curl is further hardened with `CURLOPT_FOLLOWLOCATION => false` and HTTPS-only protocols.
- **Every attempt** writes a `webhook_deliveries` row (status, attempt number, truncated response) and calls `$endpoint->recordDeliveryResult(...)`, whose failure streak can auto-disable the endpoint.
- **Sync safety:** it only throws to trigger a retry when `config('queue.default') !== 'sync'` and attempts remain. Under `sync` (or the after-response fallback) a dead endpoint delivers once and never throws, so it can never surface an error to the order request that fired the event.

See the developer webhooks reference in `../dev/api/webhooks.md` and `../dev/api/webhooks-events.md`.

## Campaign and broadcast jobs

Marketing campaigns use a two-stage fan-out (`User\CampaignController`):

- **`SendCampaignJob`** (`$tries = 1`). Dispatched from the controller — immediately, or `->delay($scheduledAt)` for a scheduled send. Guard: it only proceeds when `status === 'scheduled'`, then flips to `sending`. It builds the audience from `customers` with `marketing_consent = true`, applying the `recent` / `lapsed` segment window (30 days on `last_order_at` / `created_at`). It chunks the audience (100 at a time) into `campaign_recipients` rows, then dispatches one `SendSingleCampaignMessageJob` per recipient and marks the campaign `sent`.
- **`SendSingleCampaignMessageJob`** (`$tries = 2`, `backoff = [60, 300]`). Sends the actual email or SMS for one recipient. Re-checks `marketing_consent`, substitutes `{name}`, and generates a signed unsubscribe link via `URL::temporarySignedRoute('campaigns.unsubscribe', now()->addDays(30), ...)`. Email goes through `CampaignMail`; SMS appends "Reply STOP to unsubscribe." and calls `SmsService::send(..., marketing: true)`, which suppresses opted-out numbers. Each outcome updates the recipient status and increments the campaign's `sent_count` / `failed_count`.

Related single-channel senders in `App\Jobs`: `SendSmsJob` (`tries 2`, backoff `[60,300]`), `SendWhatsAppJob` (`tries 2`), `SendPushNotificationJob` (`tries 1`), `SendStaffOrderPushJob`, `CreateNotificationJob` (`tries 3`, backoff `[60,300,900]`), `SendMetaCapiEventJob` (`tries 1`), `SyncInvoiceJob` (`tries 2`). AI/menu jobs: `TranslateMenuJob`, `GenerateMenuItemImagesJob` (both `tries 1`, and both refresh the worker heartbeat while running). Inbound/aggregator jobs: `ProcessWhatsAppWebhookJob`, `ProcessHungerStationOrderJob`. POS: `PushPosOrderJob` (`tries 3`, backoff `[30,120,300]`) — throws on a failed push so the queue retries and an exhausted push lands in `failed_jobs`; idempotent per `(order_id, provider)`, and it never throws under the `sync` driver or on the `provider_unavailable` branch.

## Scheduled commands

Defined in `Kernel::schedule()`. All times are the server timezone. This table lists the load-bearing entries; the Kernel has grown further single-feature crons (abuse-guard flushes, CRM auto-tag, checklists, shifts, feedback, gift cards, etc.) — the file itself is the source of truth.

| Schedule | Command / callback | Purpose |
| --- | --- | --- |
| every minute (guarded) | `queue:work --stop-when-empty --max-time=50` | No-worker queue drainer (only when no live worker) |
| every minute (guarded) | `queue:work --queue=heavy --stop-when-empty --max-time=1800` | No-worker drainer for the `heavy` queue (store backups) |
| 03:00 | `backups:run-scheduled` | Dispatch due owner backup schedules |
| 03:40 | `backups:cleanup-store` | Store-backup retention cleanup |
| hourly | `cron:hourly` | Housekeeping: abandoned checkouts, lapsed plan upgrades |
| hourly | `subscriptions:tick` | Subscription lifecycle tick (below) |
| hourly | `marketing:cart-recovery` | Abandoned-cart recovery sends (the older `whatsapp:cart-recovery` signature is a deprecated BC alias and is **not** scheduled) |
| hourly | `studio:prune` | Menu Studio guest-session purge (48h TTL) |
| daily | `cron:daily` | Housekeeping: refresh the cached sitemaps |
| daily | `cron:cleanup-user-backups` | Prune old user backups |
| 00:00 | `menu:reset-sold-out` | Reset daily sold-out flags |
| 02:00 | `reseller:renew` | Renew reseller subscriptions |
| 02:30 | `AppBillingService::renewDue()` (`apps:renew`) | Renew paid third-party app installs |
| 03:00 | `whatsapp:purge` | Purge WhatsApp data |
| 03:50 | `benchmarks:compute` | Anonymized peer-benchmark cohort medians (owner dashboard tile) |
| 04:10 | `subscriptions:verify` | Read-only dual-write drift check (exits 1 on diff) |
| 04:20 | `ledger:reconcile` | Read-only ledger integrity check (exits 1 on drift) |
| 06:45 | `reseller:invoice` | Wholesale seat invoicing + dunning (self-gated monthly per reseller) |
| 07:00 | `PlatformMetrics::evaluateAlerts()` | Platform alert evaluation |
| 07:10 | `AbuseMonitor::scan()` | Theme abuse / phone-home scan |
| 08:00 Mon | `analytics:send-weekly-digest` | Weekly analytics digest |
| 09:00 | `analytics:send-anomaly-alerts` | Daily anomaly alerts |
| 09:00 | `birthday-offers:send` | Birthday offer sends |
| 10:15 | `addons:auto-renew` | Wallet-funded add-on renewal (#67) |
| 03:00 Mon | `platform:supply-chain-audit` | Weekly dependency audit + SBOM |

The scheduler assumes a system cron entry running `php artisan schedule:run` every minute.

### Subscription lifecycle tick

`subscriptions:tick` (`App\Console\Commands\SubscriptionsTick`) is the plans-rebuild P3 hourly lifecycle engine. It is idempotent and safe to run at any time. Each run, chunking by id (200 rows):

- `trialing` past `ends_at` → expire
- `canceled` past `ends_at` → expire
- `active` past `ends_at` → apply any scheduled downgrade, then wallet auto-renew (opt-in) or transition to `past_due`
- `past_due` past the grace window (`settings.subscription_grace_days`, default 3, min 1) → expire

Every transition emits an audit event and a `subscription.*` webhook (via `WebhookService::dispatch`, so it rides the same `DeliverWebhookJob` path). The dunning ladder (T-7/T-3/T-1 → expiry → grace midpoint → grace end, plus trial T-3) sends `SubscriptionReminderMail` and webhooks, one send per stage stamped in `meta.dunning`; `renew()` clears the stamps so the ladder stops on renewal.

The live subscription's `past_due` state raises a merchant Renew-now banner (M5) that charges the wallet inline or funnels to checkout. `addons:auto-renew` (10:15, #67) runs alongside the tick. The pre-rebuild `dunning:check` (#66) command keyed off an `Upgrade.past_due` status nothing ever wrote — it was removed as dead code (M5).

Related: [Payments](payments.md), [Entitlements](entitlements.md), [Services map](services-map.md).
