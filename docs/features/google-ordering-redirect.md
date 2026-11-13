# Google Ordering Redirect

Google's **Ordering Redirect** sends a hungry customer from Google Search/Maps to *your own
ordering page*. juwri does not take the order on Google: it publishes the three feeds Google
needs and gives Google a stable deep link per order type. The customer taps the link, lands on
your storefront in the right language, and orders and pays with you as usual.

Everything here is **off by default**. Nothing is sent to Google or to any server until you
turn it on and set the feed upload credentials.

## Turning it on

Two switches must both be on: a platform switch (set by the juwri operator) and a per-store
switch (set by the store owner).

| Where | Key | Meaning |
|---|---|---|
| Platform (env) | `GOOGLE_ORDERING_ENABLED` | Master on/off for the whole install. Default `false`. |
| Platform (env) | `GOOGLE_ORDERING_DRIVER` | `fake` (default) or `real`. `real` is required before anything is uploaded. |
| Platform (env) | `GOOGLE_ORDERING_SFTP_HOST` / `_PORT` / `_USER` / `_KEY` / `_PATH` / `_ATTEMPTS` | The SFTP drop Google gives you at onboarding. Empty host = no upload. |
| Platform (env) | `GOOGLE_ORDERING_FEED_SHARD_SIZE` | Rows per feed file before it is split. Default `20000`. |
| Store option | `google_ordering_enabled` | Per-store switch. `'1'` includes the store in the feeds; anything else leaves it out. Default off. |

The store option is read exactly like the reservations one (`google_ordering_enabled === '1'`).
Until both switches are on, the command is a no-op and no links are advertised.

## The three feeds

Built by `google:ordering-feeds` into `storage/app/google-ordering/`. Each file has the same
metadata/envelope style as the Reserve-with-Google feeds.

- **`merchant_feed.json`** — one entry per eligible store: the store's business identity
  (name, address, phone, geo, opening hours, category). `merchant_id` is `store-<id>`. A store
  is eligible only when its `google_ordering_enabled` option is `'1'` **and** it has a
  representative active branch with latitude/longitude set.
- **`service_feed.json`** — one entry per order type the store offers, each carrying an
  **action link** (the deep link below). Order types come from the storefront's own ordering
  service (`StorefrontOrdering::orderTypes`), so the feed never advertises a type the store does
  not actually offer.
- **`menu_feed.json`** — the store's menu: categories, items, descriptions, images, dietary
  tags, availability and prices.

Prices are **integer minor units plus an ISO currency code**, taken from the **same** code the
storefront uses (`CatalogPresenter` / `ViewModelBuilder` / `MoneyFormat`). The feed and the
storefront therefore never disagree on a price or on availability. Every store-scoped query is
explicitly scoped to one store id, so two stores' data can never mix in one entry.

## The deep link

Each order type in the service feed points at a stable HTTPS link on the store's own address:

```
<store primary address>[/<locale>]/order/<order type>?utm_source=google&utm_medium=ordering_redirect
```

- **Address** — built with `StoreAddressResolver`, so it uses the store's custom domain, its
  reseller domain, or the platform host automatically, in whichever form that store serves.
- **Locale** — the language prefix is added only when the storefront uses language prefixes and
  the store's storefront is available in that language. No prefix is added when prefixes are off.
- **Order type** — the storefront ("kit") key for the core fulfilment mode.

The query `utm_source=google&utm_medium=ordering_redirect` lets your analytics attribute the
order to Google. The link is a plain HTTPS URL: no interstitial, no login wall, and no cookie
banner blocking the first load.

### Examples

A cafe on its own domain, English (no prefix):

| Order type | Link |
|---|---|
| Delivery | `https://shop.example.com/order/delivery?utm_source=google&utm_medium=ordering_redirect` |
| Takeaway / pickup | `https://shop.example.com/order/takeaway?utm_source=google&utm_medium=ordering_redirect` |
| Dine-in (on-table) | `https://shop.example.com/order/dine_in?utm_source=google&utm_medium=ordering_redirect` |

The same store in Arabic (`ar` prefix):

```
https://shop.example.com/ar/order/delivery?utm_source=google&utm_medium=ordering_redirect
```

A store on the platform host (path form `{slug}/order/{type}`):

```
https://app.juwri.com/example-cafe/order/delivery?utm_source=google&utm_medium=ordering_redirect
```

Order-type keys follow the storefront kit map: `takeaway`, `delivery`, `dine_in` (core
`on-table`), `pickup` (core `in_store`), plus `room_service`, `ship`, `pickup_slot`,
`appointment` and `at_home` where a store offers them. A link is only produced for a type the
store offers; otherwise there is no link.

### What the link does

Opening a link hits the public route `storefront.googleOrdering.link`
(`{slug}/order/{type}`, or `order/{type}` on the store's own host). It answers with a **302** to
the store's home page in the same locale:

- a type the store offers → `?order_type=<type>` plus any incoming `utm_*` parameters, so the
  storefront can preselect that order type and analytics see the campaign;
- an unknown or not-offered type → the store home without `order_type`;
- an unknown store → the normal 404.

There is no cookie banner, login or interstitial in the way of the redirect.

## The command

```
php artisan google:ordering-feeds [--no-upload]
```

- Runs nightly (schedule it like `rwg:feeds`).
- **Dark when off:** if `GOOGLE_ORDERING_ENABLED` is `false`, it prints a message and does
  nothing. No Google or SFTP traffic ever happens in tests or in a dark build.
- Writes a plain and a gzipped copy of each file to `storage/app/google-ordering/` so you can
  inspect them.
- Uploads via SFTP **only** when the driver is `real` and the SFTP settings are filled in.
  Use `--no-upload` to build and inspect without uploading.

### Reading the artifacts

Look in `storage/app/google-ordering/`. You will see `merchant_feed.json`,
`service_feed.json` and `menu_feed.json` (plus `.gz` copies, and extra numbered shards once a
feed grows past `GOOGLE_ORDERING_FEED_SHARD_SIZE`). The command clears old shards from the
previous run before writing new ones. Each file opens with a metadata block (feed name,
generated-at time, counts) followed by the entries. The validator is offline only — it checks
HTTPS links, required fields and minor-unit integer prices, and never calls Google.

## Not done yet

This work publishes the feeds, the deep links, the redirect route and the command. The
following remain **owner or Google work**, not code:

- **No owner dashboard toggle yet** — the per-store switch is set directly as the store option
  `google_ordering_enabled`; a UI control is a later item.
- **No real Google credentials, sandbox or certification** — the owner expresses interest in
  the Actions Center portal, creates the Google Cloud project/sandbox, and completes Google's
  certification and pilot. The SFTP details come from Google at onboarding.
- **Feed shape must be confirmed in the Actions Center portal.** The feeds follow Google's
  published Ordering Redirect documentation as summarised in
  the internal Google Actions evaluation notes; the partner portal and its policies are the final
  authority and can change.
