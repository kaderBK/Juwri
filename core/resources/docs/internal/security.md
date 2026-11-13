---
title: Security posture
audience: internal
group: Cross-cutting
updated: 2026-08-06
verified_against: e09d26f8
---

# Security posture

Engineering reference for the platform's security controls: authentication surfaces, two-factor auth, rate limiting and captcha, HTML sanitization, the storefront content security policy, the BYOK credentials vault, impersonation auditing, and webhook signing. Every claim here is grounded in code under `core/app/`. Related reading: [architecture](architecture.md), [entitlements](entitlements.md), [payments](payments.md).

## Authentication surfaces

The app carries several distinct auth surfaces, each with its own guard or token middleware. All bearer tokens are stored as SHA-256 hashes — the raw token is only ever seen at issue time and on each request; the DB never holds a reusable secret.

- **Owner/admin web** — Laravel session guard `web` (`Auth\LoginController`). Login accepts a `username`/`email` + `password`. `CheckUserIsBanned` logs out and redirects any `status === 0` account on each request; `AdminMiddleware` gates admin routes on `User::isAdmin()`.
- **Staff dashboard** — session guard `staff` (`Auth\StaffLoginController`). `EnsureStaffDashboardPermission` maps each route name to a required permission (`orders`, `menu_edit`, `customers`, `analytics`, `kds`, `coupons`) and also verifies the staff member can access the target restaurant/order via `dashboard_can_access_*`.
- **Public API tokens** — `ApiTokenAuth` (`App\Models\ApiToken`), keyed by `hash('sha256', $raw)`, with per-token ability checks (`read`/`write`, 403 on missing ability) and `last_used_at` tracking. Parallel per-surface token middlewares exist: `CustomerTokenAuth`, `StaffTokenAuth`, `DriverTokenAuth`, and `StorefrontTokenAuth` (scope-checked, plan-gated: 403 if the plan lacks the storefront API).
- **Installed apps** — `VerifyAppSessionToken` validates a JWT bearer, resolves the app from the token audience (client id), and 401s (never redirects) on any failure since these are XHR calls.
- **Client-identity stripping** — token middlewares use the `StripsClientIdentity` concern so a client cannot spoof server-controlled identity attributes; the resolved user/token is attached via `$request->attributes`, not request input.

Additional middleware:

- **`ImpersonationGuard`** — enforces a hard time cap on admin impersonation
  sessions; no-op when no impersonation is active.
- **`ResellerApiAuth`** — authenticates reseller API calls via bearer token
  (SHA-256 hashed). 401 on missing/invalid token; 403 on suspended/inactive
  reseller.
- **`ResolveCustomDomain`** — detects a reseller custom domain on the incoming
  request and binds the matching reseller context; non-matching hosts pass
  through.

Auth routes live in `core/routes/auth.php`; impersonation and admin actions in `core/routes/web.php`.

## Two-factor authentication

TOTP 2FA for owner/admin web accounts. `Services\TwoFactorService` wraps `pragmarx/google2fa`; the controller is `Auth\TwoFactorController` and routes are under `two-factor/*` in `core/routes/auth.php`.

- **Enrollment** is opt-in. `enable` mints a secret plus ten single-use recovery codes (`XXXXXXXX-XXXXXXXX`) held on the `User` model; `confirm` requires a valid first code before setting `two_factor_confirmed_at`. Secrets and recovery codes are stored encrypted on the user.
- **Login challenge** — after a correct password, `LoginController::authenticated` suspends the session login and diverts to `2fa.challenge` when `hasTwoFactorEnabled()` and the device is not remembered. The pending user id is stored in the session alongside a corroborating key `hash('sha256', id.'|'.email)`, so a tampered session id cannot redirect the challenge to another user (`verifyChallenge` uses `hash_equals`).
- **Verification** accepts a 6-digit TOTP (±1 step for clock drift) or a recovery code. Recovery-code matching is constant-time (`hash_equals` per code) and single-use.
- **Remember device** — an optional `2fa_remember` cookie (encrypted `id|sha1(secret)`, 30-day window) skips the challenge. It is bound to the current secret, so re-enrolling or disabling 2FA invalidates it; `disable` also forgets the cookie and requires the current password.
- The enrollment QR is rendered as inline SVG (`getQRCodeInline`) — no external request, CSP-safe.

The 2FA challenge-verify route is throttled with `auth-login`.

## Rate limiting and captcha

Named limiters are defined in `Providers\RouteServiceProvider::configureRateLimiting` and applied via `throttle:<name>` middleware in the route files. Login/register/password limiters key on a normalized email/username **combined with** IP (`key|ip`) so neither an attacker rotating emails nor a shared-NAT office is unfairly blocked.

| Limiter | Limit / min | Applied to |
| --- | --- | --- |
| `auth-login` | 6 | `POST login`, `POST staff/login`, 2FA challenge verify |
| `auth-register` | 3 | `POST signup` |
| `auth-password-email` / `auth-password-reset` | 5 | password reset flow |
| `auth-verification` | 6 | email verification resend |
| `public-form` | 12 | contact, feedback |
| `public-check` | 30 | coupon apply, review/NPS, order lookup, item events |
| `newsletter` | 8 | newsletter subscribe |
| `restaurant-order` | 10 | `restaurant.submitOrder` (keyed by restaurant + IP) |
| `restaurant-waiter` | 6 | `restaurant.callTheWaiter` |
| `api` | 60 | authed API (per user, else IP) |
| `storefront` | `config('api.storefront_rate', 120)` | Storefront API, per-token bucket |
| `owner-api` | `config('api.owner_rate', 120)` | Owner token API, keyed per SHA-256 hash of the raw bearer/query token (A1; auth runs per-route *after* the throttle, so token-hash keying is order-independent), IP fallback |
| `reseller-api` | `config('api.reseller_rate', 60)` | `/api/v1/reseller` endpoints, same per-token-hash keying (A1), IP fallback |
| `agent-stt` | 10 + 30 | storefront voice-input STT (`agent.stt`), dual buckets: 10/min per browser session (IP fallback) and a 30/min per-IP ceiling |
| `studio-export` / `studio-fit` | 10 | Studio export/fit, keyed per route family |

Order/waiter limiters key on restaurant id + IP so a busy dining room does not starve a neighbor. Captcha is Google reCAPTCHA via `anhskohbo/no-captcha`, wired through the `validate_recaptcha()` helper: when `settings.recaptcha_mode` is on, it injects `g-recaptcha-response => required|captcha` into the login/register/password validators. It is an admin toggle (Settings), off by default in dev.

## Rich text sanitization

The single output-time sanitizer choke point is `App\Support\Html::clean($html, $profile)` (also the `@clean` Blade directive). Raw `{!! ... !!}` echoes of user/DB HTML outside this class are banned by the guardrails. It delegates to `stevebauman/purify` (HTMLPurifier); profiles are in `core/config/purify.php`:

- **`default`** (inline formatting only) — for short user text like menu-item and restaurant descriptions. Allows `b,strong,i,em,u,s,del,br,p,ul,ol,li,span,a[href|title]`. No headings, images, or styles.
- **`richtext`** — for long-form owner content (bios, blog posts). Adds `h1–h6`, `blockquote`, `img[src|alt|width|height]`, and `pre[dir]/code` (shell snippets in RTL prose), plus a small CSS allowlist (font/color/text-align).

Both profiles forbid `script,style,iframe,object,embed,form,input,button`, force `nofollow`, and restrict URI schemes to `http/https/mailto/tel`. HTMLPurifier strips `on*` handlers and disallowed elements regardless.

Custom CSS blobs echoed into a storefront `<style>` go through the separate `sanitize_css()` helper (`Helper.php`), which strips tag breakouts, `@import`, `expression()`, `behavior:`, `-moz-binding`, and dangerous `url()` schemes — defense-in-depth alongside the per-tenant `custom_code_enabled` gate and the CSP.

## Content security policy

`App\Support\Csp\StorefrontPolicy` (juwri's own small CSP layer in `App\Support\Csp`, which replaced spatie/laravel-csp in P1-00a) is a nonce-based policy scoped to the public storefront only — admin and dashboard are untouched. It is attached to the `{slug}` publicView route via the `App\Http\Middleware\AddCspHeaders` middleware.

Directives: `base`/`default`/`connect`/`form-action`/`frame-ancestors`/`script` are `'self'`; `object` is `'none'`; `img`/`font` add `data:` and `https:`; `media`/`style` add `https:`. Nonces are added for `script` and `style` (no `unsafe-inline`).

**Enforce-safe rollout.** `config/csp.php` reads `CSP_ENFORCE`: when false (default) the policy rides **report-only** (violations collected at `/csp-report`, or `CSP_REPORT_URI`) so a bad policy never breaks a live menu; when true it enforces. Every inline `<script>`/`<style>` in the post-template themes carries `nonce="{{ csp_nonce() }}"` (per the policy docblock, 151 tags across 42 files), guarded by `CspEnforceTest` against regression — the storefront is enforce-ready across all themes. Remaining owner steps: flip `CSP_ENFORCE=true`, do a per-theme browser pass under enforce, and widen the allowlist for any legitimate third-party embed (payment SDK, maps) found in the report window.

## BYOK credentials vault

`App\Models\Credential` is the encrypted store for external API keys. The `feature` column is free-form (not a fixed enum); values seen in code include `ai`, `sms`, `storage`, `whatsapp`, `hungerstation`, `accounting`, and the reseller-scoped `reseller_gateway`. `secrets` uses the `encrypted:array` cast, so values are encrypted at rest with `APP_KEY`; the settings UI masks existing keys on read (`••••`) and preserves the stored value when a masked field is resubmitted.

Resolution is `Credential::for($feature, $storeId)`: an **active merchant (restaurant) override wins**, else the active platform default (`store_id IS NULL`), else null. A separate `Credential::forReseller($feature, $resellerId)` resolves reseller-scoped rows (e.g. `reseller_gateway`) which carry `reseller_id` and a null `store_id` so they never collide with per-restaurant credentials. All external features call this resolver rather than reading raw config, and when an owner supplies their own key their usage bypasses platform metering for that feature (their key, their quota). Rotating `APP_KEY` invalidates every stored secret — coordinate with the owner.

## Impersonation audit

Admin "log in as user" (`Admin\UserController::loginAsUser`, route `admin.users.login`) writes an `admin.impersonate` entry via `log_activity()` **before** the auth switch, so the actor is recorded as the admin (not the impersonated user). It stores `admin_id`, `target_id`, `target_name`, and IP, then stashes the admin id in `quick_admin_user_id`. On `logout`, `LoginController` restores the admin session and writes `admin.impersonate_exit`. The admin must give a reason (5–200 characters, stored in the audit row); another admin account cannot be opened this way; `AdminImpersonationGuard` signs the admin back in as themself after 60 minutes (`admin.impersonate_expire`) and blocks the same sensitive routes as reseller impersonation (`ImpersonationGuard::blocks`).

`log_activity()` (`Helper.php`) inserts into `activity_logs` (`ActivityLog`) with `actor_user_id`, `store_id`, `action`, polymorphic subject, `meta`, and IP. It is wrapped in try/catch so audit failures never break the primary flow — the same helper backs credential-change and other security-sensitive audits.

## Staff HR document storage

Staff HR documents (IDs, contracts, health certs) are private data and must never be reachable through the public `storage/` symlink. `User\StaffHrmController::uploadDocument` stores new uploads on the private `local` disk (`staff-docs/<store_id>/...`), and the only read path is the authenticated route `staff.hrm.documents.download` (`GET dashboard staff/{id}/hrm/documents/{docId}/download`, `feature:hrm_suite`-gated) served by `downloadDocument` — the file is streamed after the dashboard auth/ownership checks, never linked directly.

Uploads made before the private-disk fix landed under `storage/app/public` (symlinked into the webroot, no auth). The artisan command `storage:migrate-staff-docs` (`Console\Commands\MigrateStaffDocsPrivate`) relocates those legacy files to the private disk. It is a **dry-run report by default**; pass `--move` to actually relocate. The stored `path` is disk-relative either way, so the download route keeps working, and `downloadDocument` falls back to the legacy public location for rows not yet migrated.

## Transport security headers

The root `.htaccess` (the load-bearing control on the cPanel/Apache prod stack — see [deploy runbook](deploy-runbook.md)) sets the baseline response headers: `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, and HSTS via `Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"` guarded by `expr=%{HTTPS} == 'on'` — the header is only emitted on HTTPS responses (per spec it is ignored over HTTP anyway), so it is safe to set unconditionally. Max-age is one year with `includeSubDomains`; no `preload` token, so the domain is not committed to browser preload lists.

## Webhook signing

Outbound webhooks (`Services\WebhookService` → queued `Jobs\DeliverWebhookJob`) follow the Standard Webhooks convention. Each event fans out to every matching active `WebhookEndpoint` owned by the user; each endpoint gets its own job so a dead endpoint never blocks the triggering request.

- **Signature** — `WebhookEndpoint::signStandard($id, $timestamp, $body)` is base64(HMAC-SHA256) over `{id}.{timestamp}.{body}` with the endpoint secret. Sent as `webhook-signature: v1,<sig>` with `webhook-id` and `webhook-timestamp` headers. Binding id + timestamp into the signed content defeats replay; receivers should reject timestamps outside a small tolerance. A legacy `X-Webhook-Signature` (HMAC over body only) is still sent for backward compatibility.
- **SSRF guard** — before delivery, `Services\SsrfGuard::isSafePublicUrl()` rejects loopback/private/link-local/reserved ranges and the cloud metadata IP `169.254.169.254`, fail-closed on unresolvable hosts. Curl is locked to HTTPS-only (`CURLPROTO_HTTPS`) and never follows redirects, so a 30x cannot bounce to an internal host after the pre-flight check.
- **Reliability** — 5 attempts (1 + 4 retries) with deterministic exponential backoff + jitter (~1m/5m/15m/1h). Every attempt is logged to `webhook_deliveries`; a run of failures auto-disables the endpoint (`recordDeliveryResult`). Under the `sync` queue driver the job delivers once and never throws, so cron-less hosts still emit `order.paid` etc. without surfacing errors to the caller.

See the developer docs [webhooks](../dev/api/webhooks.md) and [webhook events](../dev/api/webhooks-events.md) for the receiver-side contract.

## Gotchas

- CSP enforce is **off by default** (`CSP_ENFORCE` unset). Themes are nonce-ready and guarded by `CspEnforceTest`, but production is not enforcing until the env flip and browser pass are done — treat storefront XSS defense as report-only until then.
- `APP_KEY` rotation breaks all `encrypted:array` credentials and 2FA secrets and logs everyone out — never rotate it without owner coordination.
- The webhook SSRF guard and HTTPS-only curl apply to owner-supplied endpoint URLs; any new outbound HTTP that accepts a tenant-supplied URL must route through `SsrfGuard` too.
- `sanitize_css()` and `Html::clean()` are the only sanctioned raw-output paths; a new `{!! !!}` sink echoing model data must go through one of them.
