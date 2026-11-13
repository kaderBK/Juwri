# Owner app — bridge components (server item S5)

The web half of the Hotwire Native bridge: four Stimulus bridge components that
let a dashboard page put **native** controls in the app — a nav-bar Save button,
a native action sheet, a nav-bar action button, and the in-app purchase flow.
`form`, `menu` and `button` keep the same names and message shapes as the official
Hotwire Native demo (`hotwired/hotwire-native-demo`), so the native side can start
from the demo's `FormComponent` / `MenuComponent` and a `ButtonComponent` like the
one in the iOS docs. `purchase` is the mobile lane's own component (not in the
demo): the billing screen drives it and the store SDKs answer through it.

## 1. What is loaded, and when

| File | What |
|---|---|
| `assets/owner-app/vendor/stimulus-3.2.2.js` | `@hotwired/stimulus` 3.2.2, ESM, verbatim |
| `assets/owner-app/vendor/hotwire-native-bridge-1.2.2.js` | `@hotwired/hotwire-native-bridge` 1.2.2 (latest on npm, 2026-09-25), verbatim except its `@hotwired/stimulus` import now points at `./stimulus-3.2.2.js` (no bundler / import map on the dashboard) |
| `assets/owner-app/bridge.js` | boot module: registers `bridge--form`, `bridge--menu`, `bridge--button`, `bridge--purchase` |
| `assets/owner-app/bridge.css` | hides the web controls a native component replaces |

- Loaded by `layouts/app.blade.php` **only when `OwnerApp::inApp()`** (UA contains
  `JuwriApp/`). The browser and the PWA never load them.
- `bridge.js` is `<script type="module" nonce="…" data-turbo-eval="false">`: CSP-clean
  (same-origin, `script-src 'self'` + nonce), and a module runs once per document —
  Turbo visits never re-run it. Stimulus connects/disconnects the controllers on
  every Turbo body swap by itself.
- A controller loads only if the app's user agent lists its component. Hotwire
  Native adds this to the UA automatically from the components the shell registers:
  `… JuwriApp/1.0.0 (ios) Hotwire Native iOS; Turbo Native iOS; bridge-components: [form menu button purchase]`.
  A shell that does not register a component leaves the web control untouched.
- When the native shell attaches its adapter, the library sets on `<html>`:
  `data-bridge-platform="ios|android"` and `data-bridge-components="form menu button purchase"`.
  `bridge.css` keys off `data-bridge-components` only — so nothing is hidden until
  the native side has really taken over. `purchase` appears there only once a shell
  registers it; without it the billing screen is simply non-functional in the app
  (there is no web checkout to fall back to, Apple 3.1.3).

## 2. Web markup contract (copy-paste)

Rules for every component:
- The `data-controller` value, target names and `data-bridge-*` attributes below
  are the whole contract. Nothing else is read.
- **Title**: `data-bridge-title`; if absent → `aria-label` → text → `value`. Keep it
  short (it is a nav-bar label): "Save", "Add item".
- **At most one `bridge--form` and one `bridge--button` per page** — the nav bar has
  one slot each. Several `bridge--menu` per page are fine (one per row).
- The web element keeps working when the native side does not take over (browser,
  old shell), so markup is written once for web + app.

### 2.1 `bridge--form` — native Save / Submit in the nav bar

```html
<form method="post" action="…" data-controller="bridge--form">
  @csrf
  …fields…
  <div class="sticky-savebar" data-bridge-hide="form">          {{-- optional: hide the web bar in the app --}}
    <button type="submit"
            data-bridge--form-target="submit"
            data-bridge-title="{{ ___('Save') }}">{{ ___('Save changes') }}</button>
  </div>
</form>
```

- `data-controller="bridge--form"` on the `<form>` (or on a wrapper that contains
  the form and its submit button).
- `data-bridge--form-target="submit"` on the ONE submit button the native button
  stands for. Hidden in the app by `bridge.css`; `data-bridge-hide="form"` hides any
  extra chrome around it (a sticky save bar, a Cancel/Back link).
- Native tap → the web button is `.click()`ed → the form submits exactly as a tap on
  the web button would (Turbo in the app, `data-ajax` / Alpine handlers keep working).
- The native button is **disabled** while: the web button is `disabled`, has
  `aria-disabled="true"` or class `disabled`; a Turbo submission is in flight; the
  target is not in the DOM (e.g. rendered later by `x-if`). It re-enables when that
  ends. So a multi-step form can keep the native button grey until its last step with
  `x-bind:aria-disabled="step !== 'confirm'"` on the submit button.
- Use it on single-form screens (store settings). Pages with several independent
  inline forms (menu categories) should not use it.

### 2.2 `bridge--menu` — native action sheet

```html
<div data-controller="bridge--menu" data-bridge-title="{{ ___('Order') }} #{{ $order->id }}">
  <button type="button" class="btn btn-light btn-sm"
          data-bridge--menu-target="trigger" aria-label="{{ ___('Actions') }}">⋯</button>

  <div class="dropdown-menu">                                    {{-- the web menu, unchanged --}}
    <a href="{{ $editUrl }}" data-bridge--menu-target="item">{{ ___('Edit') }}</a>
    <button type="button" @click="completeOrder($event, @js($url))"
            data-bridge--menu-target="item">{{ ___('Complete') }}</button>
    <button type="button" @click="deleteOrder($event, @js($url))"
            data-bridge--menu-target="item" data-bridge-destructive>{{ ___('Delete') }}</button>
    <a href="…" data-bridge--menu-target="item" data-bridge-disabled="android">{{ ___('Print') }}</a>
  </div>
</div>
```

- `data-controller="bridge--menu"` on a wrapper containing the trigger and the items;
  `data-bridge-title` on it is the sheet title (optional, may be empty).
- `data-bridge--menu-target="trigger"` on the element that opens the web menu. In the
  app a tap on it opens the **native** sheet instead: the click is stopped in the
  capture phase at the wrapper, so Bootstrap / Alpine / `data-ui-menu` never open the
  web menu. Make the trigger a child of the wrapper (not the wrapper itself).
  (`data-action="bridge--menu#show"` also works, demo-style.)
- `data-bridge--menu-target="item"` on every action, in display order. Picking a native
  row `.click()`s that element — links navigate, buttons run their `@click` (and any
  `data-confirm` / `dashConfirm` dialog), submit buttons submit their form.
- `data-bridge-destructive` → red / destructive row. `data-bridge-disabled="true"`
  (or `"ios"` / `"android"`) → left out of the native sheet; so is an item that is
  `disabled`, `aria-disabled="true"` or `.disabled`.
- If no item is left, the web menu opens as usual.

### 2.3 `bridge--button` — native nav-bar button

```html
<a href="{{ route('stores.create') }}"
   data-controller="bridge--button"
   data-bridge-title="{{ ___('Add store') }}"
   data-bridge-ios-image="plus"
   data-bridge-android-image="add">{{ ___('Add store') }}</a>
```

- `data-controller="bridge--button"` on the element itself (link or button). Hidden in
  the app by `bridge.css`; the native tap `.click()`s it (so `@click` handlers that
  open an in-page dialog work too).
- `data-bridge-ios-image` = an SF Symbol name; `data-bridge-android-image` = a
  Material icon / drawable name the Android app maps. Both optional (title only).

### 2.4 `bridge--purchase` — in-app purchase (StoreKit / Play Billing)

```html
<div class="billing-screen" data-controller="bridge--purchase">
  {{-- the billing screen: plan list + Buy / Restore controls (P5-22) --}}
</div>
```

- `data-controller="bridge--purchase"` on the billing screen container. Like every
  component it only activates when the app's UA lists `purchase`; in the browser
  and in a shell without it the screen still renders, but Buy / Restore do nothing —
  buying lives only in the app (there is no store or web-checkout link; Apple 3.1.3).
- The screen drives the controller: load the prices, buy one product, restore. It also
  sends `acknowledge` once the server accepted a verify, so the shell finishes the
  transaction. The controller relays those asks to the native side and hands the
  replies back to the screen. Exact events and payloads are §3.4.
- `connect {}` carries nothing (no `data-bridge-*` attributes are read). The account
  token is fetched by the screen from the server (`owner-app/billing/account-token`,
  route `owner-app.billing.token`) and put into every `buy` / `restore` message by
  the controller.

### 2.5 Helpers

| Attribute | Effect (in the app only, once the component is supported) |
|---|---|
| `data-bridge-hide="form"` / `"menu"` / `"button"` (space-separated) | `display: none` — for web chrome made redundant by that native component |
| `data-controller-optout-ios="bridge--menu"` (library built-in; also `-android`) | that controller stays inactive on that platform |
| `data-web-only` (server item S2, `shell.css`) | hidden in the app regardless of components |

## 3. Native side contract (iOS + Android)

Messages are the library's: `{ id, component, event, data }`; `data.metadata.url` is
added to every message (the page URL — Hotwire Native uses it to drop messages from
a page that is no longer the visible one). Replies go back with `reply(to: event)` /
`replyTo(event)` and optional data. Unknown events must be ignored (the web side may
add events later).

### 3.1 `form` (name `"form"`)

| Direction | Event | Data | Native action |
|---|---|---|---|
| web → native | `connect` | `{ "submitTitle": "Save" }` | show a text button at the trailing end of the nav bar / toolbar; replace any previous one of this component |
| native → web | reply to `connect` | none | on tap; the web clicks the submit target |
| web → native | `submitDisabled` | `{}` | disable the button |
| web → native | `submitEnabled` | `{}` | enable the button |

`connect` implies enabled; `submitDisabled` can follow immediately. A new `connect`
(the button was re-rendered, the page restored) replaces the button and enables it.
Same as the demo `FormComponent` (`@SerialName("submitTitle")`).

### 3.2 `menu` (name `"menu"`)

| Direction | Event | Data | Native action |
|---|---|---|---|
| web → native | `display` | `{ "title": "Order #12", "items": [ { "title": "Edit", "index": 0, "destructive": false }, { "title": "Delete", "index": 2, "destructive": true } ], "source": { "x": 312, "y": 188, "width": 44, "height": 44 } }` | present an action sheet (iOS `UIAlertController(.actionSheet)`, Android bottom sheet) with a Cancel row; `destructive` → `.destructive` style / error colour; `source` (CSS px, relative to the web view's visible content) anchors the iPad / iOS 26 popover |
| native → web | reply to `display` | `{ "selectedIndex": 2 }` | send the tapped item's **`index`** (not its row position — disabled items are skipped, so indexes can have gaps) |

Cancel sends nothing. Same as the demo `MenuComponent`, plus `destructive`.

### 3.3 `button` (name `"button"`)

| Direction | Event | Data | Native action |
|---|---|---|---|
| web → native | `connect` | `{ "title": "Add item", "iosImage": "plus", "androidImage": "add" }` (`iosImage` / `androidImage` may be `null`) | show a nav-bar button: the image when present (title as its accessibility label), otherwise the title; replace any previous one of this component |
| native → web | reply to `connect` | none | on tap; the web clicks the element |

### 3.4 `purchase` (name `"purchase"`)

The billing screen's in-app purchase flow. Prices are the **store's own, localized
strings** (from StoreKit / Play Billing), never computed by the web side.

| Direction | Event | Data | Native action |
|---|---|---|---|
| web → native | `connect` | `{}` | the billing screen is up; a purchase controller exists |
| web → native | `products` | `{ "product_ids": ["com.juwri.owner.pro.monthly", "juwri_pro_monthly"] }` | query the store for these product ids |
| native → web | reply to `products` | `{ "prices": { "com.juwri.owner.pro.monthly": "$29.00" } }` | a localized price string per product id found; ids not found for the store are left out |
| web → native | `buy` | `{ "product_id": "com.juwri.owner.pro.monthly", "store": "apple"\|"google", "account_token": "<uuid>" }` | start the purchase for that product in that store |
| native → web | reply to `buy` | apple: `{ "status": "purchased", "signed_transaction": "<JWS>" }` · google: `{ "status": "purchased", "product_id": "juwri_pro_monthly", "purchase_token": "<token>" }` | purchase done; the web posts it to the server (`owner-app/billing/apple` / `…/google`) |
| native → web | reply to `buy` | `{ "status": "cancelled" }` | the user cancelled |
| native → web | reply to `buy` | `{ "status": "pending" }` | pending (e.g. Ask to Buy); nothing is granted yet |
| native → web | reply to `buy` | `{ "status": "failed" }` | the purchase failed |
| web → native | `restore` | `{ "store": "apple"\|"google", "account_token": "<uuid>" }` | list the user's current entitlements |
| native → web | reply to `restore` | apple: `{ "status": "restored", "transactions": ["<JWS>", …] }` · google: `{ "status": "restored", "purchases": [ { "product_id": "juwri_pro_monthly", "purchase_token": "<token>" }, … ] }` | entitlements found; the web posts each to the server |
| native → web | reply to `restore` | `{ "status": "empty" }` | nothing to restore |
| native → web | reply to `restore` | `{ "status": "failed" }` | restore failed |
| web → native | `acknowledge` | apple: `{ "store": "apple", "product_id": "com.juwri.owner.pro.monthly", "signed_transaction": "<JWS>" }` · google: `{ "store": "google", "product_id": "juwri_pro_monthly", "purchase_token": "<token>" }` | finish this transaction: StoreKit `transaction.finish()` / Play `acknowledgePurchase()`. Apple carries no parsed transaction id on the web side, so the `signed_transaction` string itself is sent (`product_id` may be `null` on a restore, where the page only has the signed strings) |

`acknowledge` is sent by the page **only after the verify POST returned 2xx** (for
`buy` and for each restored item that verified). It is what lets the native side
finish the transaction; the `purchased` / `restored` replies are never a signal to
finish.

Required of the native side:

- **Pass the account token through.** Every `buy` / `restore` carries the owner's
  `account_token`. The native purchase must carry it as Apple's `appAccountToken` /
  Google's `obfuscatedExternalAccountId`, so the server can prove the purchase is this
  owner's (the store notification names whose it is).
- **Finish only what the server accepted.** The web posts the signed transaction /
  purchase token to the server and sends `acknowledge` only once that POST returned
  2xx. Finish (`finishTransaction` / `acknowledgePurchase`) **only on `acknowledge`**,
  never on a `purchased` / `restored` reply. A purchase still `pending` is never
  finished; a transaction whose verify failed stays unfinished, so Restore and the
  next app start can complete it.
- **Never show a store or web-checkout link.** The page links to no store and no web
  checkout — buying happens only through this flow (Apple 3.1.3).

### 3.5 Placement when both appear

A page may have one `form` and one `button`. Keep them as two separate items
(iOS: `navigationItem.rightBarButtonItems`, form rightmost; Android: two toolbar menu
items, form `order` 999) — do not let one overwrite the other's
`rightBarButtonItem`. Remove both when the destination is torn down (the library
does this per destination). There is no `disconnect` message; a new page sends its
own `connect`.

### 3.6 Registering

The shell registers exactly the components it implements
(`Hotwire.registerBridgeComponents([FormComponent.self, MenuComponent.self, ButtonComponent.self, PurchaseComponent.self])`
/ `Hotwire.registerBridgeComponents(BridgeComponentFactory("form", ::FormComponent), …)`).
That list is what ends up in the UA and in `data-bridge-components`, which in turn
decides which web controls are hidden — never hard-code it on the web side.

## 4. Verification

- `core/tests/Feature/OwnerAppBridgeTest.php` — assets load only with the app UA
  (module + nonce + `data-turbo-eval="false"`), the vendored imports resolve without an
  import map, this document and `bridge.js` / `bridge.css` agree on names, events and
  payload keys.
- Browser harness run for PR (Chrome, enforced dashboard CSP, fake native adapter):
  `data-bridge-*` set on `<html>`, web Save / button hidden, trigger visible; `connect` /
  `display` payloads as above; a `selectedIndex` reply clicks the item; the native Save
  submits through Turbo and the form re-announces itself on the next page; web
  button disabled / removed → `submitDisabled`, back → `submitEnabled` / `connect`.
- **Verified on a real Android shell (2026-09-25, emulator API 35, `mobile/android`):**
  store settings (`/stores/{id}/edit`, modal sheet) sends `form` `connect {submitTitle}` →
  native Save in the toolbar → tap replies → the web submits → `submitDisabled` / `submitEnabled`
  round-trip observed; the stores list sends `button` `connect {title, iosImage: plus,
  androidImage: add}` → native "+" → tap opens `/stores/create` as a modal. The UA carries
  `bridge-components: [form menu button]`.
- **Not verified yet:** `menu` end to end — no page carries `bridge--menu` markup yet (the orders
  row "⋯" menu waits for the redesign, FORMS-AUDIT §4); the native `menu` sheet is built on both
  platforms. iOS components compile and run on the simulator (Hotwire Native 1.2.2 build, see
  IMPLEMENTATION-SPEC status); physical devices are the owner's check.
- **Not verified yet:** `purchase` end to end — the native `PurchaseComponent` (Swift / Kotlin) is
  the mobile lane's, and the billing screen (P5-22) is built against `owner-app/billing/overview`,
  which is not implemented yet.
