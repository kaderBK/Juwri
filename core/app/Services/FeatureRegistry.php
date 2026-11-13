<?php

// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Juwri\Core\Contracts\Billing\PlanCatalog;
use Juwri\Core\Features\PlatformFeatures;
use Juwri\Core\Http\Middleware\EnsureFeatureEnabled;
use Juwri\Core\Models\Feature;
use Juwri\Core\Support\RequestEpoch;

/**
 * GAPS-RUN-2 B0: the single source of truth for every superadmin-controllable
 * feature shipped by GAPS-RUN-1 and GAPS-RUN-2.
 *
 * Each entry drives BOTH the Feature Controls panel (resources/views/admin/
 * settings/feature-controls.blade.php) and the gate-enforcement audit test.
 *
 * The kill-switch write path already exists end-to-end:
 *   app_switch('admin_allow_<key>') → SettingsController@update (general_setting
 *   branch) → PlatformSettings::set → SettingsServiceProvider hydrates
 *   config('settings.admin_allow_<key>') → Entitlements::has() vetoes.
 *
 * `default` is how a MISSING Option row behaves — run-1 features default ON
 * (already live-toggleable per restaurant), run-2 features default OFF until
 * their track ships and flips the seed.
 */
class FeatureRegistry
{
    /** P1 (feature-platform): per-request memo of the table-backed catalog. */
    public const CACHE_KEY = 'features.registry.rows';

    private static ?array $tableCache = null;

    /* Cross-test leak guard: this memo holds `features` ROWS, which
       RefreshDatabase rolls back between tests — a class that seeded the table
       otherwise keeps answering for classes that did not. One app instance per
       request AND per test => rebind on a new app. Pinned by
       tests/Modules/Platform/StaticMemoAppScopeTest.php. */
    private static ?int $memoApp = null;

    /**
     * P1 (feature-platform): the `features` TABLE is now the source of truth;
     * the PHP array below is the seed + fallback (empty/absent table — e.g.
     * mid-migration or a not-yet-migrated install). Same shape as before so
     * the 57 existing call sites stay untouched.
     *
     * @return array<int, array{key:string,label:string,description:string,group:string,scope:string,default:bool,shipped:bool}>
     */
    public static function all(): array
    {
        self::rebindMemoOnNewApp();

        if (self::$tableCache !== null) {
            return self::$tableCache;
        }

        try {
            // P6-58: the catalogue is global (not per store) and changes only on admin edits, so it is
            // kept in the app cache across requests; Feature saved/deleted events and clearCache() drop it.
            $rows = Cache::remember(self::CACHE_KEY, 3600, fn () => Feature::query()
                ->whereNotNull('default_value')   // registry (kill-switchable) keys only
                ->where('killable', true)
                ->orderBy('position')
                ->get()
                ->map(fn ($f) => [
                    'key' => $f->key,
                    'label' => $f->label,
                    'description' => (string) $f->description,
                    'group' => $f->group_,
                    'scope' => 'global',
                    'default' => (string) $f->default_value === '1',
                    'shipped' => (bool) $f->shipped,
                ])->values()->all());

            if ($rows !== []) {
                return self::$tableCache = $rows;
            }
            Cache::forget(self::CACHE_KEY); // never pin an empty catalogue
        } catch (\Throwable $e) {
            // table absent → fallback below
        }

        return self::$tableCache = self::fallback();
    }

    /** Reset the per-request memo (tests, admin catalog edits). */
    public static function clearCache(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
            // P6-60: the plan-gated key list rides the same bust (any Feature write).
            Cache::forget(EnsureFeatureEnabled::CACHE_KEY);
        } catch (\Throwable) {
        }
        app()->forgetInstance('features.plan_gated');
        self::$tableCache = null;
        self::$grantingPlansCache = null;
    }

    /** Clears ONLY this class's memo — never another class's. */
    private static function rebindMemoOnNewApp(): void
    {
        $appId = RequestEpoch::id();
        if (self::$memoApp !== $appId) {
            self::$memoApp = $appId;
            self::$tableCache = null;
            self::$grantingPlansCache = null;
        }
    }

    /**
     * The legacy PHP catalog — now the migration seed + fallback.
     *
     * @return array<int, array{key:string,label:string,description:string,group:string,scope:string,default:bool,shipped:bool}>
     */
    public static function fallback(): array
    {
        return [
            // ---- GAPS-RUN-1 features (default ON) ----
            self::entry('scheduled_orders', 'Scheduled Orders', 'Let customers pick a future pickup/delivery slot.', 'run1', true, true),
            self::entry('gift_cards', 'Gift Cards', 'Sell and redeem stored-value gift cards.', 'run1', true, true),
            self::entry('aggregator_hub', 'Aggregator Hub', 'Inbound orders from delivery aggregators.', 'run1', true, true),
            self::entry('courier_dispatch', 'Courier Dispatch', 'Assign and track own-fleet couriers.', 'run1', true, true),
            self::entry('reservation_deposits', 'Reservation Deposits', 'Take a deposit when booking a table.', 'run1', true, true),
            self::entry('curbside', 'Curbside Pickup', 'Curbside "I\'m here" arrival flow.', 'run1', true, true),
            self::entry('ai_phone_agent', 'AI Phone Agent', 'Voice ordering agent for inbound calls.', 'run1', true, true),
            self::entry('gloriafood_import', 'GloriaFood Import', 'Import menus/orders from GloriaFood.', 'run1', true, true),

            // ---- GAPS-RUN-2 features (default OFF until their track ships) ----
            self::entry('pos_bridge', 'POS Bridge', 'Push orders to Square/Clover POS.', 'run2', false, true),
            self::entry('marketing_automation', 'Marketing Automation', 'Cart recovery + win-back campaigns.', 'run2', false, true),
            self::entry('accounting_sync', 'Accounting Sync', 'Sync invoices to Xero/QuickBooks.', 'run2', false, true),
            self::entry('gbp_link', 'Google Business Ordering', 'Order-food action link on Google Business Profile.', 'run2', false, true),
            self::entry('demand_forecast', 'Demand Forecasting', 'Delivery heat + demand/staffing forecasts.', 'run2', false, true),
            self::entry('digital_signage', 'Digital Signage', 'Menu/order-status display boards.', 'run2', false, true),

            // ---- DASHBOARD-UX P2-6 (default OFF) ----
            // WIRING-RUN A7: the assistant is fully built (drawer + DashboardController@assistant,
            // gated by abort_unless(isEnabled('ai_assistant'), 404)) but shipped=false kept it out
            // of Feature Controls, so no superadmin could ever turn it on. Default stays OFF.
            self::entry('ai_assistant', 'AI Dashboard Assistant', 'Grounded chat + peer benchmarks on the owner dashboard.', 'dashux', false, true),
            self::entry('staff_rota', 'Staff Rota & Payroll', 'Shift scheduling and payroll export.', 'run2', false, true),
            self::entry('catering', 'Catering Orders', 'Large-headcount catering request flow.', 'run2', false, true),
            self::entry('kiosk', 'Self-Order Kiosk', 'In-store kiosk ordering mode.', 'run2', false, true),
            self::entry('food_safety', 'Food Safety / HACCP', 'Temperature and compliance logs.', 'run2', false, true),
            self::entry('disputes', 'Chargebacks / Disputes', 'Stripe dispute tracking and alerts.', 'run2', false, true),
            self::entry('multivendor_cart', 'Multi-Vendor Cart', 'Cross-store single-cart checkout (gated).', 'run2', false, true),

            // ---- SYSTEMS-RUN features (default OFF; flip shipped=true when each track's route gate lands) ----
            self::entry('accounting_gl', 'Accounting (Built-in GL)', 'Double-entry general ledger, expenses, financial reports.', 'systems', false, true),
            self::entry('hrm_suite', 'HRM Suite', 'Employee records, documents vault, leave and time-off.', 'systems', false, true),
            self::entry('payroll_suite', 'Payroll', 'Pay periods, payslips, tip pooling, bank-file export.', 'systems', false, true),
            self::entry('crm_suite', 'CRM', 'Unified guest profiles, RFM, audience builder.', 'systems', false, true),
            self::entry('support_desk', 'Support Desk', 'Feedback inbox, WhatsApp shared inbox, merchant ticketing.', 'systems', false, true),
            self::entry('team_comms', 'Team Comms', 'Team chat, shift notes, checklists, rota upgrades.', 'systems', false, true),
            self::entry('driver_app', 'Driver App', 'Driver PWA, dispatch map, proof of delivery.', 'systems', false, true),
            self::entry('waiter_mode', 'Waiter Mode', 'Tableside ordering on the staff PWA.', 'systems', false, true),
            self::entry('supplier_suite', 'Supplier Suite', 'Receiving, COGS/variance, supplier invoice capture.', 'systems', false, true),

            // ---- TAX-RUN features: fiscalization master + per-country matrix (default OFF) ----
            self::entry('fiscalization', 'Fiscalization', 'MENA tax-authority e-invoicing / fiscal receipts (ZATCA, JoFotara, ETA + country profiles).', 'tax', false, true),
            self::entry('fiscal_sa', 'Fiscal: Saudi Arabia (ZATCA)', 'Enable the ZATCA Fatoora driver for KSA restaurants.', 'tax', false, false),
            self::entry('fiscal_jo', 'Fiscal: Jordan (JoFotara)', 'Enable the JoFotara ISTD driver for Jordan restaurants.', 'tax', false, false),
            self::entry('fiscal_eg', 'Fiscal: Egypt (ETA)', 'Enable the ETA e-Receipt driver for Egypt restaurants.', 'tax', false, false),
            self::entry('fiscal_ae', 'Fiscal: UAE', 'Enable the UAE (FTA) fiscal profile.', 'tax', false, false),
            self::entry('fiscal_om', 'Fiscal: Oman', 'Enable the Oman (OTA Fawtara) fiscal profile.', 'tax', false, false),
            self::entry('fiscal_bh', 'Fiscal: Bahrain', 'Enable the Bahrain (NBR) fiscal profile.', 'tax', false, false),
            self::entry('fiscal_tn', 'Fiscal: Tunisia', 'Enable the Tunisia (TTN) fiscal profile.', 'tax', false, false),
            self::entry('fiscal_ma', 'Fiscal: Morocco', 'Enable the Morocco (DGI) fiscal profile.', 'tax', false, false),
            self::entry('fiscal_qa', 'Fiscal: Qatar', 'Enable the Qatar (GTA) fiscal profile.', 'tax', false, false),
            self::entry('fiscal_iq', 'Fiscal: Iraq', 'Enable the Iraq fiscal profile (optional first-class sales tax).', 'tax', false, false),
            self::entry('fiscal_kw', 'Fiscal: Kuwait', 'Enable the Kuwait fiscal profile.', 'tax', false, false),
            self::entry('fiscal_dz', 'Fiscal: Algeria', 'Enable the Algeria fiscal profile.', 'tax', false, false),

            // ---- PAYMENTS-RUN G10: Paylink Partner API (default OFF; superadmin flips on) ----
            self::entry('paylink_partner', 'Paylink Partner Onboarding', 'Let restaurants onboard to Paylink payments from inside the dashboard (platform registers the merchant and auto-provisions keys).', 'payments', false, true),

            // ---- FEATURES-RUN-4 T1 hotel module (default OFF; superadmin sells per plan) ----
            self::entry('hotel', 'Hotel Guest Services', 'In-room dining, rooms/zones QR, service requests, charge-to-room folio and guest info hub.', 'hotel', false, true),

            // ---- DIRECTORY-RUN P0-1: the directory module master switch ----
            // Ships dark (D1): default OFF and shipped=false until T7-2, so
            // merging the module to main changes nothing in production.
            self::entry('directory', 'Business Directory', 'Public restaurant directory: explore/city/cuisine pages, store reviews, imported listings and business claims.', 'directory', false, true),

            // ---- PARITY-RUN features (T0-3). shipped=false until each track lands its
            // gate AND its assertion in tests/Feature/FeatureGateAuditTest. Kill-switch
            // defaults ON except table_otp, which changes how every customer reaches the
            // menu and is therefore opt-in per store. ----
            // PARITY-RUN T1 shipped: Settings → Printing carries `feature:print_routing`
            // and FeatureGateAuditTest asserts the refusal.
            self::entry('print_routing', 'Print Routing', 'Direct receipt/kitchen printing from the browser, Android (RawBT) or the desktop LAN bridge, with per-station routing and auto-print.', 'parity', true, true),
            // T2 SHIPPED: gate = FeatureAccess::storeAllows() inside OrderBoardController
            // (public page — never `feature:` middleware); audit assertion =
            // FeatureGateAuditTest::test_order_board_refuses_when_killed.
            self::entry('order_board', 'Order Status Board', 'Customer-facing preparing/ready board with daily token numbers.', 'parity', true, true),
            // T3 SHIPPED. `default` stays false — the kill-switch is opt-in per
            // store because this changes how every guest reaches the menu. The
            // gate assertion lives in tests/Feature/FeatureGateAuditTest.
            self::entry('table_otp', 'Verified Table QR', 'One-time-code table sessions that stop fake dine-in orders from off-premises scans.', 'parity', false, true),
            // T4 SHIPPED: gate = `feature:table_merge` on the owner's floor-map
            // merge routes + TableMergeService::enabled() on the register's
            // (staff guard ⇒ the subject must be the store owner). Assertions in
            // tests/Feature/FeatureGateAuditTest.
            self::entry('table_merge', 'Table Merge & Transfer', 'Merge two occupied tables onto one bill, or move an open order to another table.', 'parity', true, true),
            // T5 SHIPPED: gate = `feature:menu_schedules` on the /schedules owner
            // route group, PLUS a store-scoped service gate
            // (MenuScheduleService::enabled) for the anonymous storefront, which
            // a middleware cannot cover. Both asserted in FeatureGateAuditTest.
            self::entry('menu_schedules', 'Time-Based Menus & Pricing', 'Availability windows and scheduled prices — breakfast menus, happy hours, day-part pricing.', 'parity', true, true),
            // T6 SHIPPED: gates = `feature:combos` on the /combos owner route group
            // AND ComboService::enabled() on the anonymous storefront/order path.
            // Both asserted in tests/Feature/FeatureGateAuditTest.
            self::entry('combos', 'Combo Meals', 'Server-priced combo/meal-deal builder with choice groups and combo pricing.', 'parity', true, true),
            // T10 SHIPPED: gate = `feature:qr_designer` on the /qr-designer route group,
            // asserted in tests/Feature/FeatureGateAuditTest.
            self::entry('qr_designer', 'QR Design Suite', 'Branded QR templates, logo-in-QR, table-tent and bulk print sheets.', 'parity', true, true),
            // BIOLINK-RUN: link-in-bio page (Linktree-class, restaurant-native).
            // Ships DARK: `default` = false (kill-switch OFF at merge; superadmin flips
            // `settings.admin_allow_biolink` per plan later). `shipped` = true (F0): Lane B's
            // `feature:biolink` dashboard gate is merged (routes/features5/biolink.php) and the
            // FeatureGateAuditTest 'biolink' case is added — so the superadmin Feature Controls
            // list can surface + flip it. The PUBLIC bio page is gated in-controller via
            // FeatureAccess::storeAllows — NEVER `feature:` middleware on the storefront route.
            self::entry('biolink', 'Bio Page', 'Link-in-bio page — a Linktree-class landing with your menu, hours, links, socials, capture and click insights.', 'parity', false, true),
            // BIOLINK-TEMPLATES-RUN (Lane B): AI page generator (T1-4) + block
            // translate (T2-2). NEW plan feature key gating BOTH AI surfaces.
            // Ships DARK: `default` = false (kill-switch `admin_allow_ai_biolink`
            // OFF at merge; superadmin flips per plan). `shipped` = true (F0): its
            // `feature:ai_biolink` route gate + the FeatureGateAuditTest case land
            // in this run, so Feature Controls can surface + flip it. Like biolink
            // itself the features row is kill-only (plan_gated stays false); the
            // PLAN entitlement is enforced in-controller via plan_feature('ai_biolink')
            // + the superadmin plan-matrix app_switch. R0-3: needs the ship
            // migration inserting this row default OFF, else it ships OPEN in prod.
            self::entry('ai_biolink', 'Bio Page AI', 'AI that drafts a full bio page from your menu and translates every block into your other languages.', 'parity', false, true),
            // bio-t15 gap-fix: bio_pro / bio_analytics_pro exist only as DB rows from the
            // ship_bio_pro_and_analytics migration. Without a PHP fallback, any window where
            // that row is missing (pre-migration boot, or a stale FeatureRegistry table-cache
            // in a long-lived Octane/queue process) makes isEnabled() fail OPEN and hands out
            // the tip jar / premium appearance / analytics for free. These dark (default=false)
            // fallback entries make the gate fail CLOSED instead — matching ai_biolink.
            self::entry('bio_pro', 'Bio Page Pro Appearance', 'Premium bio-page appearance: image backgrounds, extra font pairing, and the tip-jar block.', 'parity', false, true),
            self::entry('bio_analytics_pro', 'Bio Page Analytics Pro', 'Country + referrer breakdowns and CSV export on the bio-page insights dashboard.', 'parity', false, true),
            // T7 SHIPPED: gate = `feature:aggregator_<key>` on each owner connection
            // page, PLUS a controller gate (FeatureAccess::storeAllows) on the partner
            // webhook, which is reached by an unauthenticated third party and can
            // therefore never carry middleware. Both asserted in FeatureGateAuditTest.
            self::entry('aggregator_zomato', 'Aggregator: Zomato', 'Zomato menu push, order webhook and status sync (bring your own partner credentials).', 'parity', true, true),
            self::entry('aggregator_swiggy', 'Aggregator: Swiggy', 'Swiggy menu push, order webhook and status sync (bring your own partner credentials).', 'parity', true, true),
            // T9 SHIPPED: gates = `feature:mobile_app_push` on the owner's
            // /mobile-app settings routes, PLUS MobilePushService::enabled()
            // (store-scoped) on device registration and delivery — the app and
            // the queue worker are not dashboard requests, so a middleware there
            // would refuse every install. Both asserted in FeatureGateAuditTest.
            self::entry('mobile_app_push', 'Mobile App & Push', 'Branded Capacitor mobile wrapper plus native push notifications for staff and guests.', 'parity', true, true),

            // INTEGRATIONS-PACK F0-2: two dark keys. `integrations` gates the
            // automation surfaces (webhooks UI + the Zapier / Make / n8n packages);
            // `aggregator_deliverect` is a STANDALONE per-provider aggregator key
            // (follows aggregator_zomato/swiggy — NOT coupled to aggregator_hub, which
            // gates a separate admin surface). Both ship DARK: default OFF and
            // `shipped:false` AT FOUNDATION. The INTEGRATOR flips `shipped:true` after
            // the lanes land the gates, in the same commit that adds each key's
            // FeatureGateAuditTest `$covered` entry + refusal assertion — setting
            // shipped:true before the gate exists fails that audit (see F0-2 /
            // ai_biolink precedent). plan_gated stays 0 until lane D wires the UI.
            // Group 'parity' is deliberate: the superadmin Feature Controls blade
            // renders a hardcoded heading per group, so a brand-new group would drop
            // these switches (FeatureControlsPanelTest). Kept with the aggregator
            // siblings, which already have a rendered heading.
            self::entry('integrations', 'Integrations & Automation', 'Webhooks plus Zapier, Make and n8n — send orders, customers and menu changes to the tools you already use.', 'parity', false, false),
            self::entry('aggregator_deliverect', 'Aggregator: Deliverect', 'One Deliverect connection brings Talabat, Jahez, HungerStation, Careem and more into your normal order queue.', 'parity', false, false),

            // ---- RESELLER-PAGES features (default OFF; superadmin flips on) ----
            self::entry('reseller_pages', 'Reseller Site Builder', 'Block composer for reseller landing + custom pages.', 'rpages', false, false),
            self::entry('reseller_blog', 'Reseller Blog', 'Multilingual blog for reseller white-label sites.', 'rpages', false, false),
            // BLOG-AUTOPILOT B4: AI topic ideas + full draft generation for the
            // reseller blog. Separate kill-switch on top of reseller_blog; the
            // per-reseller monthly cap comes from plan settings ai_blog_posts_limit.
            self::entry('reseller_blog_ai', 'Reseller Blog AI', 'AI topic ideas and full blog-article drafts for reseller white-label blogs (plan-capped).', 'rpages', false, false),

            // ---- MONETIZATION Wave B.1: modules gated for the first time ----
            // These live in the features TABLE (migration 2026_07_27_150000) and
            // ship default ON. They MUST also appear here: FeatureRegistry falls
            // back to this array whenever the table is empty (a no-RefreshDatabase
            // test, a fresh install mid-migrate), and the nav-gate + audit
            // contracts read shipped() from it — omit a shipped key and its
            // sidebar link 403s with no gate. default ON (nothing changes until
            // V0), shipped true (each carries its gate + audit assertion now).
            self::entry('inventory', 'Inventory & Stock', 'Stock levels, recipes and ingredient depletion, waste logging and low-stock alerts.', 'monetization', true, true),
            self::entry('analytics_pro', 'Analytics Pro', 'Cohorts, product mix, hour-of-day heat, branch comparison and exportable reports.', 'monetization', true, true),
            self::entry('backup_pro', 'Scheduled Backups', 'Automatic backups on a schedule with retention windows. Manual export stays free.', 'monetization', true, true),
            self::entry('media_library_pro', 'Media Library Pro', 'Bulk upload, folders, image optimisation and galleries shared across menus.', 'monetization', true, true),
            // Wave B.2a
            self::entry('kds', 'Kitchen Display (KDS)', 'Kitchen and bar screens with per-station routing, bump timers and course firing.', 'monetization', true, true),
            self::entry('floor_map', 'Floor Map', 'Visual table layout with live occupancy, server sections and drag-to-seat.', 'monetization', true, true),
            self::entry('reservations', 'Table Reservations', 'Booking calendar with party sizes, time slots, no-show tracking and guest notes.', 'monetization', true, true),
            self::entry('waitlist', 'Waitlist', 'Walk-in queue with quoted wait times and a "your table is ready" notification.', 'monetization', true, true),
            // Wave B10 — service-gated (store-credit issuance). Default ON.
            self::entry('customer_wallet', 'Customer Wallet', 'Store-credit wallets guests top up and spend. Balances already loaded stay spendable.', 'monetization', true, true),
            // Wave B6 — growth modules.
            self::entry('loyalty', 'Loyalty Program', 'Points, tiers and rewards that turn a first visit into a regular one.', 'monetization', true, true),
            self::entry('live_order_alerts', 'Live Order Alerts', 'Real-time order-status updates on the storefront notification bell — the diner sees "Ready" the moment the kitchen marks it, without reopening the tracking page.', 'monetization', true, true),
            self::entry('referrals', 'Referrals', 'Refer-a-friend links with automatic reward payout on the referred order.', 'monetization', true, true),
            self::entry('stories', 'Stories', 'Full-screen tappable story reels at the top of the storefront.', 'monetization', true, true),
            self::entry('banners', 'Promo Banners', 'Scheduled promotional banners and announcement strips across the storefront.', 'monetization', true, true),
            // Wave B7 — custom domain (route-gated on DomainController).
            self::entry('custom_domain', 'Custom Domain', 'Serve the menu on the restaurant\'s own domain with managed SSL.', 'monetization', true, true),
            // rev/multi-vertical F0-6 — sell non-restaurant verticals (retail, cosmetics,
            // clothing, car parts, laundry, pharmacy-OTC). Subject-gated in StoreController::store();
            // legacy restaurants are never gated. Owner grants it on paid tiers.
            // Theme Studio C1 — the visual storefront editor (feature:theme_studio on
            // every /dashboard/theme-studio route). Ships DARK (default OFF), sellable
            // per plan, plan_gated=0 until the owner flip (migration 2026_09_26_300000).
            // custom_sections (phase A) and
            // ai_theme_builder (phase D) are registered now so StudioCaps can read
            // them; shipped=false until their gates land.
            self::entry('theme_studio', 'Theme Studio', 'Visual storefront editor — add, arrange and style ready-made sections, then publish.', 'monetization', false, true),
            self::entry('custom_sections', 'Theme Studio Custom Sections', 'Owner-built custom blocks placed as Theme Studio sections.', 'monetization', false, false),
            self::entry('ai_theme_builder', 'AI Theme Builder', 'Describe your store and get a complete Theme Studio storefront to refine.', 'monetization', false, false),
            // Theme Studio E1 — superadmin-only AI section author (feature:ai_section_author on
            // /admin/theme-studio/sections). Dark (default OFF), platform_only, never sold
            // (migration 2026_09_27_120100).
            self::entry('ai_section_author', 'AI Section Author', 'Superadmin tool: AI writes new Theme Studio library sections behind automatic gates and human review.', 'monetization', false, false),
            self::entry('multi_vertical', 'Multi-Vertical Stores', 'Create non-restaurant stores — retail, cosmetics, clothing, car parts, laundry, OTC pharmacy and hotels — with vertical-specific catalogs and checkout.', 'monetization', true, true),
        ];
    }

    /** Registry keyed by feature key for O(1) lookup. */
    public static function keyed(): array
    {
        $out = [];
        foreach (self::all() as $entry) {
            $out[$entry['key']] = $entry;
        }

        return $out;
    }

    /** Only the SHIPPED features (the audit test asserts a gate for each of these). */
    public static function shipped(): array
    {
        return array_values(array_filter(self::all(), fn ($e) => $e['shipped']));
    }

    /** Resolved effective state of a kill-switch: stored Option value, else registry default. */
    public static function isEnabled(string $key): bool
    {
        // P1-07: answered by the Pennant-backed registry (platform scope); same result as before.
        return PlatformFeatures::active($key);
    }

    /**
     * PERF: per-request cache of the full ent_key => [plan labels] map. The nav's
     * upsell resolution calls grantingPlans() for ~40 keys per render, each firing
     * a PlanEntitlement + a Plan query; the catalog does not change within a
     * request. App-instance scoped (rebindMemoOnNewApp / clearCache), same guard
     * $tableCache uses.
     *
     * @var array<string, array<int, string>>|null
     */
    private static ?array $grantingPlansCache = null;

    /**
     * Names of plans that grant a feature key via typed entitlement rows.
     * Best-effort; empty when the table/rows are absent.
     */
    public static function grantingPlans(string $key): array
    {
        self::rebindMemoOnNewApp();

        if (self::$grantingPlansCache === null) {
            self::$grantingPlansCache = self::buildGrantingPlansMap();
        }

        return self::$grantingPlansCache[$key] ?? [];
    }

    /**
     * The whole ent_key => [plan labels] map (free/trial config plans first as
     * "Free plan"/"Trial plan", then live plan names). P1-13: plans are Billing's
     * (ee) — read through the {@see PlanCatalog} extension point (none in Community).
     *
     * @return array<string, array<int,string>>
     */
    private static function buildGrantingPlansMap(): array
    {
        return app(PlanCatalog::class)->grantingPlansMap();
    }

    private static function entry(string $key, string $label, string $desc, string $group, bool $default, bool $shipped): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'description' => $desc,
            'group' => $group,
            'scope' => 'global',
            'default' => $default,
            'shipped' => $shipped,
        ];
    }
}
