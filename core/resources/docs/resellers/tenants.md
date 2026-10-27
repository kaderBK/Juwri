---
title: "Managing tenants"
audience: reseller
group: Resellers
updated: 2026-07-08
---

# Managing tenants

Every restaurant account on the platform is a user record (a "tenant") with a plan, stores, and a wallet balance. This page covers the admin screens that let you find, edit, impersonate, and adjust those tenants. It is written for operators of a white-label instance; the same screens serve the platform superadmin.

For plan definitions and pricing see [Configuring plans and pricing](plans-config.md). For the reseller portal itself see [Reseller and white-label overview](overview.md).

## Where these screens live

All tenant management lives under the admin panel. The panel is served from a configurable URL prefix (the `APP_ADMIN` environment value, default `admin`), so the user list is at `/{admin}/users`. Every route in this guide is named `admin.users.*`.

Access is gated by two middleware: `admin` (the signed-in user must have `user_type = admin`, checked by `AdminMiddleware`) and `demo`. A non-admin who hits these URLs is redirected to the login page.

## The user list

`GET /{admin}/users` (`admin.users.index`) is the main tenant table. The top of the page shows four counters — total users, paid subscribers, free and trial, and new in the last 30 days.

The filter bar supports:

- **Search** by name, username, or email.
- **Subscription plan** — all plans, plus Free and Trial.
- **Plan status** — active, expired, lifetime, trial (active), trial (expired).
- **Email status** — verified or unverified.
- **Status** — active or banned.
- **Stores** — with or without restaurants.

Column headers for Joined, id, name, email, plan end, last login, stores, and transactions are sortable, and the result set is paginated (25, 50, or 100 per page).

Each row has two actions: **Edit** (opens the account screen) and **Login** (impersonates the user — see below). A floating action button group also offers bulk delete, "delete all users without restaurants", and "select all users without restaurants". The Export / Import menu handles regular, WhatsApp, full-backup, target-app, and Hungerino exports plus bulk import.

## Editing a tenant

`admin.users.edit` opens the account screen. The form (`admin.users.update`, PUT) edits:

- Avatar (upload or remove; JPG/JPEG/PNG up to 2 MB).
- Full name, username, email — username and email are unique-validated.
- **User type** — user or admin. Promoting a tenant to admin gives them the admin panel.
- **Account status** — Active or Ban.
- **Email status** — Verified or Unverified (setting Verified stamps `email_verified_at`).
- Country.

A send-email panel on the same screen (`admin.users.sendmail`) queues a one-off support message to the tenant, with a subject, reply-to, and rich-text body.

### Resetting a password

The **Password** tab (`admin.users.password`) posts to `admin.users.password.update`. The new password must be at least 6 characters and confirmed. The tenant is not notified automatically.

### The account sidebar

The left sidebar (the `userdetails` partial) shows the tenant's current plan and its limits — restaurants, menu categories, items per category, monthly scans, and feature toggles such as ordering, table QR codes, and hidden branding. A limit stored as `999` renders as "Unlimited". From here you can open **Change Plan**, **Export Full Data**, **Hungerino Export**, and **Import Full Data** (which replaces the tenant's subscription and restaurant data from an uploaded backup zip).

## Impersonation with audit

The **Login** button on each user row posts to `admin.users.login` (`loginAsUser`) and logs you in as that tenant so you can see exactly what they see.

What happens:

1. If you target your own account, nothing switches — you get an "already logged in" notice.
2. Before the session switches, an audit row is written to `activity_logs` with action `admin.impersonate`, recording your admin id, the target id and name, and the request IP. Logging happens first so the actor on the record is the admin, not the impersonated user.
3. Your admin session is replaced by the tenant's, your original admin id is stored in the session key `quick_admin_user_id`, and you land on the tenant dashboard.

To leave impersonation, log out normally (`route('logout')`). The logout handler detects `quick_admin_user_id`, writes an `admin.impersonate_exit` audit row, logs you back in as the original admin, and returns you to the user list — you do not have to sign in again.

Both start and exit are recorded in the shared activity log (there is no separate impersonation table), so every "log in as" is attributable to an admin with a timestamp and IP. See [Activity log](../users/activity-log.md) for how to read those entries.

Two items from the original impersonation spec are **not present in the shipped code**: there is no persistent on-screen banner while you are impersonating (exit is by ordinary logout, not a dedicated "Exit" button), and sensitive actions are not specially blocked or flagged while impersonating. Treat impersonation as full access to the tenant account and act accordingly.

## Subscriptions

There is no separate subscription list; a tenant's subscription is managed from the **Change Plan** panel in the account sidebar (`admin.users.plan`, `updatePlan`).

The panel lets you:

- Pick a plan — Free, Trial, or any configured paid plan.
- Set **Trial done** (yes/no), which controls whether the tenant can start a trial again.
- Set an **expiration date** (hidden when the plan is Free).

Saving deletes the tenant's existing membership row, creates the new one, updates their plan group, and resyncs the modern subscription record from the legacy tables. The user list shows the resulting plan window as a badge — Lifetime, Expired with a date, or "Until \<date\>" — with trial windows computed from the signup date.

### Seasonal pause and resume

When a tenant has a live, active, paid subscription, the Change Plan panel shows a **Pause subscription (seasonal)** button (`admin.users.subscription.pause`). While paused, the panel instead shows **Resume subscription** (`admin.users.subscription.resume`); the pause duration is added back to the expiry on resume. These are admin-only controls with no self-service equivalent.

## Wallet adjustments

Each tenant has a wallet whose balance is the sum of their wallet transactions. Admins can add credit through the endpoint `admin.users.wallet.grant` (`WalletAdminController@grantCredit`), which:

- Validates the amount (between 0.01 and 9999) and an optional reason (up to 120 characters).
- Credits the wallet via `WalletService::credit`, which is idempotent per reference and always a positive delta.
- Writes a `wallet.admin_grant` activity-log row with the amount, resulting balance, and your admin id.

Note: the grant endpoint is live on the backend, but no button in the current admin user screens posts to it — a manual admin credit currently has no shipped UI. If you need to grant credit today, treat this as backend-only until a button is wired up. Tenant-facing top-ups are configured separately; see [Wallet top-up](../users/wallet.md).

## What is and is not built

- Built: user list with filters and bulk tools, account and password editing, plan change, seasonal pause/resume, impersonation with start/exit audit, wallet credit service and endpoint.
- Not built or partial: an impersonation banner and guardrails on sensitive actions during impersonation; an admin UI button for wallet grants; a standalone subscriptions listing page.
