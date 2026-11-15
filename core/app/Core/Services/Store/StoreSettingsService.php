<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\Store;

use App\Core\Support\UploadSniffer;
use App\Models\Order;
use App\Models\StoreOption;
use App\Services\Storefront\StorefrontMenuCache;
use Illuminate\Http\Request;
use Juwri\Core\Contracts\Marketplace\Directory;
use Juwri\Core\Contracts\Stores\CountryCurrency;
use Juwri\Core\Contracts\Stores\StoreCatalogProbe;
use Juwri\Core\Contracts\Stores\UploadQuota;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\Tenancy\GlobalSlugService;
use Juwri\Core\Support\DeferredGateways;
use Juwri\Core\Support\ExternalRedirect;
use Juwri\Core\Support\Hooks;
use Juwri\Core\Support\SafeUrl;

/**
 * Single write-seam for store settings (F0-1, reseller-store).
 *
 * The owner editor (User\StoreController::update) and the reseller Store console
 * both persist store settings through this service, so validation/persistence
 * can never drift between the two surfaces (the settings/currency/theme-total
 * drift bug class). Reseller writes differ only by (a) tenancy fence upstream,
 * (b) role/entitlement gate upstream, (c) audit sink (passed in as a closure).
 *
 * `applyOwnerUpdate()` reproduces the old inline body byte-for-byte. The smaller
 * primitives (`applyOptions`, `applyCoreColumns`, `guardCurrencyChange`, …) are
 * the seam the reseller per-tab controllers compose partial saves from.
 */
class StoreSettingsService
{
    /**
     * Every option key the full store editor writes (identity + design +
     * ordering + tax + currency + bio + payments + storefront pages).
     * Order preserved from the original controller for a faithful diff.
     *
     * @var list<string>
     */
    /**
     * Money switches only the store's OWNER may change (owner decisions 2026-09-27 / 2026-10-07): automatic refunds to
     * the original payment, and the Tap + Moyasar opt-in on top of it. Never written by a staff session, the reseller
     * console or a fleet bulk edit (they all go through OPTION_KEYS, which leaves these out).
     */
    public const OWNER_KEYS = ['auto_refunds_enabled', 'auto_refunds_tap_moyasar'];

    /**
     * P6-108b: set on a new store to the currency it was given by default (the platform's), never by the owner. While the
     * store still has that currency, has no items and no orders, naming its country switches it to the country's currency
     * ({@see applyCountryCurrencyDefault()}). Stores without the marker (every store made before P6-108b) never change.
     */
    public const CURRENCY_DEFAULT_MARKER = 'currency_default_from';

    public const OPTION_KEYS = [
        'customer_auth_mode',
        // Days a diner may request a refund after ordering (0 = no limit). Paired
        // with the customer_wallet refund-request flow.
        'refund_request_window_days',
        // P3-08 (D7, N5): goods returns window (0 = none), returns refunds (off), back-in-stock messages (on)
        'returns_window_days',
        'refunds_returns',
        'back_in_stock_alerts',
        'store_template',
        'menu_layout',
        'default_language',
        // P6-289: store-level timezone override (the per-store option the owner
        // writes from edit.blade.php; falls back to the primary branch's
        // timezone, then app('timezone') — see store_tz() in StoreMoneyHelper).
        'timezone',
        'currency_code',
        'currency_sign',
        'currency_pos',
        'currency_rtl_auto',
        'enable_multi_currency',
        'currency_rates_manual',
        'currency_rates_mode',   // MC-33: manual | auto
        'display_currencies',    // MC-33: ISO codes for auto mode
        'allow_call_waiter',
        // #80: storefront reservations + waitlist. Every post_template gates its
        // booking UI on these; without a writer here the UI can never render.
        'enable_reservations',
        'enable_waitlist',
        'waitlist_avg_turn',
        'allow_multi_branch_switch',
        'allow_auto_user_info',
        'open_close_store',
        'prevent_order_on_close',
        'show_open_close_store',
        'restaurant_on_table_order',
        'restaurant_takeaway_order',
        'restaurant_delivery_order',
        'restaurant_delivery_charge',
        'restaurant_free_delivery_over',
        'restaurant_free_delivery_covers_branch_fee', // P5-34 (default ON)
        'restaurant_taxx_charge',
        'restaurant_tax_type',
        'restaurant_tax_label',
        'tax_engine', // P6-99b
        'tax_prices_include',
        'tax_delivery_taxable', // J32 / P6-116: per-fee VAT switches, levies, rate presets
        'tax_tray_taxable',
        'tax_service_taxable',
        'tax_levies',
        'tax_rate_preset',
        'tax_zero_vatex', // P6-175
        'tax_exempt_vatex',
        // TAX-RUN F0: per-restaurant fiscal identity (drives country profile).
        'fiscal_country',
        'fiscal_vat_number',
        'fiscal_cr_number',
        'fiscal_legal_name',
        'fiscal_street', // P6-176: KSA seller national address
        'fiscal_building_number',
        'fiscal_additional_number',
        'fiscal_district',
        'fiscal_city',
        'fiscal_postal_code',
        'allow_coupons',
        'allow_tips',
        'tip_presets', // K4: comma-separated tip % presets (e.g. "10,15,20")
        'allow_dietary_filters',
        'item_reviews_enabled', // owner switch for the "Rate this dish" widget (default ON)
        'offline_queue_enabled', // P3-03: store level of the offline order queue (default ON)
        'offline_queue_expiry_min', // P3-03: minutes a queued order lives (default 30)
        'order_lookup_by_number', // owner switch: let guests find orders by order number (default OFF)
        'agent_wallet_spend', // Coupons & Wallets: let AI agents spend store credit (decision B)
        'customer_auth_mode',
        'google_review_url',
        'public_theme_mode',
        'menu_font',
        'min_order_value',
        'allow_order_scheduling',
        // FEATURES-5 Track C: accept customer voice notes on orders (default OFF).
        'accept_voice_notes',
        // ZERO-TOUCH Track B3: auto-transcribe voice notes (default ON when capable).
        'voice_note_transcription',
        // ZERO-TOUCH Track C: static prep-time estimate fallback ("25-35").
        'prep_eta_static',
        // T1 scheduled-orders granular settings
        'scheduling_types',
        'scheduling_lead_minutes',
        'scheduling_slot_interval',
        'scheduling_max_days',
        'scheduling_prep_minutes',
        'scheduling_slot_capacity',
        'laundry_turnaround_hours', // P3-11
        // T2 gift cards
        'gift_cards_enabled',
        'gift_card_presets',
        'gift_card_min',
        'gift_card_max',
        'gift_card_expiry_months',
        // PARITY-RUN T2: public order board + daily token numbers.
        'order_board_enabled',
        'order_board_ready_minutes',
        'order_board_chime',
        'order_board_type_takeaway',
        'order_board_type_on_table',
        'order_board_type_delivery',
        'order_board_type_room_service',
        // T4 courier dispatch mode: manual | own | cheapest | overflow
        'dispatch_mode',
        // T5 reservation deposits
        'reservation_deposit_enabled',
        'reservation_deposit_amount',
        'reservation_deposit_per',
        'reservation_deposit_min_party',
        'reservation_deposit_refund',
        'reservation_deposit_refund_pct',
        // T5 curbside pickup
        'curbside_enabled',
        'store_submit_order_notification',
        'restaurant_online_payment',

        'restaurant_paypal_install',
        'restaurant_paypal_title',
        'restaurant_paypal_sandbox_mode',
        'restaurant_paypal_api_client_id',
        'restaurant_paypal_api_secret',
        'restaurant_paypal_api_app_id',
        'restaurant_paypal_webhook_id', // the store's own PayPal webhook (webhook/paypal/store/{store})

        'restaurant_stripe_install',
        'restaurant_stripe_title',
        'restaurant_stripe_secret_key',
        'restaurant_stripe_publishable_key',
        'restaurant_stripe_webhook_secret', // the store's own Stripe webhook signing secret

        'restaurant_razorpay_install',
        'restaurant_razorpay_title',
        'restaurant_razorpay_api_key',
        'restaurant_razorpay_secret_key',
        'restaurant_razorpay_webhook_secret',

        'restaurant_mollie_install',
        'restaurant_mollie_title',
        'restaurant_mollie_api_key',

        'restaurant_paytm_install',
        'restaurant_paytm_title',
        'restaurant_paytm_sandbox_mode',
        'restaurant_paytm_merchant_key',
        'restaurant_paytm_merchant_mid',
        'restaurant_paytm_merchant_website',

        'restaurant_paystack_install',
        'restaurant_paystack_title',
        'restaurant_paystack_secret_key',
        'restaurant_paystack_public_key',

        'restaurant_payumoney_install',
        'restaurant_payumoney_title',
        'restaurant_payumoney_sandbox_mode',
        'restaurant_payumoney_merchant_pos_id',
        'restaurant_payumoney_signature_key',
        'restaurant_payumoney_oauth_client_id',
        'restaurant_payumoney_oauth_client_secret',

        'restaurant_iyzico_install',
        'restaurant_iyzico_title',
        'restaurant_iyzico_sandbox_mode',
        'restaurant_iyzico_api_key',
        'restaurant_iyzico_secret_key',

        'restaurant_midtrans_install',
        'restaurant_midtrans_title',
        'restaurant_midtrans_sandbox_mode',
        'restaurant_midtrans_client_key',
        'restaurant_midtrans_server_key',

        'restaurant_paytabs_install',
        'restaurant_paytabs_title',
        'restaurant_paytabs_region',
        'restaurant_paytabs_profile_id',
        'restaurant_paytabs_secret_key',

        'restaurant_telr_install',
        'restaurant_telr_title',
        'restaurant_telr_sandbox_mode',
        'restaurant_telr_store_id',
        'restaurant_telr_authkey',

        'restaurant_2checkout_install',
        'restaurant_2checkout_title',
        'restaurant_2checkout_sandbox_mode',
        'restaurant_2checkout_account_number',
        'restaurant_2checkout_public_key',
        'restaurant_2checkout_private_key',

        'restaurant_ccavenue_install',
        'restaurant_ccavenue_title',
        'restaurant_ccavenue_merchant_key',
        'restaurant_ccavenue_access_code',
        'restaurant_ccavenue_working_key',

        // #65: regional gateways
        'restaurant_tap_install',
        'restaurant_tap_title',
        'restaurant_tap_sandbox_mode',
        'restaurant_tap_secret_key',
        'restaurant_tap_publishable_key',

        // Phase 4 item 18: Moyasar (KSA)
        'restaurant_moyasar_install',
        'restaurant_moyasar_title',
        'restaurant_moyasar_secret_key',
        'restaurant_moyasar_publishable_key',
        'restaurant_moyasar_webhook_secret',

        'restaurant_flutterwave_install',
        'restaurant_flutterwave_title',
        'restaurant_flutterwave_sandbox_mode',
        'restaurant_flutterwave_secret_key',
        'restaurant_flutterwave_public_key',

        // TDSP / T-Lync (Libya)
        'restaurant_tdsp_install',
        'restaurant_tdsp_title',
        'restaurant_tdsp_sandbox_mode',
        'restaurant_tdsp_store_id',
        'restaurant_tdsp_token',

        // PAYMENTS-RUN: BNPL eligibility controls
        'restaurant_bnpl_enabled',
        'restaurant_bnpl_min_total',

        // PAYMENTS-RUN: Paylink / STC Pay (KSA)
        'restaurant_paylink_install',
        'restaurant_paylink_title',
        'restaurant_paylink_sandbox_mode',
        'restaurant_paylink_api_id',
        'restaurant_paylink_secret_key',

        // PAYMENTS-RUN: Paymob (EG / KSA / UAE / Oman)
        'restaurant_paymob_install',
        'restaurant_paymob_title',
        'restaurant_paymob_sandbox_mode',
        'restaurant_paymob_secret_key',
        'restaurant_paymob_public_key',
        'restaurant_paymob_integration_id',
        'restaurant_paymob_hmac_secret',
        'restaurant_paymob_region', // P6-108b: eg | ksa | uae | omn; blank = from the store country

        // PAYMENTS-RUN: Geidea (KSA)
        'restaurant_geidea_install',
        'restaurant_geidea_title',
        'restaurant_geidea_sandbox_mode',
        'restaurant_geidea_merchant_public_key',
        'restaurant_geidea_api_password',

        // PAYMENTS-RUN: Tamara (BNPL)
        'restaurant_tamara_install',
        'restaurant_tamara_title',
        'restaurant_tamara_sandbox_mode',
        'restaurant_tamara_api_token',
        'restaurant_tamara_notification_token',

        // PAYMENTS-RUN: Tabby (BNPL)
        'restaurant_tabby_install',
        'restaurant_tabby_title',
        'restaurant_tabby_sandbox_mode',
        'restaurant_tabby_secret_key',
        'restaurant_tabby_public_key',
        'restaurant_tabby_merchant_code',

        // PAYMENTS-RUN: Fawry (Egypt)
        'restaurant_fawry_install',
        'restaurant_fawry_title',
        'restaurant_fawry_sandbox_mode',
        'restaurant_fawry_merchant_code',
        'restaurant_fawry_security_key',

        // PAYMENTS-RUN: Fatora (Qatar)
        'restaurant_fatora_install',
        'restaurant_fatora_title',
        'restaurant_fatora_sandbox_mode',
        'restaurant_fatora_api_key',

        // PAYMENTS-RUN: Noon Payments (KSA)
        'restaurant_noon_install',
        'restaurant_noon_title',
        'restaurant_noon_sandbox_mode',
        'restaurant_noon_business_id',
        'restaurant_noon_application_id',
        'restaurant_noon_api_key',
        // WIRING-RUN E2: per-restaurant noon webhook signing secret.
        'restaurant_noon_webhook_secret',

        // Syria: wallet_transfer (manual wallet tender, no secrets)
        'restaurant_wallet_transfer_install',
        'restaurant_wallet_transfer_title',
        'restaurant_wallet_transfer_syriatel_number',
        'restaurant_wallet_transfer_mtn_number',
        'restaurant_wallet_transfer_shamcash_number',
        'restaurant_wallet_transfer_instructions',

        // P1-23 part 6: bank (wire) transfer for store payments — the store's OWN bank details (no secrets)
        'restaurant_wire_transfer_install',
        'restaurant_wire_transfer_title',
        'restaurant_wire_transfer_bank_info',

        // Syria: eCash (licensed acquirer)
        'restaurant_ecash_install',
        'restaurant_ecash_title',
        'restaurant_ecash_sandbox_mode',
        'restaurant_ecash_merchant_id',
        'restaurant_ecash_terminal_id',
        'restaurant_ecash_api_key',

        // Syria: QRPay (second acquirer)
        'restaurant_qrpay_install',
        'restaurant_qrpay_title',
        'restaurant_qrpay_sandbox_mode',
        'restaurant_qrpay_client_id',
        'restaurant_qrpay_secret_id',

        // #101 Meta: Pixel / Conversions API + presence
        'meta_pixel_id',
        'meta_capi_token',
        'instagram_url',
        'facebook_url',

        // #104 SMS: transactional customer alerts (owner opt-in)
        'customer_order_sms',
        'customer_status_sms',

        // brand-row B3: follower notifications (new dish / offer) — DARK, default OFF
        'follower_notify_enabled',

        // P3-06b (API-29): message the customer when they cancel a pending payment — default OFF
        'notify_customer_pending_payment_cancelled',

        // #63 birthday offers
        'birthday_offers_enabled',
        'birthday_reward_percentage',
        'birthday_validity_days',
        'birthday_channel',

        // #34 AI translate: glossary + tone
        'translation_tone',
        'translation_glossary',

        // Storefront info pages (Terms/Privacy/About/Help) — plain text,
        // surfaced in every theme's account footer + at /page/{slug}.
        'sf_page_terms',
        'sf_page_privacy',
        'sf_page_about',
        'sf_page_help',
    ];

    /**
     * Post table columns written by the identity/bio full-form save.
     *
     * @var list<string>
     */
    public const CORE_COLUMNS = [
        'color', 'title', 'sub_title', 'description', 'timing', 'phone', 'address',
        // Legacy welcome-page columns (bio_title/description, link*, bio_color_*)
        // retired in Phase 3 — the content lives on the new biolink now.
    ];

    /**
     * Reproduce the owner full-form store update. Returns an error message the
     * caller should surface with a redirect-back (bad redirect URL / unconfirmed
     * currency change), or null on success.
     *
     * $audit is called for side-channel audit events:
     *   $audit(string $action, Post $store, array $meta): void
     * Defaulting to log_activity keeps the owner path byte-identical.
     */
    public function applyOwnerUpdate(Store $store, Request $request, ?callable $audit = null): ?string
    {
        $audit ??= function (string $action, Store $store, array $meta): void {
            log_activity($action, $store, $meta, $store->id);
        };

        // rev/external-redirect B1: persist the opt-out redirect fields (shared,
        // validated, audited, sitemap-busting path; a bad URL aborts before writes).
        if ($redirectError = ExternalRedirect::saveFromRequest($store, $request)) {
            return $redirectError;
        }

        // P6-321: validate the menu-font upload before any writes so an invalid,
        // oversized or unrecognised font is rejected with an error instead of the
        // owner being told "Saved" while the file is silently dropped.
        if ($request->hasFile('menu_font_file')) {
            $fontFile = $request->file('menu_font_file');
            $fontSize = $fontFile->isValid() ? (int) $fontFile->getSize() : 0;
            if (! $fontFile->isValid()
                || $fontSize > 2 * 1024 * 1024
                || UploadSniffer::font((string) file_get_contents($fontFile->getRealPath(), false, null, 0, 4)) === null) {
                return ___('Use a .woff2 font under 2 MB.');
            }
            if (! app(UploadQuota::class)->allow((int) $store->user_id, $fontSize)) {
                return ___('Storage limit reached. Delete some files or upgrade your plan.');
            }
        }

        // P6-108b: a store whose currency the owner never chose takes its country's currency.
        $this->applyCountryCurrencyDefault($store, $request);

        // MC-12: guard a currency change on a store that already has orders.
        if ($currencyError = $this->guardCurrencyChange($store, $request->all(), $audit)) {
            return $currencyError;
        }

        $images = $this->uploadEditorImages($store, $request);

        $columns = [];
        foreach (self::CORE_COLUMNS as $col) {
            $columns[$col] = $request->get($col);
        }
        // MCP-expansion P2-5: platform Catalog MCP opt-out (default visible).
        $columns['catalog_visible'] = $request->boolean('catalog_visible');

        // Slug: keep the submitted slug, else derive one from the title with collision retry (BL-047).
        GlobalSlugService::writeWithUniqueSlug(
            (string) $request->title,
            function (string $slug) use ($store, $columns, $images): void {
                $columns['slug'] = $slug;
                $this->applyCoreColumns($store, array_merge($columns, $images));
            },
            ! empty($request->slug) ? (string) $request->slug : null,
        );

        // DIRECTORY L1 T1-1: sync the cuisine picks (already capped at 3 by the
        // validator). While the module is off the picker is hidden, so `cuisines`
        // never arrives and this is a no-op — no silent pivot writes.
        if (app(Directory::class)->enabled()) { // P1-13 13d: the directory gate (Marketplace, ee; Community: off)
            $store->cuisines()->sync($request->input('cuisines', []));
        }

        StoreOption::updateStoreOption($store->id, 'menu_languages',
            json_encode($request->get('menu_languages')));

        $options = [];
        foreach (self::OPTION_KEYS as $key) {
            $options[$key] = $request->get($key);
        }
        if (! is_staff_dashboard() && (int) auth()->id() === (int) $store->user_id) {
            foreach (self::OWNER_KEYS as $key) {
                if ($request->has($key)) {
                    $options[$key] = $request->boolean($key) ? '1' : '0';
                }
            }
        }
        $this->applyOptions($store, $options);

        if ($request->has('menu_sections_json')) {
            StoreOption::updateStoreOption($store->id, 'menu_sections', $request->input('menu_sections_json'));
        }

        $this->handleMenuFont($store, $request);

        $audit('store.update', $store, []);

        return null;
    }

    /**
     * Currency-change guard (MC-12). Returns an error string when a change is
     * attempted on a store with existing orders and the confirm box is unticked;
     * otherwise records the change (when applicable) and returns null.
     *
     * @param  array<string, mixed>  $input
     */
    public function guardCurrencyChange(Store $store, array $input, ?callable $audit = null): ?string
    {
        $audit ??= function (string $action, Store $store, array $meta): void {
            log_activity($action, $store, $meta, $store->id);
        };

        $currentCurrency = strtoupper((string) (store_options($store->id, 'currency_code') ?: ''));
        $newCurrency = strtoupper((string) ($input['currency_code'] ?? ''));

        if ($currentCurrency !== '' && $newCurrency !== '' && $newCurrency !== $currentCurrency
            && Order::where('store_id', $store->id)->exists()) {
            $confirmed = filter_var($input['confirm_currency_change'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if (! $confirmed) {
                return ___('Changing the currency does not convert existing prices or past order totals. Tick the box to confirm.');
            }
            $audit('store.currency_changed', $store, [
                'from' => $currentCurrency,
                'to' => $newCurrency,
            ]);
        }

        return null;
    }

    /** P6-108b: the currency of a supported store country (its fiscal profile), or null. */
    public static function countryCurrency(?string $country): ?string
    {
        return app()->bound(CountryCurrency::class) ? app(CountryCurrency::class)->currencyFor($country) : null;
    }

    /**
     * P6-108b: when the store's currency is still the default it was created with (the marker) and the save names a
     * supported country, the request's currency (code + sign) becomes the country's. A submitted currency that differs
     * from the stored one is the owner's choice: the marker is cleared and the default never applies again. Never on a
     * store that already has items or orders (their amounts are in the old currency).
     */
    public function applyCountryCurrencyDefault(Store $store, Request $request): void
    {
        $marker = strtoupper((string) (store_options($store->id, self::CURRENCY_DEFAULT_MARKER) ?: ''));
        if ($marker === '') {
            return;
        }
        $stored = strtoupper((string) (store_options($store->id, 'currency_code') ?: ''));
        $submitted = $request->input('currency_code');
        $submitted = is_scalar($submitted) ? strtoupper(trim((string) $submitted)) : '';
        if ($stored !== $marker || ($submitted !== '' && $submitted !== $stored)) {
            StoreOption::updateStoreOption($store->id, self::CURRENCY_DEFAULT_MARKER, ''); // chosen elsewhere: hands off

            return;
        }
        $country = $request->input('fiscal_country');
        $currency = self::countryCurrency(is_scalar($country) ? (string) $country : null);
        if ($currency === null) {
            return; // no country yet: keep waiting
        }
        if (Order::where('store_id', $store->id)->exists() || (! app()->bound(StoreCatalogProbe::class) || app(StoreCatalogProbe::class)->hasItems((int) $store->id))) {
            return;
        }
        $request->merge(['currency_code' => $currency, 'currency_sign' => currency_symbol_for($currency)]);
        StoreOption::updateStoreOption($store->id, self::CURRENCY_DEFAULT_MARKER, '');
    }

    /**
     * Write Post table columns from a key=>value map (only the keys present).
     *
     * @param  array<string, mixed>  $columns
     */
    public function applyCoreColumns(Store $store, array $columns): void
    {
        if ($columns !== []) {
            $store->update($columns);
            // SEO/perf: a core-column change (title, colour, sub_title…) alters the
            // rendered storefront — bump the store's cache token so the menu-payload
            // cache, ETag AND the full-page cache all invalidate.
            StorefrontMenuCache::bust($store->id);
        }
    }

    /**
     * Write a map of post_option key=>value for a store. Normalizes the SAR
     * currency sign codepoint (S4) when both keys are present in the map.
     *
     * @param  array<string, mixed>  $options
     */
    public function applyOptions(Store $store, array $options): void
    {
        $options = DeferredGateways::filterOptions($options);
        // P6-186 (J44): Ordering may drop a locked setting and act after the write (tax method change)
        $hook = Hooks::answer('store.options.saving', (int) $store->id, $options);
        if (is_array($hook) && is_array($hook['options'] ?? null)) {
            $options = $hook['options'];
        }
        foreach ($options as $key => $value) {
            // S4: rewrite wrong riyal codepoints (U+20C0/U+E900) to the canonical
            // U+20C1 when the store's currency is SAR.
            if ($key === 'currency_sign') {
                $currencyCode = $options['currency_code'] ?? store_options($store->id, 'currency_code');
                $value = normalize_currency_sign((string) $value, (string) $currencyCode);
            }
            // SEO (FIX 3): a scheme-less social URL ("instagram.com/x", "@name")
            // renders as a RELATIVE href → juwri.com/<url> → 404. Store it as
            // an absolute https:// URL (or a dead '#' for un-linkable junk).
            if (in_array($key, ['instagram_url', 'facebook_url'], true) && is_string($value) && trim($value) !== '') {
                $value = external_url($value);
            }
            // google_review_url is echoed into a public href and redirected to by the
            // review funnel: keep http(s) only (javascript:/data: would be stored XSS).
            if (in_array($key, ['google_review_url', 'app_store_url', 'play_store_url'], true) && is_string($value)) {
                $value = SafeUrl::web($value) ?? '';
            }
            StoreOption::updateStoreOption($store->id, $key, $value);
        }
        if (is_array($hook) && is_callable($hook['after'] ?? null)) {
            ($hook['after'])();
        }

        // A settings change alters the storefront — invalidate the store's caches
        // (menu payload + ETag + full-page cache) via the shared per-store token.
        StorefrontMenuCache::bust($store->id);
    }

    /**
     * Handle the editor image uploads (logo/cover + bio logo/cover), reusing the
     * shared image pipeline. Returns the column map to merge into the core write.
     *
     * @return array<string, string>
     */
    public function uploadEditorImages(Store $store, Request $request): array
    {
        $out = [];

        if ($request->has('cover_image')) {
            $current = $store->cover_image;
            $out['cover_image'] = image_upload($request->file('cover_image'), 'storage/store/cover/', null, null,
                $current != 'default.png' ? $current : null);
        }
        if ($request->has('main_image')) {
            $current = $store->main_image;
            $out['main_image'] = image_upload($request->file('main_image'), 'storage/store/logo/', '500x500', null,
                $current != 'default.png' ? $current : null);
        }
        // bio_cover_image / bio_main_image uploads retired in Phase 3 (legacy
        // welcome page). The biolink has its own avatar/cover via the media library.

        return $out;
    }

    /**
     * #43: custom menu-font upload (woff2 only, max 2 MB) or removal.
     */
    public function handleMenuFont(Store $store, Request $request): void
    {
        if ($request->hasFile('menu_font_file')) {
            $fontFile = $request->file('menu_font_file');
            $ownerId = (int) $store->user_id;
            if (! $fontFile->isValid()) {
                return;
            }
            $path = $fontFile->getRealPath();
            if ($path === false) {
                return;
            }
            // P6-78: judge by the file's magic bytes, never the client extension; the stored name is server-chosen.
            $ext = UploadSniffer::font((string) file_get_contents($path, false, null, 0, 4));
            if ($ext !== null && $fontFile->getSize() <= 2 * 1024 * 1024) {
                $fontSize = $fontFile->getSize();
                $fontFilename = 'font_'.$store->id.'_'.bin2hex(random_bytes(8)).'.'.$ext;
                $fontFile->move(public_path('storage/fonts/'), $fontFilename);
                app(UploadQuota::class)->add($ownerId, $fontSize);
                $oldFont = StoreOption::getStoreOption($store->id, 'menu_font_file');
                if ($oldFont) {
                    $oldPath = public_path('storage/fonts/'.$oldFont);
                    app(UploadQuota::class)->sub($ownerId, (int) @filesize($oldPath));
                    @unlink($oldPath);
                }
                StoreOption::updateStoreOption($store->id, 'menu_font_file', $fontFilename);
                StoreOption::updateStoreOption($store->id, 'menu_font', '');
            }
        } elseif ($request->get('remove_menu_font_file') === '1') {
            $oldFont = StoreOption::getStoreOption($store->id, 'menu_font_file');
            if ($oldFont) {
                $oldPath = public_path('storage/fonts/'.$oldFont);
                app(UploadQuota::class)->sub((int) $store->user_id, (int) @filesize($oldPath));
                @unlink($oldPath);
            }
            StoreOption::updateStoreOption($store->id, 'menu_font_file', '');
        }
    }
}
