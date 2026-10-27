---
title: "Theme marketplace administration"
audience: reseller
group: Resellers
updated: 2026-07-08
---

# Theme marketplace administration

The theme marketplace lets sellers submit storefront themes that restaurant owners buy with wallet credit. This page covers the platform-admin side: reviewing submitted theme versions, the sandbox whitelist that gates them, how purchases and revenue share flow through the wallet, and the seller cash-out (withdrawal) queue.

All admin pages here live under the admin panel (routes named `admin.*`, prefixed with `admin_url()` — `admin` by default, overridable via the `APP_ADMIN` env var) and are gated by the `admin` middleware, so only a superadmin can reach them.

## How a theme reaches review

A submission is a zip that runs through a fixed pipeline before a human ever sees it (`app/Storefront/ThemeSubmissionService.php`):

1. Hard security gates — the package validator (`ThemePackageValidator`) rejects anything with a `.php` file, disallowed extensions, or a bad layout. A failure throws; nothing is stored.
2. Immutable install — the theme files are written to disk under `storage/app/marketplace-themes/<slug>/<version>/` and a `theme_versions` row is created with `status = review`. The database holds only the index (manifest, checksum, reports), never the theme markup.
3. Linter (`ThemeLinter`) — parses the Liquid against a closed whitelist. Its output is persisted to `lint_report`. If the lint fails, the version is auto-rejected (`status = rejected`) with `review_notes` naming the failed rules — no human time is spent.
4. Optional quality gate — when preview URLs exist, a Lighthouse performance/accessibility check writes `gate_report` and auto-rejects below the bar.

Only versions that pass the automated gates land in the human review queue. There is no admin-facing upload form: submissions come in through `ThemeSubmissionService` (used by tooling/tests and the developer flow documented at `/developers/themes`), not a routed controller.

## The whitelist

Themes run in a sandbox with a closed contract, and the whitelist is the heart of it. It is not a list of approved sellers — it is the fixed set of Liquid filters and tags a theme may use (`app/Storefront/ThemeFilters.php`, enforced by `ThemeLinter` and the render-time `LiquidEnvironmentFactory`). Any filter outside the whitelist is an `unknown-filter` lint error, which auto-rejects the submission before review.

The whitelist is authoritative and generated from code. Sellers can read the current set at `/developers/themes` and through the developer MCP endpoint, so a submission that stays inside it is what reaches your queue clean. As a reviewer you do not edit the whitelist per theme; you approve or reject against the report the pipeline already produced.

## Reviewing submitted versions

The review queue is at `admin.theme-reviews.index` (`/admin/theme-reviews`, linked in the admin sidebar as "Theme Reviews"). The controller is `app/Http/Controllers/Admin/ThemeReviewController.php`.

The page has two tables:

- Pending review — every `theme_versions` row still in `review`, oldest first. Columns: Theme (name + slug), Version (semver), Lint, Quality gate, Submitted (relative time), and actions.
  - Lint shows a green "clean" badge (plus a warnings count if any) or a red badge with the error count.
  - Quality gate shows "pending" when `gate_report` is null, a green `P{performance} / A{accessibility}` badge when it passed, or a red "failed".
  - Preview opens `marketplace.preview` for that slug and version in a new tab.
- Recent decisions — the last 20 versions that are `published`, `rejected`, or `delisted`, newest first, showing the status badge, truncated review notes, and when it was decided.

Two actions:

- Approve and publish (`admin.theme-reviews.approve`) — sets the version to `published` and the parent theme to `published`. A published version is immutable from that moment (guarded by the `ThemeVersion` model); any change ships as a new version row. This is what makes buyer version pinning safe.
- Reject (`admin.theme-reviews.reject`) — prompts for rejection notes (required, max 2000 chars), stores them in `review_notes`, and sets the version to `rejected`. The notes are the author's feedback.

Both actions only act on a version still in `review` (`findOrFail` scoped to that status), so a stale button on a version someone else already decided fails safely.

## Themes, versions, and status

The schema (`create_marketplace_theme_tables` and follow-ups) separates identity from releases:

- `themes` — one row per theme: `seller_id` (null means platform-owned), `name`, unique `slug`, `status` (`draft` / `review` / `published` / `delisted`), and `price` (added by `create_theme_purchases`; `0` = free).
- `theme_versions` — one row per submitted release: `semver` (unique per theme), `manifest`, `checksum` (sha256 of the zip), `status` (`review` / `published` / `rejected` / `delisted`), plus the `lint_report`, `gate_report`, and `review_notes` review trail. A published row never mutates.

A restaurant's `theme_settings` can carry a `theme_version_id` to pin the exact version its customisations were built for.

## Purchases and entitlement

A purchase is a per-account license, not per-restaurant. Buying happens on the public marketplace: an owner hits `marketplace.buy` for a published theme, and `ThemePurchaseService::purchase()` runs the charge through `WalletService` (the ledgered, idempotent money choke point). A `theme_purchases` row records `user_id`, `theme_id`, `price_paid`, and the wallet reference, with a unique `(user_id, theme_id)` constraint — buying twice returns the existing license without a second charge. Free themes (`price = 0`) need no purchase.

Entitlement is what the render and apply paths check, not mere ownership. `ThemePurchaseService::entitled()` allows any free theme and otherwise requires a `theme_purchases` row; `apply` and the storefront renderer both enforce it, returning 402 when the account lacks a license. If the entitlement lookup errors, it fails open (allow) so an outage never blanks a live storefront.

## Revenue share and the ledger

Every paid sale credits the seller's wallet with their configured share (`app/Services/SellerPayoutService.php`). The default is 70/30 seller/platform, set by `platform.marketplace.seller_share` (env `MARKETPLACE_SELLER_SHARE`, clamped to 0–1). The platform's cut stays implicit — it is simply the remainder. Details:

- The credit is idempotent per sale reference, so a retried purchase never double-pays.
- Platform-owned themes (null `seller_id`) skip the credit entirely.
- The seller amount is rounded down to the cent; the platform absorbs sub-cent dust.
- A payout failure is reported but never breaks the buyer's purchase.

Because every credit and debit runs through `WalletService`, they all land in the platform wallet ledger at `admin.wallet.index` (`/admin/wallet`, sidebar "Wallet ledger"). That page lists every `wallet_transactions` row across users, filterable by type (credit/debit), reason, and user, with 30-day credit/debit/net totals. Theme sales, seller payouts, withdrawals, and withdrawal refunds are all visible there — it is the single audit trail for marketplace money.

## Seller withdrawals (cash-out)

Sellers cash out their wallet balance through a withdrawal queue you settle manually. The flow is designed so money can never be double-spent: the amount is debited from the seller's wallet at request time (`SellerPayoutService::requestWithdrawal()` via the seller-facing `wallet.withdraw` route), inside a transaction — an over-balance request throws before any pending row is committed. A pending withdrawal therefore already represents money that has left the wallet.

The admin queue is at `admin.withdrawals.index` (`/admin/withdrawals`; controller `WithdrawalAdminController`). Note this page is not currently linked in the admin sidebar — reach it by URL. It shows:

- Pending — seller (name + email), amount, requested time, and mark-paid / reject controls.
- Processed — the last 50 non-pending rows with status badge, reference (or reject note), and when they were processed.

Two actions, both scoped to a pending withdrawal:

- Mark paid (`admin.withdrawals.pay`) — for the manual rail, you enter an external payment reference (required, max 120 chars); `SellerPayoutService::markPaid()` records it and stamps `processed_by` / `processed_at`. The money already left the wallet, so this only records the settlement. For the Stripe Connect rail the button reads "Pay via Stripe" and takes no reference — see below.
- Reject (`admin.withdrawals.reject`) — takes an optional reason note (max 255 chars), credits the reserved amount back to the seller's wallet (`withdrawal_rejected`), and marks the row `rejected`.

The `seller_withdrawals` table holds `user_id`, `amount`, `status` (`pending` / `paid` / `rejected`), `rail` (`manual` / `stripe_connect`), the external `reference`, an admin `note`, and `processed_by` / `processed_at`.

## Payout rails

Which rail a new withdrawal uses is set platform-wide by `platform.marketplace.payout_rail` (env `MARKETPLACE_PAYOUT_RAIL`), defaulting to `manual`:

- `manual` — a superadmin records an external payment reference when marking paid. No integration needed.
- `stripe_connect` — the platform settles a pending withdrawal with a Stripe Transfer to the seller's connected Express account, and the transfer id becomes the reference (`StripeConnectService` / `SellerPayoutService::payViaStripe()`). The transfer is made in the withdrawal's own currency (the wallet currency it was drawn from); a currency Stripe cannot carry is refused. `platform.marketplace.payout_currency` is no longer used for transfers.

The Stripe rail only actually transfers when it is both active and usable — a Stripe secret key is configured and the Stripe SDK is present (`StripeConnectService::isAvailable()`); otherwise it runs behind a mockable gateway. Sellers connect their Stripe Express account through a hosted onboarding link (`StripeConnectService::beginOnboarding()`), and `seller_stripe_accounts` stores one `account_id` and `details_submitted` flag per seller. Either way, the wallet is debited at request time — the rail only changes how the pending amount is settled out.

## Related pages

- [Reseller and white-label overview](overview.md)
- [Plans and pricing configuration](plans-config.md)
- [Branding](branding.md)
