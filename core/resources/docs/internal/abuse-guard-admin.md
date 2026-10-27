---
title: "Abuse Guard — Operator Guide (INTERNAL)"
audience: internal
group: Internal
updated: 2026-07-10
---

# Abuse Guard — Operator Guide (INTERNAL)

Super-admin page: **Admin → Abuse Guard** (`/admin/abuse-guard`). Every guard ships in
**warn-only** mode: would-be blocks are logged to the Events tab, nothing is blocked.
Watch the 24h counts, then switch a guard to **Enforce**. `Off` disables logging too.

All settings persist via the Options system (`abuse_*` keys) and read at
`config('settings.abuse_*')`. No `.env`, no new tables.

## Tabs

| Tab | Guards | Key settings |
|---|---|---|
| **Rate Limits** | `ai`, `export`, `upload`, `public_write`, `signup`, `webhook_in`, `mcp` + `branch`, `messaging` | `abuse_<g>_mode`, `abuse_rl_<g>_per_min/_per_hour`; `abuse_branch_orders/waitlist/reservation_per_min` (300/60/60), `abuse_messaging_tenant_daily_cap` (5000), `abuse_rl_webhook_in_max_kb` (64) |
| **AI Spend** | `ai_spend` | `abuse_ai_spend_mode`, `_user_daily_cap` (200), `_tenant_daily_cap` (1000), `_platform_daily_cap` (10000), `_anomaly_multiplier` (10), `_max_input_chars` (40000), `_max_tokens` (2048) |
| **Signup Guard** | `signup` | `abuse_signup_mode`, `abuse_signup_velocity_per_day` (3), `abuse_turnstile_site_key`, `abuse_turnstile_secret_key` |
| **Queue & Uploads** | `queue`, `upload`, `storage` | `abuse_queue_tenant_pending_cap` (10), `abuse_upload_max_mb` (8), `abuse_upload_max_pixels` (25M), `abuse_scan_max_pages` (30), `abuse_storage_tenant_cap_mb` (500) |
| **Events** | — | paginated `abuse_events` (7d), guard filter, per-guard 24h counts; alert thresholds + `abuse_events_retention_days` (90, pruned nightly) |
| **Resource Usage** | — | top-10 tenants per metric, 14-day drill-down, heavy-tenant banner |

## Enforcement points

- **Rate limits:** `ratelimit:<guard>` middleware (warn logs + allows, enforce 429 + `Retry-After`).
- **Branch ceilings:** `branchlimit:orders|waitlist|reservation` — per-restaurant/branch on public writes.
  Defaults HIGH (orders 300/min, waitlist/reservation 60/min). **Public menu READS are never limited.**
- **AI spend:** `App\Services\Ai\AiSpendGuard` in `AiService` — denies via the existing
  `AiLimitExceededException`. BYOK calls bypass caps but are still metered.
- **Signups:** `App\Services\SignupGuard` at partners / reseller / sale-link register
  (disposable email via MailChecker, honeypot `company_url`, velocity, Turnstile seam).
- **Queue:** `App\Services\QueueGuard::allow()` before dispatching heavy jobs.
- **Uploads / storage:** `App\Services\UploadGuard`, `App\Services\StorageQuota`.
- **Abuse hold:** `users.abuse_hold` freezes dashboard writes (`AbuseHoldWall` on the authed
  group) + AI; the public QR menu stays live read-only. Toggle from the admin Users page.

## Metering (Resource Usage)

- `UsageMeter` middleware increments cache counters per tenant/metric (terminate-time, no DB write per request).
- `abuse:usage-flush` (hourly) upserts counters into `tenant_usage_daily` (idempotent).
- `abuse:usage-snapshot` (nightly) records storage_mb / db_rows / queue_jobs.
- Heavy-tenant alerts (share > `abuse_heavy_tenant_pct` 10% of requests, `_ai_pct` 25% of AI)
  fire through `PlatformMetrics::evaluateAlerts()`.

## Alerts (settings)

`abuse_alert_signups_per_hour` (30), `abuse_alert_user_events_per_day` (50),
`abuse_heavy_tenant_pct` (10), `abuse_heavy_tenant_ai_pct` (25).

See also `abuse-runbook.md` for the infra layer (Cloudflare, PHP-FPM, MySQL, fail2ban, heavy-queue worker).
