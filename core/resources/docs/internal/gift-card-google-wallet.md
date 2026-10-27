---
title: "Gift-Card Google Wallet (INTERNAL)"
audience: internal
group: Internal
updated: 2026-08-24
---

# Gift-Card Google Wallet

**Status:** Shipped `d5c275ee7` (origin/main) · **Dark by default** · Chrome-verified 2026-08-24

Give a diner an **Add to Google Wallet** button for the gift cards they bought, and expose the same capability on the customer API and the Customer MCP server. Everything stays inert ("dark") until an owner wires real Google Wallet credentials and flips the per-store toggle.

---

## 1. At a glance

The Google Wallet **save-link endpoint** for a gift card already shipped in the earlier google-suite Lane C work (`GoogleWalletController@giftCardJwt`), fully tested — but nothing rendered its button, and no developer surface exposed it. This change closes that gap on three surfaces:

| Surface | What was added |
|---|---|
| **Storefront** | A self-anchoring partial that renders a **"Your gift cards"** section with a per-card *Add to Google Wallet* badge on the customer account page. |
| **Customer API** | `GET /api/v1/customer/gift-cards` — the cards a customer purchased, each with a Wallet `wallet_save_url` when enabled. |
| **Customer MCP** | A new `my_gift_cards` tool returning the same data (read-only). |

Plus documentation on all three developer-doc pillars.

---

## 2. How the save-link works (the chain)

The button never contains a Google pass. It fetches a freshly-minted, signed save-link on click:

```
customer clicks badge
   │
   ▼
GET /restaurant/{storeId}/google-wallet/gift-card/{giftCardId}/jwt   (auth:customer, throttle:30,1)
   │   route name: publicView.googleWallet.giftCardJwt
   ▼
GoogleWalletController@giftCardJwt
   │   guards: 401 no customer · 404 store disabled · 404 wrong store · 403 not the purchaser
   ▼
GoogleWalletService::giftCardSaveUrl($store, $card)
   │
   ├─ GiftCardPassMapper::payload()   → giftCardClasses[] + giftCardObjects[] (balance micros, code, QR)
   └─ PassJwt::saveUrl()              → RS256-signs → https://pay.google.com/gp/v/save/<JWT>
   ▼
{ "save_url": "https://pay.google.com/gp/v/save/…" }
   │
   ▼
button navigates the top window to save_url
```

**Why lazy-mint:** the JWT is minted only for an authenticated customer who owns the card, and is never baked into the HTML — so `Content-Security-Policy: connect-src 'self'` holds and no pass leaks to page source.

### The two gates

`GoogleWalletService::enabledForStore($store)` returns true only when **both** hold:

1. **Platform creds present** — `enabled()` = `issuerId() !== '' && serviceAccountPath() !== ''` (reads config, never touches the key file; side-effect-free so it is safe on every request).
2. **Per-store toggle on** — `store_options($id, 'google_wallet_enabled') === '1'` (a **string** `'1'`/`'0'`, never `=== true`).

A third gate is per-card: the requesting customer must be the card's `purchaser_customer_id` (else `403`). Recipient-by-email cards have no purchaser link and are reached from the delivery email, not this endpoint.

---

## 3. The storefront button

**File:** `core/resources/views/store_templates/_partials/google-wallet-giftcards.blade.php`
**Wired from:** `core/resources/views/store_templates/layout.blade.php` (one `@include`, right after the loyalty Wallet partial).

### Render conditions (server-side `@php` gate)

The block renders **nothing** unless all are true:

- `request()->routeIs('customer.account')` — only on the account page.
- `auth('customer')->check()` — a signed-in customer.
- `GoogleWalletService::enabledForStore($store->id)` — Wallet configured + toggled on.
- The customer has purchased ≥ 1 gift card at this store (`purchaser_customer_id`).

### Self-anchoring

Included once from the shared layout, so it must place itself into whichever account theme is rendering. A small CSP-nonce script moves the pre-rendered (hidden) block into the account content column and unhides it:

```
section[data-view="account"] .view-scroll,   /* 13 "me-card" themes: crave, crumb, dough, ember,
.view-scroll,                                    glow, lime, matcha, orbit, picnic, plate, scoop,
.au-account-container,                           soiree, velora */
.ff-account-page, .account-page,              /* feast, lala-theme */
.v-account-page, .vd-account                  /* velvet, vivid */                       /* + aurora */
```

If no anchor is found within ~3s the block removes itself. Each card row shows code, balance, status/expiry, and — when the card is redeemable — the badge (`.mbGWGCBtn[data-jwt-url]`). One delegated click handler fetches the JWT and navigates to `save_url`; on any non-OK response the button hides quietly (fail-soft).

---

## 4. Customer API

`GET /api/v1/customer/gift-cards` — middleware `CustomerTokenAuth` (bearer). Method: `CustomerAppController@giftCards`. Auto-surfaced in the generated OpenAPI spec (`/api/v1/openapi.json`).

```json
{
  "wallet_enabled": true,
  "gift_cards": [
    {
      "id": 42,
      "code": "GC-7F3K-9QW2",
      "balance": 25.00,
      "formatted": "$25.00",
      "status": "active",
      "expired": false,
      "redeemable": true,
      "expires_at": "2027-01-01T00:00:00+00:00",
      "wallet_save_url": "https://pay.google.com/gp/v/save/…"
    }
  ]
}
```

- Lists only cards the customer **purchased** (recipient cards excluded).
- `wallet_save_url` is present only when the store has Wallet enabled **and** the card is redeemable — otherwise `null` and `wallet_enabled` is `false`.
- The save-link is short-lived; hand it to the customer to open, don't store it.

---

## 5. Customer MCP tool

Server `POST /mcp/customer` (customer bearer token). New tool **`my_gift_cards`** (read-only, `annotations.readOnlyHint = true`). Returns the same rows (code, balance, status, expired, redeemable, expires_at, `wallet_save_url`), scoped to the diner's own purchased cards. The Customer registry grew from 6 → **7** tools.

---

## 6. Files changed (10)

**Added**

| File | Purpose |
|---|---|
| `core/resources/views/store_templates/_partials/google-wallet-giftcards.blade.php` | The storefront button/section |
| `core/tests/Feature/GoogleWalletGiftCardsTest.php` | 10 tests (API / MCP / partial) |

**Modified**

| File | Change |
|---|---|
| `core/resources/views/store_templates/layout.blade.php` | `@include` the new partial |
| `core/app/Http/Controllers/Api/V1/CustomerAppController.php` | `giftCards()` method |
| `core/routes/apiv1.php` | `GET api/v1/customer/gift-cards` route |
| `core/app/Modules/Agents/Mcp/Customer/CustomerTools.php` | `my_gift_cards` tool (definition + handler; served by CustomerMcpServer) |
| `core/resources/docs/dev/api/customer-orders.md` | "Gift cards" endpoint section |
| `core/resources/docs/dev/mcp/mcp-servers.md` | Customer table: +`my_bookings`, +`my_gift_cards`; count 5 → 7 |
| `core/resources/docs/users/gift-cards.md` | Owner-facing "Add to Google Wallet" note |
| `core/tests/Feature/McpLandingPageTest.php` | Registry ratchet 6 → 7 |

---

## 7. How to use it (owner + customer)

**Owner (once creds exist):**
1. Platform: set `GOOGLE_WALLET_ISSUER_ID`, `GOOGLE_WALLET_SA_JSON`, `GOOGLE_WALLET_ORIGINS` in the environment.
2. Per store: turn on **Google Wallet** (the `google_wallet_enabled` toggle on the store's loyalty/settings page — `googleWallet.toggle`).

**Customer:** buy a gift card → sign in → open **Profile / My account** → the **Your gift cards** section shows each card with an **Add to Google Wallet** button. Tapping it saves the card (balance, code, QR) as a Wallet pass that updates as they spend.

---

## 8. How to test

### Automated

```bash
php -d opcache.enable_cli=0 artisan test --filter=GoogleWalletGiftCardsTest
```

`GoogleWalletGiftCardsTest` — 10 tests:

- **API** — lists purchased cards with `wallet_save_url` when enabled; `null` when disabled; excludes other customers' and recipient cards; 401 without a token.
- **MCP** — `tools/list` includes `my_gift_cards`; returns own cards with save-url; `null` when disabled.
- **Account partial (through the real controller)** — shows the block + button + card code when enabled; hidden when disabled; hidden with no cards; the save-link JWT is **not** leaked into the HTML.

Guard/contract suites that also cover this change (all green — 86 tests):
`ViewComposerContractTest` · `OpenApiParityTest` · `McpLandingPageTest` · `GoogleWalletEndpointTest` · `GoogleWalletPassTest` · `CustomerMcpTest` · `ControllerSizeGuardTest`. PHPStan: `[OK]` (full ratchet).

> **PHPStan note:** `Post::find($customer->store_id)` is typed non-null here, so the null-guards were dead (`booleanAnd.rightAlwaysTrue`). Fixed honestly with `findOrFail` + dropping the redundant `&& $store`, not a cast or ignore.

### Manual — light it and walk it locally

Google Wallet is creds-dark, so generate a **fake** service-account file (a throw-away RSA key, `tests/Support/FakeGoogleServiceAccount.php` makes one) to demo without real creds. (This is also saved as the `google-wallet-local-demo-recipe` memory.)

**1. Configure (local `.env` — it is git-ignored, no repo change):**

```bash
# append to core/.env, then:  php -d opcache.enable_cli=0 artisan config:clear
GOOGLE_WALLET_ISSUER_ID=3388000000012345678
GOOGLE_WALLET_SA_JSON=/abs/path/to/your/throwaway-service-account.json
GOOGLE_WALLET_ORIGINS=https://template.test
```

**Host facts:** repo `menubar-template` serves at `https://template.test`, DB `newmenu_template` (prefix `qrtest_`). Store **3425 = scoop-demo**. The store_options column is `option_name`. Customer web login is **email + password** (route `customer.login`).

**2. Seed (tinker):**

```php
$store = App\Models\Post::find(3425);
App\Models\StoreOption::updateStoreOption($store->id, 'google_wallet_enabled', '1');
$c = App\Models\Customer::firstOrCreate(
  ['store_id'=>$store->id, 'email'=>'walletdemo@example.test'],
  ['name'=>'Wallet Demo', 'phone'=>'5550100777',
   'password'=>Illuminate\Support\Facades\Hash::make('Password123!')]);
$card = App\Services\GiftCardService::issueManual($store->id, 30.0,
  ['purchaser_customer_id'=>$c->id]);   // active, funded, redeemable
```

**3. Walk (real Chrome):** log in as `walletdemo@example.test` / `Password123!` via the storefront profile → open `/scoop-demo/customer/account`. Verify:

```js
document.getElementById('mbGWGiftCards')          // present, .hidden === false
document.querySelector('.mbGWGCBtn').dataset.jwtUrl // /restaurant/3425/google-wallet/gift-card/{id}/jwt
// prove the mint (same-origin, authed):
await fetch(jwtUrl,{headers:{Accept:'application/json'},credentials:'same-origin'})
  .then(r=>r.json())                                // → { save_url: "https://pay.google.com/gp/v/save/…" }
```

> ⚠️ **Do NOT click the badge through to `pay.google.com`.** The pack rule bars hitting real Google endpoints; the local mint (200 + `save_url`) is the verification boundary, and a real save needs owner creds anyway.

**4. Clean up (always):** remove the `.env` lines + `config:clear`; delete the gift card + its ledger account/entries, the customer (guard on 0 orders), and the toggle row, in one transaction; re-verify `GoogleWalletService::enabled() === false` and `cards_left === 0`.

---

## 9. Verification evidence (2026-08-24)

Chrome walk on `template.test` / scoop-demo, real Chrome:

- Account page rendered as "Wallet Demo" (authenticated).
- `#mbGWGiftCards` present, `hidden === false` (self-anchored into scoop `.view-scroll`).
- Button `data-jwt-url` = `/restaurant/3425/google-wallet/gift-card/1/jwt`; card `GC-WWR0ZD69Z3FV`, `Balance: 30.00$`, heading "Your gift cards".
- Button's fetch URL → **HTTP 200** + real `pay.google.com/gp/v/save/<JWT>`.
- **No console errors.**
- Did not click through to Google. Fake creds + seed rows fully reverted afterward (`enabled() === false`, `cards_left === 0`).

---

## 10. What remains

**Owner (go-live blockers — external, only the owner can do):**
- Create a Google Wallet **issuer** account, generate a **service-account key**, obtain **publishing access**.
- Set `GOOGLE_WALLET_ISSUER_ID` / `GOOGLE_WALLET_SA_JSON` / `GOOGLE_WALLET_ORIGINS` in production.
- Flip the per-store `google_wallet_enabled` toggle for each store that should offer it.

Until all three are done, the button, the API `wallet_save_url`, and the MCP `wallet_save_url` stay dark (empty/`null`) — by design.

**Deferred build (optional, not blocking):**
- Surface an *Add to Google Wallet* affordance for **recipient** gift cards (currently reachable only from the delivery email — recipients have no `purchaser_customer_id`).
- A dedicated customer gift-card *show* page (today the cards live only inside the account page section).

**Parked decisions (need product call):**
- Reseller / white-label **Wallet-issuer policy** (per-owner issuer vs. platform issuer).
- Whether to also show the button on the purchase-confirmation screen.

---

## Security & design rules honored

- **Dark discipline** — no creds ⇒ zero UI, zero save-links; fakes keep tests green.
- **Lazy JWT** — save-link minted on click from a customer-guarded endpoint, never embedded; `connect-src 'self'` intact.
- **Ownership** — only the purchaser can mint a card's save-link (`403` otherwise); the API/MCP list only the customer's own purchased cards.
- **No real Google calls** in tests or local walks — the local mint is the boundary.
- **No global-setting workarounds** — the local `.env` creds are a demo device, reverted after; `.env` is git-ignored.
