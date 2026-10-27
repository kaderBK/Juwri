---
title: "Data model"
audience: internal
group: Internal
updated: 2026-09-18
---

# Data model

Engineering reference for the core tables and how they relate. The schema is
MySQL, defined by migrations in `core/database/migrations/` and mapped by Eloquent
models in `core/app/Models/`. This page covers the load-bearing tables; many
feature tables (loyalty, referrals, A/B testing, SMS, campaigns, studio, apps)
follow the same tenant-scoping conventions and are not exhaustively listed.

Two naming quirks trip people up:

- **A restaurant is a `Post`.** The `posts` table (`core/app/Models/Post.php`)
  is the tenant record — a restaurant by default, but since the multi-vertical
  pack a `posts.store_type` discriminator (`restaurant` | `retail` | `cosmetic`
  | `clothing` | `carparts` | `laundry` | `pharmacy`) also lets it be a
  non-restaurant store. Foreign keys named `store_id` point at `posts.id`
  whatever the vertical. The word "restaurant" almost never appears as a table
  name. See [multi-vertical.md](multi-vertical.md).
- **A menu item is a `Menu`.** The `menu` table (`core/app/Models/Menu.php`) is a
  single sellable item, not a whole menu. `menu_categories` groups them.

Legacy tables predate Laravel conventions: `user`, `transaction`, and `menu` are
singular, and several use `integer('id', true)` signed keys rather than
`bigIncrements`. Newer tables use `id()` / `bigIncrements`.

## Accounts and tenancy

- **`user`** (`User`) — platform account / restaurant owner. Signed-int PK.
  `user_type` is `user` or `admin`; `group_id` (string, default `free`) is the
  legacy plan pointer; `balance` is the wallet balance (float). One user owns
  many `posts`.
- **`posts`** (`Post`) — the restaurant/tenant. `user_id` → `user`, plus `slug`
  (public storefront path `/{slug}`), `title`, images, `address`, `phone`,
  `timing`. `store_type` (VARCHAR(32), default `'restaurant'`, indexed) is the
  vertical discriminator; a null/legacy value reads as `restaurant`. `Post` uses
  the `BelongsToTenant` concern (`core/app/Models/Concerns/`).
  Most tenant data hangs off `posts.id` via `store_id`.
- **`branches`** (`Branch`) — physical location under a restaurant. `store_id` →
  `posts`, plus `name`, `address`, `phone`, `whatsapp`, `hours` (JSON), `active`,
  `position`. A restaurant has many branches; orders, staff and stock can be
  branch-scoped (see below).

Ownership chain: `user` → `posts` → `branches`, with catalog, orders and staff
attached at the `posts` (and optionally `branch`) level.

## Catalog: categories, items, variants, extras

- **`menu_categories`** (`MenuCategory`) — `store_id` → `posts`, `name`,
  self-referential `parent` for sub-categories, `position`, `translations`
  (JSON longtext). `active` added later.
- **`menu`** (`Menu`) — a menu item. `store_id` → `posts`, `category_id` →
  `menu_categories`, `name`, `price` (float), `image`, `type` (`veg`/`nonveg`),
  `active`, `position`, `translations`. Later migrations add dietary/rating,
  `is_sold_out`, daily-special, scheduled availability, nutrition, gallery
  images, inventory linkage, `station_id` (KDS), and cost-for-margin fields.
  The multi-vertical pack adds goods/service columns (all nullable, untouched by
  restaurants): `brand_id` (FK → `brands`, null-on-delete), `weight` (decimal,
  shipping weight), `dimensions` (text, L×W×H), `attributes` (JSON longtext, the
  per-vertical long-tail bucket — fabric, ingredients, car-parts OEM/cross-ref
  numbers), `pricing_unit` (`each`|`kg`; null reads as `each`), `turnaround_hours`
  (service SLA), and the OTC-compliance trio `age_restricted` (bool), `batch_no`,
  `expiry_date` (indexed). The pre-existing inventory columns `stock_qty` /
  `low_stock_threshold` / `track_inventory` are reused for goods stock, not
  re-added.
- **`modifier_groups`** + **`modifiers`** (P1-06; `Juwri\Modules\Menu\Models\ModifierGroup` /
  `Modifier`) — a group belongs to one item (`menu_id`) or to the store's library (`menu_id` null,
  `is_global`). `kind = variant`: a choice group (e.g. Size), read through the legacy `MenuVariantOption`
  model whose positional `options` / `option_prices` / `option_images` lists map to one `modifiers` row per
  option (`price_minor`). `kind = addon`: an item's add-ons or the store's add-on library; every add-on is a
  `modifiers` row read through the legacy `MenuExtra` model (its group is found or created on save).
  `Menu::variantOptions()` / `extras()`.
- **`item_modifier_group`** — library attachments (`Menu::attachedVariantOptions()` / `attachedExtras()`):
  a whole group (`modifier_id` null, per-item required/min/max overrides) or one add-on (`modifier_id` set,
  per-item `price_override_minor`).
- **`menu_variants`** (`MenuVariant`) — priced variants of an item (`options` = `{group id: option index}` or a
  matrix cell `{"Size": "M", "Color": "Red"}`) with their own `price_minor`, `sku`, `stock_qty` (null = not
  stock-tracked) and `barcode`. `Menu::variants()`.
- **`image_menus`** (`ImageMenu`) — photo/PDF-style menus attached to a restaurant.
- **`branch_menu`** (`BranchMenu`) — pivot for per-branch availability and price
  override: `branch_id`, `menu_id`, `available`, `price` (null = use base price),
  unique on `(branch_id, menu_id)`.
- **`brands`** (`Brand`) — per-store product brand for goods verticals (two
  unrelated stores each keep their own "Nike" row, not a global catalog).
  `store_id` → `posts` (cascade), `name`, `slug`, `logo`, `active`,
  `position`. Referenced by `menu.brand_id`.
- **`vehicle_fitments`** (`VehicleFitment`) — car-parts Year/Make/Model
  (/Submodel/Engine) fitment rows; one part fits many vehicles, hence a child
  table. `menu_id` → `menu` (cascade), `store_id` → `posts` (cascade,
  denormalized so tenant-safe YMM lookups need no join), `year`/`make`/`model`/
  `submodel`/`engine`/`notes`, plus a composite `(store_id, year, make,
  model)` index. OEM/cross-ref numbers live in `menu.attributes`, not here.

## Orders

- **`orders`** (`Order`) — `store_id` → `posts`, `type` — since the
  multi-vertical pack a `VARCHAR(32)` (was an `ENUM`), holding a value from
  `config('verticals.fulfillment')`: the legacy four
  (`on-table`/`takeaway`/`delivery`/`room_service`) plus the goods/service modes
  `ship`/`pickup_slot`/`appointment`/`in_store` — `customer_name`, `table_number`,
  `phone_number`, `address`, `status`, `is_paid`, `payment_gateway`. Status
  tracking migration widens `status` and adds `track_token` (unique, public
  tracking) and timestamp columns `preparing_at`, `ready_at`, `completed_at`,
  `cancelled_at`. Later additions: `customer_id`, coupon fields, `tip`/notes,
  `scheduled_at`, `branch_id`, `driver_id`, `client_uuid` (idempotency),
  WhatsApp cart-recovery, and POS fields (`source`, `payment_method`,
  `amount_tendered`, `change_due`, `service_charge_amount`, `pos_shift_id`,
  `created_by_staff_id`, `held_at`). The multi-vertical pack adds the
  goods/service board timestamps `packing_at`, `shipped_at`, `collected_at`
  (nullable); the food board's `ready_at`/`completed_at` are reused as-is.
- **`order_items`** (`OrderItem`) — `order_id` → `orders`, `item_id` → `menu`,
  `variation`, `quantity`, plus `weight_kg` (nullable decimal) for per-kg service
  lines (laundry), where `quantity` stays `1` and the weight rides here.
  `cart_lines.weight_kg` mirrors this pre-checkout. KDS fields added later.
  `OrderItemExtra` records chosen extras per line.
- **`order_adjustments`** (`OrderAdjustment`) — discounts/surcharges applied to
  an order.

Prices are recomputed server-side from the catalog at order time; `order_items`
stores selections (ids/quantities), not trusted client prices.

## Customers

- **`customers`** (`Customer`) — per-restaurant customer directory.
  `store_id` → `posts`, contact fields, `marketing_consent`, `tags` (JSON),
  aggregates `orders_count` / `total_spent` / `last_order_at`. Unique on
  `(store_id, email)` and `(store_id, phone)` — customers are scoped
  per tenant, not global.
- **`customer_addresses`** (`CustomerAddress`) — saved delivery addresses.
- SMS/WhatsApp consent and birthday columns are added by later migrations.

## Plans, subscriptions and billing

Billing has a **legacy layer** and a **v2 layer** that run in parallel (dual-write
during the plans rebuild — see `User::plan()` in `core/app/Models/User.php`).

Legacy:

- **`plans`** (`Plan`) — plan catalog: `name`, `monthly_price`, `annual_price`,
  `lifetime_price`, `settings` (text), `recommended`, `status`.
- **`upgrades`** (`Upgrade`) — legacy per-user subscription state: `user_id`,
  `pay_mode`, `interval`, `upgrade_lasttime`/`upgrade_expires` (unix), `status`.
  Dunning fields added by `2026_07_01_000008_add_dunning_fields_to_upgrades.php`.
- **`user.group_id`** — legacy pointer to the active plan id (fallback resolver).

v2 (`2026_07_07_200000_create_subscriptions_tables.php`):

- **`subscriptions`** (`Subscription`) — primary source of truth. `user_id`,
  `plan_id` (NULL = free), `status` (`trialing|active|past_due|paused|canceled|
  expired`), `interval_`, `interval_count`, `starts_at`, `ends_at` (NULL =
  lifetime), `anchor_day`, `grace_until`, `pending_plan_id`/`pending_interval`
  (scheduled change), `transaction_id`, `meta` (JSON entitlement snapshot for
  grandfathering).
- **`subscription_events`** (`SubscriptionEvent`) — audit log of status
  transitions: `event`, `from_status`/`to_status`, `actor_type`
  (`system|user|admin|gateway`).
- **`plan_prices`** (`PlanPrice`) — price per `(plan_id, interval_,
  interval_count)`.
- **`plan_entitlements`** (`PlanEntitlement`) — per-plan limits: `ent_key`,
  `int_value` (NULL = unlimited for count types), `bool_value` (flag types). Note
  the app-wide `999` sentinel also means "unlimited" in some code paths.

`User::plan()` reads the live subscription first, falls back to `group_id`, then
to the configured trial/free plan.

Transactions and wallet:

- **`transaction`** (`Transaction`) — payment records: `product_name`,
  `product_id`, `user_id`, `status` (`pending|success|failed|cancel`), `amount`,
  `base_amount`, `currency_code`, `payment_id`, `transaction_gatway` [sic],
  `frequency`, `coupon`, plus later `invoice_number`.
- **`wallet_transactions`** (`WalletTransaction`) — wallet ledger (top-ups,
  spends) feeding `user.balance`.
- **`user_addons`** (`UserAddon`) — purchased add-on packs on top of a plan;
  subscription fields added later.
- **`coupons`**, **`payment_gateways`**, **`taxes`** — discount codes, configured
  gateways, and tax rules.

## Staff and shifts

- **`restaurant_staff`** (`StaffMember`) — staff logins separate from `user`.
  `owner_id` (owning user), `store_id` → `posts`, `name`, `email` (unique),
  `password`, `pin` (KDS quick login), `role` (`manager|cashier|kitchen|waiter`),
  `permissions` (JSON overrides), `active`. `branch_id` and `default_branch_id`
  added by later migrations.
- **`pos_shifts`** (`PosShift`) — cash-drawer shifts referenced by
  `orders.pos_shift_id`.
- **`staff_tasks`**, **`staff_task_completions`**, **`staff_messages`**,
  **`staff_time_clock`**, **`staff_synced_ops`**, **`staff_tokens`** — task board,
  messaging, clock-in/out, offline op sync, and PWA auth tokens.

## Inventory

Ingredient-level inventory (`2026_06_30_500*` migrations):

- **`ingredients`** (`Ingredient`) — `store_id` → `posts`, `name`, `unit`,
  `current_stock`, `par_level`, `cost_per_unit`, `supplier_id`.
- **`stock_movements`** (`StockMovement`) — append-only ledger: `type`, signed
  `quantity`, `unit_cost`, `balance_after`, polymorphic-style
  `reference_type`/`reference_id` (`order`, `purchase_order`, `stock_count`,
  `waste_log`).
- **`recipe_items`** (`RecipeItem`) — bill of materials linking a `menu` item to
  the ingredients (and quantities) it consumes.
- **`suppliers`**, **`purchase_orders`**, **`purchase_order_items`** — procurement.
- **`stock_counts`**, **`stock_count_items`** — physical count sessions.
- **`waste_logs`** (`WasteLog`) — recorded waste.
- **`branch_stocks`** (`BranchStock`) — per-branch stock levels.

## Kitchen and floor

- **`kitchen_stations`** (`KitchenStation`) — KDS stations; items route via
  `menu.station_id`, and `branch_menu_station` maps branch/station.
- **`store_tables`** (`StoreTable`) — dine-in tables with floor-map
  coordinates, capacity range, status; multi-branch and reservation/section
  columns added by `2026_07_03_*` floor-map migrations.
- **`reservations`** (`Reservation`), **`waitlist_entries`** (`WaitlistEntry`),
  **`waiter_call`** (`WaiterCall`), **`table_checks`** (`TableCheck`).

## WhatsApp

Multi-provider WhatsApp suite (`2026_07_02_00000*` migrations, `WhatsApp*` models):

- **`whatsapp_routes`** (`WhatsAppRoute`) — provider/number routing config.
- **`whatsapp_log`** (`WhatsAppLog`) — outbound/inbound message log.
- **`whatsapp_inbound`** (`WhatsAppInbound`) — inbound messages.
- **`whatsapp_usage`** (`WhatsAppUsage`) — usage/billing counters.
- **`whatsapp_optouts`**, **`whatsapp_consent`** — opt-out and consent tracking.
- **`whatsapp_windows`** (`WhatsAppWindow`) — 24-hour session-window state.
- **`whatsapp_templates`** (`WhatsAppTemplate`) — approved message templates.
- **`whatsapp_events`** (`WhatsAppEvent`), **`whatsapp_number_health`**
  (`WhatsAppNumberHealth`) — delivery events and number reputation.

## Theme marketplace

Marketplace commerce (`2026_07_05_120000_create_marketplace_theme_tables.php` and
later):

- **`themes`** (`Theme`) — a marketplace theme. `seller_id` (NULL =
  platform-owned), `slug` (unique), `status` (`draft|review|published|delisted`),
  and `price` (added by the theme-purchases migration).
- **`theme_versions`** (`ThemeVersion`) — versioned uploads: `semver`, `manifest`
  (JSON), `checksum` (sha256 of the zip), `status`; review columns added later.
  Unique on `(theme_id, semver)`.
- **`theme_purchases`** (`ThemePurchase`) — per-account license: `user_id`,
  `theme_id`, `price_paid`, `wallet_ref` (links to a `WalletTransaction`), unique
  on `(user_id, theme_id)`.
- **`theme_settings`** (`ThemeSetting`) — per-restaurant applied theme settings;
  `theme_version_id` links to the installed version.
- **`seller_withdrawals`** (`SellerWithdrawal`), **`seller_stripe_accounts`**
  (`SellerStripeAccount`) — seller payouts and Stripe Connect state.

Related **`apps`** / **`app_installations`** (`App`, `AppInstallation`) power the
app-platform marketplace alongside themes.

## Loyalty and referrals

- **`loyalty_programs`** (`LoyaltyProgram`) — per-restaurant loyalty config:
  points-per-currency, reward thresholds. `LoyaltyProgram::forRestaurant()`.
- **`loyalty_accounts`** (`LoyaltyAccount`) — per-customer points balance.
  `earn()` / `redeem()` mutate the balance; `ledger()` returns the history.
- **`loyalty_ledger`** (`LoyaltyLedger`) — append-only points ledger entries
  linked to a `LoyaltyAccount`.
- **`referrals`** (`Referral`) — per-user referral program entry with a unique
  code. `user_id` → `user`. `conversions()` tracks sign-ups from the link.
- **`referral_conversions`** (`ReferralConversion`) — a referred sign-up:
  `referral_id` → `referrals`, `new_user_id` → `user`, `status`
  (`pending`/`approved`/`rejected`).

## Reseller / white-label

- **`resellers`** (`Reseller`) — a white-label reseller account. `owner_user_id`
  → `user`. `resolveForRequest()` matches the current domain/subdomain.
- **`reseller_plans`** (`ResellerPlan`) — plans a reseller offers to their
  merchants. `reseller_id` → `resellers`, `platform_plan_id` → `plans`.
- **`reseller_invoices`** (`ResellerInvoice`) — platform → reseller wholesale
  billing invoices. `reseller_id` → `resellers`.
- **`reseller_customer_subscriptions`** (`ResellerCustomerSubscription`) —
  reseller → merchant recurring billing state.
- **`reseller_announcements`** (`ResellerAnnouncement`) — reseller-scoped
  announcements shown to their merchants.
- **`reseller_audit_logs`** (`ResellerAuditLog`) — immutable audit trail per
  reseller action (`record()` static factory).
- **`reseller_email_templates`** (`ResellerEmailTemplate`) — per-reseller
  email template overrides (welcome, invoice, expiry).

## Marketplace extras

- **`marketplace_media`** (`MarketplaceMedia`) — polymorphic media attachments
  for marketplace entities (themes, apps). `mediable()` morph.
- **`marketplace_reviews`** (`MarketplaceReview`) — polymorphic user reviews on
  marketplace entities. `reviewable()` morph; `recomputeAggregates()` refreshes
  the parent's cached rating.

## Delivery

- **`delivery_orders`** (`DeliveryOrder`) — links a local `Order` to an
  external delivery aggregator (provider, external id, status, picking mode).
- **`drivers`** (`Driver`) — delivery driver accounts. `store_id` →
  `posts`, `owner_id` → `user`. `orders()` returns assigned deliveries.
- **`shipments`** (`Shipment`) — one ship-to-address shipment per goods order
  (multi-vertical). `order_id` → `orders` (cascade), `status`
  (`pending`→`shipped`→`delivered`, indexed), `carrier`, `tracking_number`,
  `shipped_at`, `delivered_at`. Deliberately simpler than `courier_deliveries`/
  `delivery_orders` — a "did it ship, what's the tracking number" record with no
  driver/geo dispatch.

## A/B testing

- **`ab_experiments`** (`AbExperiment`) — experiment definition: name, variants
  (JSON), status. `stats()` computes per-variant impression/conversion counts.
- **`ab_impressions`** (`AbImpression`) — per-visitor impression: experiment,
  variant, converted flag.

## Agents (AI ordering)

- **`agent_conversations`** (`AgentConversation`) — a chat session between a
  customer and the AI ordering agent. `store_id` → `posts`.
- **`agent_messages`** (`AgentMessage`) — individual messages in a conversation.
  `conversation_id` → `agent_conversations`.

## SEO

- **`slug_redirects`** (`SlugRedirect`) — 301 redirect map when a restaurant
  changes its slug. `old_slug` → `store_id`.

## Other feature tables

- **`media_assets`** (`MediaAsset`) — media library entries per restaurant.
  `store_id` → `posts`. `url` accessor resolves the public path.
- **`menu_banners`** (`MenuBanner`) — promotional banners on a restaurant's
  storefront. `store_id` → `posts`.
- **`menu_reviews`** (`MenuReview`) — per-item customer reviews.
  `menu_id` → `menu`, `store_id` → `posts`.
- **`nps_responses`** (`NpsResponse`) — Net Promoter Score survey responses per
  restaurant.

## Conventions and gotchas

- Tenant scoping is by `store_id` (= `posts.id`), enforced in app code via
  the `BelongsToTenant` concern, not by a DB-level tenant column on every table.
- Legacy singular table names: `user`, `transaction`, `menu`. Do not assume
  Laravel pluralization when writing raw queries.
- `menu.price` and legacy money columns are floats/doubles; newer money columns
  (`plan_prices`, POS, inventory, marketplace) are `decimal(_,2)`.
- Timestamps are mixed: some legacy tables use unix ints (`upgrades`) or single
  `created_at` (`orders`, `stock_movements`), not full `timestamps()`.
- Deleting a `Post` cascades to catalog, orders and customers via FK
  `onDelete('CASCADE')`.

For the ordering API surface see [Storefront & Customer APIs](../dev/learn-storefront-api.md);
for the platform overview see [Platform overview](../dev/learn-overview.md).
