{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Edit Store'))
@section('page_title', ___('Store settings'))
@section('page_sub', $store->title)

@section('page_actions')
    @php
        // rev/multi-vertical: mirror the dashboard card (posts/index.blade.php)
        // so this CTA routes a retail/laundry store to ITS OWN catalog editor
        // instead of the food-only menu editor, with a vocab-aware label.
        $itemForm = app(\App\Services\Verticals\VerticalRegistry::class)->itemForm($store->store_type ?? null);
    @endphp
    @if($itemForm === 'retail' && Route::has('retail.products.index'))
        <x-ui.button :href="route('retail.products.index', $store->id)" icon-left="pencil">
            {{ app(\App\Services\Verticals\VerticalVocab::class)->forStore($store, 'items') }}
        </x-ui.button>
    @elseif($itemForm === 'service' && Route::has('laundry.services.index'))
        <x-ui.button :href="route('laundry.services.index', $store->id)" icon-left="pencil">
            {{ app(\App\Services\Verticals\VerticalVocab::class)->forStore($store, 'items') }}
        </x-ui.button>
    @elseif(Route::has('stores.menu'))
        <x-ui.button :href="route('stores.menu', $store->id)" icon-left="pencil">
            {{ ___('Edit menu') }}
        </x-ui.button>
    @endif
    {{-- rev/multi-vertical B1: non-food verticals get a Fulfillment settings
         link here (their "Fulfillment" tab). Restaurants never see this —
         isFood() is true for every legacy store, so this is a pure addition. --}}
    @if(Route::has('stores.fulfillment.edit') && !app(\App\Services\Verticals\VerticalRegistry::class)->isFood($store->store_type ?? null))
        <x-ui.button :href="(route('stores.fulfillment.edit')).'?store_id='.($store->id)" variant="secondary" icon-left="truck">
            {{ ___('Fulfillment') }}
        </x-ui.button>
    @endif
    {{-- rev/multi-vertical Lane D3/D4: laundry's own service board (relabeled
         states + weight-capture) — never Lane B's "Fulfilment" board (Lane D
         does not fork/edit that view; see LaundryServiceBoardController). --}}
    @if(Route::has('stores.service_board.index') && app(\App\Services\Verticals\VerticalRegistry::class)->has($store->store_type ?? null, 'service'))
        <x-ui.button :href="(route('stores.service_board.index')).'?store_id='.($store->id)" variant="secondary" icon-left="clipboard-list">
            {{ ___('Service board') }}
        </x-ui.button>
    @endif
    @if(Route::has('publicView'))
        <x-ui.button :href="route('publicView', $store->slug)" variant="secondary" icon-left="arrow-right" target="_blank" rel="noopener">
            {{ ___('Public view') }}
        </x-ui.button>
    @endif
@endsection

@section('content')
@php
    $optionValue = function ($key, $default = '') use ($postOptions) {
        $value = old($key, data_get($postOptions, $key, $default));
        // Stored credentials are shown as a mask, never in the page; saving the
        // mask back unchanged keeps them. Text the owner just typed (old input
        // after a validation error) is shown as typed.
        if (\Juwri\Modules\Payments\Services\GatewaySecretVault::isSecret($key) && old($key) === null) {
            return \Juwri\Core\Support\SecretMask::mask(is_scalar($value) ? (string) $value : '');
        }

        return $value;
    };
    $selectedTemplate = $optionValue('store_template', data_get(collect($templates)->first(), 'folder'));
    $selectedLanguages = (array) old('menu_languages', data_get($postOptions, 'menu_languages', []));
    $activeLanguages = get_active_languages();
    // P6-289: pre-computed datalist of every IANA zone the owner can type. The
    // <datalist> powers browser autocomplete for the searchable text input
    // below; the controller saves the string verbatim and validates it with
    // timezone:all (StoreSettingsRules). The id is unique to this view so two
    // store-editor instances on the same page (admin fleet) cannot collide.
    $tzListId = 'store-tz-list-'.$store->id;
    $tzOptions = \DateTimeZone::listIdentifiers();
    $visibleOptions = [
        'store_template',
        'menu_layout',
        'public_theme_mode',
        'menu_font',
        // P6-289: store-level timezone option (see store_tz() fallback chain).
        'timezone',
        'currency_code',
        'currency_sign',
        'currency_pos',
        'default_language',
        'allow_call_waiter',
        'enable_reservations',
        'enable_waitlist',
        'waitlist_avg_turn',
        'order_lookup_by_number',
        'open_close_store',
        'prevent_order_on_close',
        'restaurant_on_table_order',
        'restaurant_takeaway_order',
        'restaurant_delivery_order',
        'restaurant_delivery_charge',
        'restaurant_free_delivery_over',
        'restaurant_free_delivery_covers_branch_fee',
        'restaurant_taxx_charge',
        'restaurant_tax_type',
        'restaurant_tax_label',
        'tax_engine',
        'tax_prices_include',
        'tax_delivery_taxable',
        'tax_tray_taxable',
        'tax_service_taxable',
        'tax_levies',
        'tax_rate_preset',
        'tax_zero_vatex',
        'tax_exempt_vatex',
        'allow_coupons',
        'allow_tips',
        'allow_dietary_filters',
        'customer_auth_mode',
        'google_review_url',
        'min_order_value',
        'allow_order_scheduling',
        'accept_voice_notes',
        'voice_note_transcription',
        'prep_eta_static',
        'store_submit_order_notification',
        'restaurant_online_payment',
        'auto_refunds_enabled',
        'auto_refunds_tap_moyasar',
    ];
    $allOptions = [
        'customer_auth_mode',
        'auto_refunds_enabled',
        'auto_refunds_tap_moyasar',
        'store_template',
        'menu_layout',
        'default_language',
        // P6-289: store-level timezone option (the "Store timezone" field in
        // Identity). Keeping it in $allOptions means a save from any other tab
        // preserves a stored value instead of blanking it — the input lives
        // on the General tab but the option is not tab-scoped at write time.
        'timezone',
        'currency_code',
        'currency_sign',
        'currency_pos',
        'allow_call_waiter',
        'enable_reservations',
        'enable_waitlist',
        'waitlist_avg_turn',
        'order_lookup_by_number',
        'allow_multi_branch_switch',
        'allow_slider_popup',
        'allow_auto_user_info',
        'allow_auto_popup',
        'open_close_store',
        'prevent_order_on_close',
        'show_lang_switch_on_bio_page',
        'show_background_img_on_bio_page',
        'show_logo_img_on_bio_page',
        'show_title_on_bio_page',
        'show_discription_on_bio_page',
        'show_timing_on_bio_page',
        'show_social_on_bio_page',
        'show_links_on_bio_page',
        'show_continue_btn_on_bio_page',
        'show_never_show_on_bio_page',
        'show_open_close_store',
        'show_open_bio_close_store',
        'restaurant_on_table_order',
        'restaurant_takeaway_order',
        'restaurant_delivery_order',
        'restaurant_delivery_charge',
        'restaurant_free_delivery_over',
        'restaurant_free_delivery_covers_branch_fee',
        'restaurant_taxx_charge',
        'restaurant_tax_type',
        'restaurant_tax_label',
        'tax_engine',
        'tax_prices_include',
        'tax_delivery_taxable',
        'tax_tray_taxable',
        'tax_service_taxable',
        'tax_levies',
        'tax_rate_preset',
        'tax_zero_vatex',
        'tax_exempt_vatex',
        'allow_coupons',
        'allow_tips',
        'allow_dietary_filters',
        'google_review_url',
        'public_theme_mode',
        'menu_font',
        'min_order_value',
        'allow_order_scheduling',
        'accept_voice_notes',
        'voice_note_transcription',
        'prep_eta_static',
        'store_submit_order_notification',
        'restaurant_online_payment',
        // PARITY-RUN T2: order board + daily tokens.
        'order_board_enabled',
        'order_board_ready_minutes',
        'order_board_chime',
        'order_board_type_takeaway',
        'order_board_type_on_table',
        'order_board_type_delivery',
        'order_board_type_room_service',
        'restaurant_paypal_install',
        'restaurant_paypal_title',
        'restaurant_paypal_sandbox_mode',
        'restaurant_paypal_api_client_id',
        'restaurant_paypal_api_secret',
        'restaurant_paypal_api_app_id',
        'restaurant_stripe_install',
        'restaurant_stripe_title',
        'restaurant_stripe_secret_key',
        'restaurant_stripe_publishable_key',
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
    ];
    // Per-restaurant payment gateways (data-driven UI in the Payments tab).
    // 't' field types: 'text' | 'sandbox' (Yes/No). Every gateway has install + title.
    $gateways = \Juwri\Core\Support\DeferredGateways::visible([
        'paypal'    => ['label' => 'PayPal',    'help' => 'https://developer.paypal.com/developer/applications/create', 'fields' => [['api_client_id','Client ID'],['api_secret','Client Secret'],['api_app_id','App ID'],['webhook_id','Webhook ID']], 'sandbox' => true],
        'stripe'    => ['label' => 'Stripe',    'help' => 'https://dashboard.stripe.com/apikeys', 'fields' => [['secret_key','Secret Key'],['publishable_key','Publishable Key'],['webhook_secret','Webhook Secret']], 'sandbox' => false],
        'razorpay'  => ['label' => 'Razorpay',  'help' => 'https://dashboard.razorpay.com/app/keys', 'fields' => [['api_key','API Key'],['secret_key','Secret Key'],['webhook_secret','Webhook Secret']], 'sandbox' => false],
        'mollie'    => ['label' => 'Mollie',    'help' => '', 'fields' => [['api_key','API Key']], 'sandbox' => false],
        'paytm'     => ['label' => 'Paytm',     'help' => '', 'fields' => [['merchant_key','Merchant Key'],['merchant_mid','Merchant MID'],['merchant_website','Merchant Website']], 'sandbox' => true],
        'paystack'  => ['label' => 'Paystack',  'help' => '', 'fields' => [['secret_key','Secret Key'],['public_key','Public Key']], 'sandbox' => false],
        'payumoney' => ['label' => 'PayUmoney', 'help' => '', 'fields' => [['merchant_pos_id','Merchant POS ID'],['signature_key','Signature Key'],['oauth_client_id','OAuth Client ID'],['oauth_client_secret','OAuth Client Secret']], 'sandbox' => true],
        'iyzico'    => ['label' => 'Iyzico',    'help' => '', 'fields' => [['api_key','API Key'],['secret_key','Secret Key']], 'sandbox' => true],
        'midtrans'  => ['label' => 'Midtrans',  'help' => '', 'fields' => [['client_key','Client Key'],['server_key','Server Key']], 'sandbox' => true],
        'paytabs'   => ['label' => 'PayTabs',   'help' => '', 'fields' => [['region','Region'],['profile_id','Profile ID'],['secret_key','Secret Key']], 'sandbox' => false],
        'telr'      => ['label' => 'Telr',      'help' => '', 'fields' => [['store_id','Store ID'],['authkey','Auth Key']], 'sandbox' => true],
        '2checkout' => ['label' => '2Checkout', 'help' => '', 'fields' => [['account_number','Account Number'],['public_key','Public Key'],['private_key','Private Key']], 'sandbox' => true],
        'ccavenue'  => ['label' => 'CCAvenue',  'help' => '', 'fields' => [['merchant_key','Merchant Key'],['access_code','Access Code'],['working_key','Working Key']], 'sandbox' => false],
        // #65: regional gateways
        'tap'         => ['label' => 'Tap (MENA)',       'help' => 'https://www.tap.company', 'fields' => [['secret_key','Secret Key (sk_...)'],['publishable_key','Publishable Key (pk_...)']], 'sandbox' => true],
        'flutterwave' => ['label' => 'Flutterwave (Africa)', 'help' => 'https://dashboard.flutterwave.com', 'fields' => [['secret_key','Secret Key (FLWSECK-...)'],['public_key','Public Key (FLWPUBK-...)']], 'sandbox' => true],
        'tdsp'        => ['label' => 'TDSP / T-Lync (Libya, LYD)', 'help' => 'https://merchant.pay.net.ly', 'fields' => [['store_id','Store ID'],['token','API Token']], 'sandbox' => true],
        'paylink'     => ['label' => 'Paylink / STC Pay (KSA)', 'help' => 'https://my.paylink.sa', 'fields' => [['api_id','API ID'],['secret_key','Secret Key']], 'sandbox' => true],
        'paymob'      => ['label' => 'Paymob (EG / KSA / UAE / Oman)', 'help' => 'https://paymob.com', 'fields' => [['region','Paymob region'],['secret_key','Secret Key'],['public_key','Public Key'],['integration_id','Integration ID'],['hmac_secret','HMAC Secret']], 'sandbox' => true],
        'geidea'      => ['label' => 'Geidea (KSA)', 'help' => 'https://merchant.geidea.net', 'fields' => [['merchant_public_key','Merchant Public Key'],['api_password','API Password']], 'sandbox' => true],
        'tamara'      => ['label' => 'Tamara (BNPL)', 'help' => 'https://partners.tamara.co', 'fields' => [['api_token','API Token'],['notification_token','Notification Token']], 'sandbox' => true],
        'tabby'       => ['label' => 'Tabby (BNPL)', 'help' => 'https://tabby.ai', 'fields' => [['secret_key','Secret Key (sk_...)'],['public_key','Public Key (pk_...)'],['merchant_code','Merchant Code']], 'sandbox' => true],
        'fawry'       => ['label' => 'Fawry (Egypt, EGP)', 'help' => 'https://developer.fawrystaging.com', 'fields' => [['merchant_code','Merchant Code'],['security_key','Security Key']], 'sandbox' => true],
        'fatora'      => ['label' => 'Fatora (Qatar)', 'help' => 'https://fatora.io', 'fields' => [['api_key','API Key']], 'sandbox' => true],
        'moyasar'     => ['label' => 'Moyasar (KSA)', 'help' => 'https://dashboard.moyasar.com', 'fields' => [['secret_key','Secret Key (sk_...)'],['publishable_key','Publishable Key (pk_...)'],['webhook_secret','Webhook Secret Token']], 'sandbox' => false],
        'noon'        => ['label' => 'Noon Payments (KSA)', 'help' => 'https://noonpayments.com', 'fields' => [['business_id','Business ID'],['application_id','Application ID'],['api_key','API Key']], 'sandbox' => true],
        // Syria gateways
        'wallet_transfer' => ['label' => 'Wallet Transfer (Syria)', 'help' => '', 'fields' => [['syriatel_number','Syriatel Cash number'],['mtn_number','MTN Cash number'],['shamcash_number','Sham Cash number'],['instructions','Payment instructions']], 'sandbox' => false],
        'ecash'           => ['label' => 'eCash (Syria)', 'help' => 'https://ecash-pay.com', 'fields' => [['merchant_id','Merchant ID'],['terminal_id','Terminal ID'],['api_key','API Key']], 'sandbox' => false],
        'qrpay'           => ['label' => 'QRPay (Syria)', 'help' => 'https://syrpayment.com/developer', 'fields' => [['client_id','Client ID'],['secret_id','Secret ID']], 'sandbox' => false],
        // P1-23 part 6: gift cards, top-ups and deposits paid into the store's own bank account (the owner confirms them)
        'wire_transfer'   => ['label' => 'Bank Transfer', 'help' => '', 'fields' => [['bank_info','Bank account details']], 'sandbox' => false],
    ]);
    $payTabsRegions = ['ARE' => 'United Arab Emirates', 'SAU' => 'Saudi Arabia', 'OMN' => 'Oman', 'JOR' => 'Jordan',
        'EGY' => 'Egypt', 'IRQ' => 'Iraq', 'PSE' => 'Palestine', 'GLOBAL' => 'Global'];
    // Collect every gateway option key so it is NOT double-rendered as a hidden input.
    $gatewayKeys = [];
    foreach ($gateways as $gk => $gconf) {
        $gatewayKeys[] = 'restaurant_'.$gk.'_install';
        $gatewayKeys[] = 'restaurant_'.$gk.'_title';
        if (!empty($gconf['sandbox'])) { $gatewayKeys[] = 'restaurant_'.$gk.'_sandbox_mode'; }
        foreach ($gconf['fields'] as $f) { $gatewayKeys[] = 'restaurant_'.$gk.'_'.$f[0]; }
    }
    // Bio-page visibility toggles (value 1/0) - surfaced in the Bio tab.
    $bioToggles = [
        'show_title_on_bio_page'        => ___('Show title'),
        'show_discription_on_bio_page'  => ___('Show description'),
        'show_logo_img_on_bio_page'     => ___('Show logo'),
        'show_background_img_on_bio_page'=> ___('Show background image'),
        'show_timing_on_bio_page'       => ___('Show opening hours'),
        'show_social_on_bio_page'       => ___('Show social icons'),
        'show_links_on_bio_page'        => ___('Show custom links'),
        'show_continue_btn_on_bio_page' => ___('Show continue button'),
        'show_never_show_on_bio_page'   => ___('Show "never show again"'),
        'show_lang_switch_on_bio_page'  => ___('Show language switcher'),
        'show_open_close_store'         => ___('Show open/close status'),
        'allow_slider_popup'            => ___('Allow slider popup'),
        'allow_auto_popup'              => ___('Auto-open popup'),
    ];
    /* PARITY-RUN T2: the Order board tab only exists for stores whose plan (and
       the platform kill-switch) allow the feature — the store settings page is
       owner-authenticated, so a plain storeAllows() check is the right gate.
       When it is off the keys stay in $hiddenOptions (below), so a save from a
       store that cannot see the tab preserves the values instead of blanking
       them — the update loop writes every key in the list unconditionally. */
    $boardFeature = \App\Services\FeatureAccess::storeAllows((int) $store->id, 'order_board');
    $boardOptionKeys = [
        'order_board_enabled',
        'order_board_ready_minutes',
        'order_board_chime',
        'order_board_type_takeaway',
        'order_board_type_on_table',
        'order_board_type_delivery',
        'order_board_type_room_service',
    ];
    $boardBranches = $boardFeature ? $store->branches : collect();
    $boardTypes = [
        'takeaway'     => ___('Takeaway'),
        'on_table'     => ___('On table'),
        'delivery'     => ___('Delivery'),
        'room_service' => ___('Room service'),
    ];
    $currencyKeys = ['enable_multi_currency', 'currency_rates_manual'];
    $surfacedKeys = array_merge(array_keys($bioToggles), $currencyKeys);
    $hiddenOptions = array_values(array_diff($allOptions, $visibleOptions, $gatewayKeys, $surfacedKeys));
    // The Board tab renders these itself when it is visible.
    if ($boardFeature) {
        $hiddenOptions = array_values(array_diff($hiddenOptions, $boardOptionKeys));
    }
    $fontOptions = [
        '' => ___('Default (system font)'),
        'Inter' => 'Inter',
        'Poppins' => 'Poppins',
        'Lato' => 'Lato',
        'Montserrat' => 'Montserrat',
        'Roboto' => 'Roboto',
        'Open+Sans' => 'Open Sans',
        'Raleway' => 'Raleway',
        'Nunito' => 'Nunito',
        'Source+Sans+3' => 'Source Sans 3',
        'Playfair+Display' => 'Playfair Display',
        'Merriweather' => 'Merriweather',
        'Dancing+Script' => 'Dancing Script',
        'Bebas+Neue' => 'Bebas Neue',
        'Sora' => 'Sora',
    ];
@endphp

{{-- Owner app S5: in the native app the Save button moves to the nav bar (bridge--form, rev/mobile-app/BRIDGE.md). --}}
<form
    class="restaurant-edit ui-scope ui-settings"
    data-controller="bridge--form"
    data-ajax
    data-ajax-success="{{ ___('Store updated') }}"
    method="post"
    action="{{ route('stores.update', $store->id) }}"
    enctype="multipart/form-data"
    x-data="linenRestaurantEdit({
        tab: 'general',
        tabs: @js(array_keys(array_filter([
            'general' => true, 'design' => true, 'ordering' => true,
            'board' => (bool) $boardFeature, 'payments' => true, 'marketing' => true,
            'domains' => true, 'languages' => true, 'pages' => true,
            // rev/multi-vertical convert+duplicate: hidden entirely for hotel
            // stores in v1 (no menu<->room bridge — see StoreVerticalConversionService).
            'vertical' => ($store->store_type ?? 'restaurant') !== 'hotel',
            'advanced' => true,
        ]))),
        logoFallback: @js(asset('storage/store/logo/'.$store->main_image)),
        coverFallback: @js(asset('storage/store/cover/'.$store->cover_image)),
    })"
>
    @csrf
    @method('put')

    @foreach($hiddenOptions as $option)
        <input type="hidden" name="{{ $option }}" value="{{ $optionValue($option) }}">
    @endforeach



    <section class="edit-hero">
        <div class="edit-hero__cover">
            <img :src="coverPreview" alt="">
        </div>
        <div class="edit-hero__body">
            <div class="edit-hero__logo">
                <img :src="logoPreview" alt="{{ $store->title }}">
            </div>
            <div>
                <p>{{ ___('Store profile') }}</p>
                <h2>{{ $store->title }}</h2>
                <span>{{ '/'.$store->slug }}</span>
            </div>
        </div>
    </section>

    {{-- Tab row on the owner UI kit: a sticky, sideways-scrolling segmented control. --}}
    <div class="ui-chips ui-settings__tabs" role="tablist" aria-label="{{ ___('Store settings tabs') }}">
        @foreach(array_filter([
            'general' => ___('General'),
            'design' => ___('Design'),
            'ordering' => ___('Ordering'),
            // PARITY-RUN T2: hidden entirely when the feature is off for this store.
            'board' => $boardFeature ? ___('Order board') : null,
            'payments' => ___('Payments'),
            'marketing' => ___('Marketing'),
            'domains' => ___('Domains'),
            'languages' => ___('Languages & currency'),
            'pages' => ___('Storefront pages'),
            // rev/multi-vertical convert+duplicate: hidden for hotel stores (v1) —
            // mirrors the `tabs` list above so a deep link (#vertical) can't open
            // a tab-pane this store isn't allowed to see.
            'vertical' => ($store->store_type ?? 'restaurant') !== 'hotel' ? ___('Store type') : null,
            'advanced' => ___('Advanced'),
        ]) as $tabKey => $tabLabel)
            <button type="button" class="ui-chip" role="tab" :aria-current="tab === @js($tabKey) ? 'true' : null" :aria-selected="tab === @js($tabKey) ? 'true' : 'false'" @click="tab = @js($tabKey)">
                {{ $tabLabel }}
            </button>
        @endforeach
    </div>

    <div class="ui-settings__panes">

    <section class="ui-card ui-settings__pane" x-show="tab === 'general'" x-transition.opacity>
        <div class="ui-card__body">
        <div>
            <p class="ui-muted ui-caps">{{ ___('General') }}</p>
            <h3 class="ui-section-title">{{ ___('Identity and contact') }}</h3>
        </div>

        <div class="ui-form-grid">
            <div class="ui-form-grid__full">
                <label class="ui-label" for="restaurant-color">{{ ___('Brand color') }}</label>
                <div class="ui-cluster">
                    <input id="restaurant-color" type="color" class="ui-input" name="color" value="{{ old('color', $store->color ?: ('#'.'10b981')) }}">
                    <span class="ui-text-2">{{ old('color', $store->color ?: ('#'.'10b981')) }}</span>
                </div>
            </div>

            <div class="ui-form-grid__full">
                <x-ui.field name="title" :label="___('Name')" :value="old('title', $store->title)" required />
            </div>
            <div class="ui-form-grid__full">
                <x-ui.field name="slug" id="store-slug" :label="___('Slug')" :value="old('slug', $store->slug)" :help="___('Use letters, numbers, and hyphens.')" />
            </div>
            <div class="ui-form-grid__full">
                <x-ui.field name="sub_title" :label="___('Subtitle')" :value="old('sub_title', $store->sub_title)" />
            </div>
            <div class="ui-form-grid__full">
                <x-ui.field name="timing" :label="___('Timing')" :value="old('timing', $store->timing)" />
            </div>
            <div class="ui-form-grid__full">
                <x-ui.field name="phone" :label="___('Phone')" :value="old('phone', $store->phone)" />
            </div>
            <div class="ui-form-grid__full">
                <x-ui.field name="address" :label="___('Address')" :value="old('address', $store->address)" required />
            </div>
            <div class="ui-form-grid__full">
                <x-ui.field name="description" type="textarea" :label="___('Description')" :value="old('description', $store->description)" required />
            </div>

            {{-- P6-289: store-level timezone override. A searchable text input
                 (browsers auto-suggest from the <datalist> below) — owners in
                 non-UTC countries set this once and reports, sold-out resets,
                 the dashboard "today" window and kitchen token resets all run
                 on the store's local day instead of UTC. Empty value clears
                 the option (the resolver then falls back to the primary
                 active branch's timezone, then the platform default). --}}
            <div class="ui-form-grid__full">
                <label class="ui-label" for="store-timezone">{{ ___('Store timezone') }}</label>
                <input
                    type="text"
                    class="ui-input"
                    id="store-timezone"
                    name="timezone"
                    list="{{ $tzListId }}"
                    autocomplete="off"
                    spellcheck="false"
                    dir="ltr"
                    value="{{ old('timezone', $optionValue('timezone')) }}"
                    placeholder="{{ ___('Leave empty to use the first active branch’s timezone') }}"
                    aria-describedby="store-timezone-help"
                >
                <datalist id="{{ $tzListId }}">
                    @foreach($tzOptions as $tzId)
                        <option value="{{ $tzId }}"></option>
                    @endforeach
                </datalist>
                <small class="ui-text-2" id="store-timezone-help">
                    {{ ___('Used by reports, the “today” window and daily resets. Pick an IANA zone (e.g. Asia/Riyadh); the server validates it. Empty = inherit from your first active branch.') }}
                </small>
            </div>

            {{-- DIRECTORY L1 T1-1: cuisine multi-select (max 3). Ship dark (D1):
                 hidden entirely while the directory module is off, so nothing new
                 appears in the dashboard before a superadmin flips the switch. --}}
            @if (app(\Juwri\Core\Contracts\Marketplace\Directory::class)->enabled() && isset($cuisines) && $cuisines->isNotEmpty())
                @php $selectedCuisines = old('cuisines', $postCuisineIds ?? []); @endphp
                <div class="ui-form-grid__full" x-data="{ picked: @js(array_map('intval', $selectedCuisines)) }">
                    <div class="ui-label ui-block">
                        {{ ___('Cuisines') }}
                        <small class="ui-text-2">({{ ___('up to 3') }})</small>
                    </div>
                    <div class="ui-cluster" role="group" aria-label="{{ ___('Cuisines') }}">
                        @foreach ($cuisines as $c)
                            <label class="ui-chip ui-settings__pick"
                                   :class="{ 'is-on': picked.includes({{ (int) $c->id }}) }">
                                <input type="checkbox" class="ui-hidden" name="cuisines[]" value="{{ $c->id }}"
                                       x-model.number="picked" :disabled="!picked.includes({{ (int) $c->id }}) && picked.length >= 3">
                                <span dir="auto">{{ $c->displayName() }}</span>
                            </label>
                        @endforeach
                    </div>
                    <small class="ui-text-2 ui-field__help" x-show="picked.length >= 3">{{ ___('Maximum of 3 reached.') }}</small>
                </div>
            @endif
        </div>

        {{-- Additional locations (branches) — relocated here from the retired Bio tab. --}}
        <div>
            <p class="ui-muted ui-caps">{{ ___('Branches') }}</p>
            <h3 class="ui-section-title">{{ ___('Additional locations') }}</h3>
        </div>
        <div class="ui-cluster ui-cluster--between">
            <p class="ui-text-2">{{ ___('Branches are now managed in their own area — set hours, per-branch menu & pricing, QR codes and reports.') }}</p>
            @if(Route::has('branches.index'))
                <x-ui.button :href="route('branches.index', ['store_id' => $store->id])" variant="secondary" icon-left="map-pin">
                    {{ ___('Manage branches') }}
                </x-ui.button>
            @endif
        </div>
        </div>
    </section>

    <section class="ui-card ui-settings__pane" x-show="tab === 'design'" x-cloak x-transition.opacity>
        <div class="ui-card__body">
        <div>
            <p class="ui-muted ui-caps">{{ ___('Design') }}</p>
            <h3 class="ui-section-title">{{ ___('Images and template') }}</h3>
        </div>

        <div class="ui-form-grid">
            <div class="ui-form-grid__full">
                <label class="ui-label ui-block" for="store-main-image">{{ ___('Logo') }}</label>
                <img :src="logoPreview" alt="" class="ui-thumb ui-block" style="max-height:120px">
                <input type="file" class="ui-input" id="store-main-image" name="main_image" accept="image/*" @change="previewFile($event, 'logoPreview')">
            </div>
            <div class="ui-form-grid__full">
                <label class="ui-label ui-block" for="store-cover-image">{{ ___('Cover image') }}</label>
                <img :src="coverPreview" alt="" class="ui-thumb ui-block" style="max-height:120px">
                <input type="file" class="ui-input" id="store-cover-image" name="cover_image" accept="image/*" @change="previewFile($event, 'coverPreview')">
            </div>
        </div>

        <div class="ui-form-grid">
            <div class="ui-form-grid__full">
                <label class="ui-label" for="menu_layout">{{ ___('Menu layout') }}</label>
                <select class="ui-input" id="menu_layout" name="menu_layout">
                    <option value="" @selected($optionValue('menu_layout') === '')>{{ ___('Default') }}</option>
                    <option value="grid" @selected($optionValue('menu_layout') === 'grid')>{{ ___('Grid') }}</option>
                    <option value="list" @selected($optionValue('menu_layout') === 'list')>{{ ___('List') }}</option>
                </select>
            </div>
            <div class="ui-form-grid__full">
                <label class="ui-label" for="public_theme_mode">{{ ___('Theme mode') }}</label>
                <select class="ui-input" id="public_theme_mode" name="public_theme_mode">
                    <option value="auto" @selected($optionValue('public_theme_mode', 'auto') === 'auto')>{{ ___('Auto') }}</option>
                    <option value="light" @selected($optionValue('public_theme_mode') === 'light')>{{ ___('Light') }}</option>
                    <option value="dark" @selected($optionValue('public_theme_mode') === 'dark')>{{ ___('Dark') }}</option>
                </select>
            </div>
            <div class="ui-form-grid__full">
                <label class="ui-label" for="menu_font">{{ ___('Menu Font') }}</label>
                <select class="ui-input" id="menu_font" name="menu_font">
                    @foreach($fontOptions as $value => $label)
                        <option value="{{ $value }}" @selected($optionValue('menu_font') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="ui-form-grid__full">
                <label class="ui-label" for="menu_font_file">{{ ___('Upload custom font') }} <small class="ui-text-2">(woff2, {{ ___('max 2 MB') }})</small></label>
                <input class="ui-input" id="menu_font_file" type="file" name="menu_font_file" accept=".woff2">
                @php $currentFont = $optionValue('menu_font_file'); @endphp
                @if(!empty($currentFont))
                    <small class="ui-field__help" x-data="{ removed: false }" :class="removed && 'ui-strike'">
                        {{ ___('Current') }}: {{ $currentFont }}
                        <a href="#" @click.prevent="removed = true; $refs.removeFont.value='1'" class="ui-text-danger">{{ ___('Remove') }}</a>
                        <input type="hidden" name="remove_menu_font_file" x-ref="removeFont" value="0">
                    </small>
                @else
                    <input type="hidden" name="remove_menu_font_file" value="0">
                @endif
            </div>
        </div>

        {{-- Ownership cleanup (phase 2): section order & visibility moved to the
             theme customizer, which is the canonical source (themes overlay
             theme_section_visible() over the legacy menu_sections row, so the
             customizer control always wins). The stored menu_sections value is
             untouched — the update endpoint only writes it when
             menu_sections_json is posted, and it no longer is. --}}
        <div>
            <p class="ui-muted ui-caps">{{ ___('Layout') }}</p>
            <h3 class="ui-section-title">{{ ___('Public menu section order') }}</h3>
        </div>
        <div class="ui-cluster ui-cluster--between ui-bordered ui-bg-inset">
            <span class="ui-text-2">
                {{ ___('Reorder, show, and hide your public menu sections in the theme customizer — with a live preview.') }}
            </span>
            <x-ui.button :href="route('theme.customizer.page').'?store='.$store->id.'#sections'" variant="secondary" size="sm" icon-left="palette">
                {{ ___('Edit sections') }}
            </x-ui.button>
        </div>

        {{-- Phase 3 (Shopify model): the template picker lives in the theme
             customizer's Theme library panel; this tab keeps a launcher card.
             store_template stays REQUIRED on update, so the current value
             rides along as a hidden field. --}}
        @php
            $currentTemplate = collect($templates)->firstWhere('folder', $selectedTemplate)
                ?? ['folder' => $selectedTemplate, 'name' => $selectedTemplate, 'description' => '', 'image' => null];
            $currentPresetThumbs = collect(\Juwri\Core\Support\Views\StoreViewHelpers::presetThumbUrls(
                collect(glob(public_path('assets/store_templates/'.$selectedTemplate.'/presets/*.png')) ?: [])
                    ->take(3)
                    ->all(),
                $selectedTemplate
            ));
        @endphp
        <input type="hidden" name="store_template" value="{{ $selectedTemplate }}">

        <div>
            <p class="ui-muted ui-caps">{{ ___('Template') }}</p>
            <h3 class="ui-section-title">{{ ___('Store template') }}</h3>
        </div>
        <div class="tpl-launcher">
            @if(!empty($currentTemplate['image']))
                <img class="tpl-launcher__thumb" src="{{ $currentTemplate['image'] }}" alt="" loading="lazy" data-ui-do="invisible-on-error">
            @endif
            <div class="tpl-launcher__body">
                <strong class="ui-block">{{ $currentTemplate['name'] }}</strong>
                <small class="ui-text-2 ui-block">{{ ___($currentTemplate['description'] ?? 'A premium QR menu template.') }}</small>
                @if($currentPresetThumbs->isNotEmpty())
                    <span class="tpl-launcher__presets" aria-hidden="true">
                        @foreach($currentPresetThumbs as $thumb)
                            <img src="{{ $thumb }}" alt="" loading="lazy">
                        @endforeach
                    </span>
                @endif
                <div class="ui-cluster">
                    <x-ui.button :href="route('theme.customizer.page').'?store='.$store->id.'#library'" variant="secondary" size="sm" icon-left="layout-grid">
                        {{ ___('Change template') }}
                    </x-ui.button>
                    <x-ui.button :href="route('theme.customizer.page').'?store='.$store->id" size="sm" icon-left="palette">
                        {{ ___('Customize') }}
                    </x-ui.button>
                </div>
            </div>
        </div>
        </div>
    </section>

    <section class="ui-card ui-settings__pane" x-show="tab === 'ordering'" x-cloak x-transition.opacity>
        <div class="ui-card__body">
        <div>
            <p class="ui-muted ui-caps">{{ ___('Ordering') }}</p>
            <h3 class="ui-section-title">{{ ___('Checkout and service options') }}</h3>
        </div>

        {{-- P4: grouped with progressive disclosure. Child fields (tip presets,
             waitlist turnaround, scheduling, gift cards, reservation deposits,
             voice transcription) render only when their parent feature is on. State
             seeds from the saved value and updates on @change; the parent selects
             keep their server-rendered @selected and are NEVER x-model-bound — an
             x-model on a "1"/"0" select is what caused the currency flag bug
             (FLAG-3). Hidden children stay in the DOM (x-show = display:none), so
             their stored values still submit and are never dropped on save. --}}
        <div x-data="{
            tips: @js((string) $optionValue('allow_tips', '0') === '1'),
            waitlist: @js((string) $optionValue('enable_waitlist', '0') === '1'),
            scheduling: @js((string) $optionValue('allow_order_scheduling', '0') === '1'),
            giftcards: @js((string) $optionValue('gift_cards_enabled', '0') === '1'),
            deposits: @js((string) $optionValue('reservation_deposit_enabled', '0') === '1'),
            voice: @js((string) $optionValue('accept_voice_notes', '0') === '1'),
        }">

        {{-- Group: Service & availability --}}
        <p class="ui-muted ui-caps">{{ ___('Service & availability') }}</p>
        <x-plan-gate-notice feature="delivery" :owner="$store->user" />
        <div class="ui-form-grid">
            @foreach([
                'open_close_store' => ___('Store open'),
                'prevent_order_on_close' => ___('Prevent orders when closed'),
                'restaurant_on_table_order' => ___('Table ordering'),
                'restaurant_takeaway_order' => ___('Takeaway ordering'),
                'restaurant_delivery_order' => ___('Delivery ordering'),
                'curbside_enabled' => ___('Curbside pickup'),
                'allow_call_waiter' => ___('Call waiter'),
                'store_submit_order_notification' => ___('Send order notifications'),
                'restaurant_online_payment' => ___('Online payment'),
            ] as $name => $label)
                {{-- P4-01: a store with no stored open switch IS open (store_open_now); defaulting the select to "No" made any save of this form close the store --}}
                @php $sel = (string) $optionValue($name, $name === 'open_close_store' ? '1' : '0'); @endphp
                <div class="ui-form-grid__full">
                    <label class="ui-label" for="{{ $name }}">{{ $label }}</label>
                    <select class="ui-input" id="{{ $name }}" name="{{ $name }}">
                        <option value="1" @selected($sel === '1')>{{ ___('Yes') }}</option>
                        <option value="0" @selected($sel !== '1')>{{ ___('No') }}</option>
                    </select>
                </div>
            @endforeach
        </div>

        {{-- Group: Checkout --}}
        <p class="ui-muted ui-caps">{{ ___('Checkout') }}</p>
        <x-plan-gate-notice feature="coupons" :owner="$store->user" />
        <div class="ui-form-grid">
            @foreach([
                'allow_coupons' => ___('Coupons'),
                'allow_dietary_filters' => ___('Dietary filters'),
                'agent_wallet_spend' => ___('Let AI agents spend store credit'),
            ] as $name => $label)
                @php $sel = (string) $optionValue($name, '0'); @endphp
                <div class="ui-form-grid__full">
                    <label class="ui-label" for="{{ $name }}">{{ $label }}</label>
                    <select class="ui-input" id="{{ $name }}" name="{{ $name }}">
                        <option value="1" @selected($sel === '1')>{{ ___('Yes') }}</option>
                        <option value="0" @selected($sel !== '1')>{{ ___('No') }}</option>
                    </select>
                </div>
            @endforeach

            {{-- Owner switch for the shared "Rate this dish" / Reviews widget.
                 DEFAULT ON (default '1'), so existing stores are unaffected. --}}
            <div class="ui-form-grid__full">
                <label class="ui-label" for="item_reviews_enabled">{{ ___('Show item reviews') }}</label>
                <select class="ui-input" id="item_reviews_enabled" name="item_reviews_enabled">
                    <option value="1" @selected((string) $optionValue('item_reviews_enabled', '1') === '1')>{{ ___('Yes') }}</option>
                    <option value="0" @selected((string) $optionValue('item_reviews_enabled', '1') === '0')>{{ ___('No') }}</option>
                </select>
            </div>

            {{-- P3-03: the store level of the offline order queue (default ON; platform and reseller can switch it off for everyone). --}}
            <div class="ui-form-grid__full">
                <label class="ui-label" for="offline_queue_enabled">{{ ___('Offline order queue') }}</label>
                <select class="ui-input" id="offline_queue_enabled" name="offline_queue_enabled">
                    <option value="1" @selected((string) $optionValue('offline_queue_enabled', '1') !== '0')>{{ ___('Yes') }}</option>
                    <option value="0" @selected((string) $optionValue('offline_queue_enabled', '1') === '0')>{{ ___('No') }}</option>
                </select>
                <small class="ui-text-2">{{ ___('A diner at a table, or a pickup customer who pays on collection, who loses the signal keeps their order on the phone; it is sent when they are back online, after they confirm any change in price. Delivery and online-paid orders never wait. Switching it off removes waiting orders with a notice.') }}</small>
            </div>
            <div class="ui-form-grid__full">
                <label class="ui-label" for="offline_queue_expiry_min">{{ ___('Queued order lifetime (minutes)') }}</label>
                <input type="number" min="5" max="240" class="ui-input" id="offline_queue_expiry_min" name="offline_queue_expiry_min"
                       value="{{ $optionValue('offline_queue_expiry_min', '30') }}">
                <small class="ui-text-2">{{ ___('A waiting order that is older than this when the phone reconnects is not sent; the customer is told and can start again.') }}</small>
            </div>

            {{-- Tips + presets (presets reveal only when Tips is on). --}}
            <div class="ui-form-grid__full">
                <label class="ui-label" for="allow_tips">{{ ___('Tips') }}</label>
                @php $selTips = (string) $optionValue('allow_tips', '0'); @endphp
                <select class="ui-input" id="allow_tips" name="allow_tips" @change="tips = ($event.target.value === '1')">
                    <option value="1" @selected($selTips === '1')>{{ ___('Yes') }}</option>
                    <option value="0" @selected($selTips !== '1')>{{ ___('No') }}</option>
                </select>
            </div>
            <div class="ui-form-grid__full" x-show="tips" x-cloak>
                <label class="ui-label" for="tip_presets">{{ ___('Tip presets (%)') }}</label>
                <input type="text" class="ui-input" id="tip_presets" name="tip_presets"
                       value="{{ $optionValue('tip_presets', '10,15,20') }}" placeholder="10,15,20">
                <small class="ui-text-2">{{ ___('Comma-separated percentages shown at checkout when Tips is enabled.') }}</small>
            </div>

            <div class="ui-form-grid__full">
                <x-ui.field name="min_order_value" :label="___('Minimum order value')" :value="$optionValue('min_order_value', 0)" type="number" />
            </div>
            <div class="ui-form-grid__full">
                <x-ui.field name="restaurant_delivery_charge" :label="___('Delivery charge')" :value="$optionValue('restaurant_delivery_charge', 0)" type="number" />
            </div>
            <div class="ui-form-grid__full">
                <x-ui.field name="restaurant_free_delivery_over" :label="___('Free delivery over')" :value="$optionValue('restaurant_free_delivery_over', '')" type="number" step="0.01" min="0" :help="___('Orders whose items reach this amount pay no delivery charge. Leave empty for none.')" />
            </div>
            {{-- P5-34: does "free delivery over X" also waive a branch's own delivery fee? (default ON) --}}
            <div class="ui-form-grid__full">
                <label class="ui-label" for="restaurant_free_delivery_covers_branch_fee">{{ ___('Free delivery over this amount also covers branch delivery fees') }}</label>
                <select class="ui-input" id="restaurant_free_delivery_covers_branch_fee" name="restaurant_free_delivery_covers_branch_fee">
                    <option value="1" @selected(! in_array($optionValue('restaurant_free_delivery_covers_branch_fee', '1'), [false, 0, '0'], true))>{{ ___('Yes') }}</option>
                    <option value="0" @selected(in_array($optionValue('restaurant_free_delivery_covers_branch_fee', '1'), [false, 0, '0'], true))>{{ ___('No') }}</option>
                </select>
                <small class="ui-text-2">{{ ___('When off, a branch with its own delivery fee always charges that fee.') }}</small>
            </div>

            {{-- ZERO-TOUCH Track C: static prep-time estimate fallback (minutes, "25-35"). --}}
            <div class="ui-form-grid__full">
                <label class="ui-label" for="prep_eta_static">{{ ___('Prep-time estimate fallback (minutes)') }}</label>
                <input type="text" class="ui-input" id="prep_eta_static" name="prep_eta_static" inputmode="numeric"
                       pattern="\d{1,3}-\d{1,3}" placeholder="25-35"
                       value="{{ $optionValue('prep_eta_static', '') }}">
                <small class="ui-text-2">{{ ___('Shown to customers while there is not enough order history for an automatic estimate. Format: 25-35. Leave empty to disable.') }}</small>
            </div>

            {{-- Order-number lookup (default OFF — order numbers are sequential, so
                 this weakens the identifier and lets orders be probed by number). --}}
            <div class="ui-form-grid__full">
                <label class="ui-label" for="order_lookup_by_number">{{ ___('Allow finding orders by order number') }}</label>
                <select class="ui-input" id="order_lookup_by_number" name="order_lookup_by_number">
                    <option value="1" @selected((string) $optionValue('order_lookup_by_number', '0') === '1')>{{ ___('Yes') }}</option>
                    <option value="0" @selected((string) $optionValue('order_lookup_by_number', '0') !== '1')>{{ ___('No') }}</option>
                </select>
                <small class="ui-text-2">{{ ___('Let guests look up an order by its number, in addition to the checkout phone/email. Order numbers are sequential, so leave this off unless you need it.') }}</small>
            </div>

            <div class="ui-form-grid__full">
                <label class="ui-label" for="customer_auth_mode">{{ ___('Customer login') }}</label>
                <select class="ui-input" id="customer_auth_mode" name="customer_auth_mode">
                    <option value="" @selected($optionValue('customer_auth_mode') === '')>{{ ___('Default') }}</option>
                    <option value="optional" @selected($optionValue('customer_auth_mode') === 'optional')>{{ ___('Optional') }}</option>
                    <option value="required" @selected($optionValue('customer_auth_mode') === 'required')>{{ ___('Required') }}</option>
                    <option value="demo" @selected($optionValue('customer_auth_mode') === 'demo')>{{ ___('Demo (presentational — no real accounts)') }}</option>
                </select>
            </div>
            <div class="ui-form-grid__full">
                <label class="ui-label" for="refund_request_window_days">{{ ___('Refund request window (days)') }}</label>
                <input type="number" class="ui-input" id="refund_request_window_days" name="refund_request_window_days" min="0" max="365"
                       value="{{ $optionValue('refund_request_window_days', 14) }}">
                <div class="ui-field__help">{{ ___('How long after ordering a customer may request a refund to store credit. 0 = no limit.') }}</div>
            </div>
            {{-- P3-08 (D7, N5): goods returns window, returns refunds (off: exchange or store credit only), back-in-stock messages. --}}
            @if(! $store->isFoodVertical())
                <div class="ui-form-grid__full" data-retail-settings>
                    <label class="ui-label" for="returns_window_days">{{ ___('Returns window (days)') }}</label>
                    <input type="number" class="ui-input" id="returns_window_days" name="returns_window_days" min="0" max="365"
                           value="{{ $optionValue('returns_window_days', 0) }}">
                    <div class="ui-field__help">{{ ___('How long after delivery or collection a customer may start a return. 0 = returns are not offered.') }}</div>
                </div>
                <div class="ui-form-grid__full">
                    <label class="ui-label" for="refunds_returns">{{ ___('Refund returns') }}</label>
                    <select class="ui-input" id="refunds_returns" name="refunds_returns">
                        <option value="0" @selected($optionValue('refunds_returns') != 1)>{{ ___('Off: exchange or store credit only') }}</option>
                        <option value="1" @selected($optionValue('refunds_returns') == 1)>{{ ___('On: customers may also ask for a refund') }}</option>
                    </select>
                </div>
                <div class="ui-form-grid__full">
                    <label class="ui-label" for="back_in_stock_alerts">{{ ___('Back-in-stock messages') }}</label>
                    <select class="ui-input" id="back_in_stock_alerts" name="back_in_stock_alerts">
                        <option value="1" @selected($optionValue('back_in_stock_alerts', 1) == 1)>{{ ___('On') }}</option>
                        <option value="0" @selected($optionValue('back_in_stock_alerts', 1) != 1)>{{ ___('Off') }}</option>
                    </select>
                    <div class="ui-field__help">{{ ___('Customers who agreed to receive messages are told once when a sold-out item is back.') }}</div>
                </div>
            @else
                <input type="hidden" name="returns_window_days" value="{{ $optionValue('returns_window_days', 0) }}">
                <input type="hidden" name="refunds_returns" value="{{ $optionValue('refunds_returns', 0) }}">
                <input type="hidden" name="back_in_stock_alerts" value="{{ $optionValue('back_in_stock_alerts', 1) }}">
            @endif
        </div>

        {{-- Group: Reservations & waitlist. #80 — every post_template gates its
             booking sheet on these keys, and the public book/join endpoints refuse
             writes unless the matching key is on. --}}
        <p class="ui-muted ui-caps">{{ ___('Reservations & waitlist') }}</p>
        <div class="ui-form-grid">
            <div class="ui-form-grid__full">
                <label class="ui-label" for="enable_reservations">{{ ___('Accept table reservations') }}</label>
                <select class="ui-input" id="enable_reservations" name="enable_reservations">
                    <option value="1" @selected((string) $optionValue('enable_reservations', '0') === '1')>{{ ___('Yes') }}</option>
                    <option value="0" @selected((string) $optionValue('enable_reservations', '0') !== '1')>{{ ___('No') }}</option>
                </select>
                <small class="ui-text-2">{{ ___('Show a booking form on your menu. Requests appear under Reservations.') }}</small>
            </div>

            <div class="ui-form-grid__full">
                <label class="ui-label" for="enable_waitlist">{{ ___('Accept waitlist join-ins') }}</label>
                @php $selWl = (string) $optionValue('enable_waitlist', '0'); @endphp
                <select class="ui-input" id="enable_waitlist" name="enable_waitlist" @change="waitlist = ($event.target.value === '1')">
                    <option value="1" @selected($selWl === '1')>{{ ___('Yes') }}</option>
                    <option value="0" @selected($selWl !== '1')>{{ ___('No') }}</option>
                </select>
                <small class="ui-text-2">{{ ___('Let diners add themselves to the queue from your menu. Entries appear under Waitlist.') }}</small>
            </div>
            <div class="ui-form-grid__full" x-show="waitlist" x-cloak>
                <label class="ui-label" for="waitlist_avg_turn">{{ ___('Average table turnaround (minutes)') }}</label>
                <input type="number" min="1" max="240" class="ui-input" id="waitlist_avg_turn" name="waitlist_avg_turn"
                       value="{{ $optionValue('waitlist_avg_turn', '20') }}">
                <small class="ui-text-2">{{ ___('Used to estimate the wait time shown to diners who join the queue.') }}</small>
            </div>

            {{-- T5 reservation deposits — child fields reveal only when deposits are on. --}}
            <div class="ui-form-grid__full">
                <label class="ui-label" for="reservation_deposit_enabled">{{ ___('Reservation deposits') }}</label>
                @php $selDep = (string) $optionValue('reservation_deposit_enabled', '0'); @endphp
                <select class="ui-input" id="reservation_deposit_enabled" name="reservation_deposit_enabled" @change="deposits = ($event.target.value === '1')">
                    <option value="1" @selected($selDep === '1')>{{ ___('Yes') }}</option>
                    <option value="0" @selected($selDep !== '1')>{{ ___('No') }}</option>
                </select>
            </div>
            <div class="ui-form-grid__full" x-show="deposits" x-cloak>
                <x-ui.field name="reservation_deposit_amount" :label="___('Deposit amount')" :value="$optionValue('reservation_deposit_amount', 0)" type="number" />
            </div>
            <div class="ui-form-grid__full" x-show="deposits" x-cloak>
                <label class="ui-label" for="reservation_deposit_per">{{ ___('Deposit charged per') }}</label>
                <select class="ui-input" id="reservation_deposit_per" name="reservation_deposit_per">
                    <option value="reservation" @selected($optionValue('reservation_deposit_per', 'reservation') === 'reservation')>{{ ___('Reservation') }}</option>
                    <option value="guest" @selected($optionValue('reservation_deposit_per') === 'guest')>{{ ___('Guest') }}</option>
                </select>
            </div>
            <div class="ui-form-grid__full" x-show="deposits" x-cloak>
                <x-ui.field name="reservation_deposit_min_party" :label="___('Deposit required for party of')" :value="$optionValue('reservation_deposit_min_party', 1)" type="number" />
            </div>
            <div class="ui-form-grid__full" x-show="deposits" x-cloak>
                <label class="ui-label" for="reservation_deposit_refund">{{ ___('Refund policy on cancel') }}</label>
                <select class="ui-input" id="reservation_deposit_refund" name="reservation_deposit_refund">
                    <option value="full" @selected($optionValue('reservation_deposit_refund', 'full') === 'full')>{{ ___('Full refund') }}</option>
                    <option value="partial" @selected($optionValue('reservation_deposit_refund') === 'partial')>{{ ___('Partial refund') }}</option>
                    <option value="none" @selected($optionValue('reservation_deposit_refund') === 'none')>{{ ___('No refund') }}</option>
                </select>
            </div>
            <div class="ui-form-grid__full" x-show="deposits" x-cloak>
                <x-ui.field name="reservation_deposit_refund_pct" :label="___('Partial refund %')" :value="$optionValue('reservation_deposit_refund_pct', 50)" type="number" />
            </div>
        </div>

        {{-- Group: Scheduling — T1 scheduled orders. Granular fields reveal only
             when Order scheduling is on. --}}
        <p class="ui-muted ui-caps">{{ ___('Order scheduling') }}</p>
        <div class="ui-form-grid">
            <div class="ui-form-grid__full">
                <label class="ui-label" for="allow_order_scheduling">{{ ___('Order scheduling') }}</label>
                @php $selSch = (string) $optionValue('allow_order_scheduling', '0'); @endphp
                <select class="ui-input" id="allow_order_scheduling" name="allow_order_scheduling" @change="scheduling = ($event.target.value === '1')">
                    <option value="1" @selected($selSch === '1')>{{ ___('Yes') }}</option>
                    <option value="0" @selected($selSch !== '1')>{{ ___('No') }}</option>
                </select>
                <small class="ui-text-2">{{ ___('Let customers pick a future time for their order at checkout.') }}</small>
            </div>
            <div class="ui-form-grid__full" x-show="scheduling" x-cloak>
                <x-ui.field name="scheduling_lead_minutes" :label="___('Scheduling: min lead time (minutes)')" :value="$optionValue('scheduling_lead_minutes', 30)" type="number" />
            </div>
            <div class="ui-form-grid__full" x-show="scheduling" x-cloak>
                <x-ui.field name="scheduling_slot_interval" :label="___('Scheduling: slot interval (minutes)')" :value="$optionValue('scheduling_slot_interval', 15)" type="number" />
            </div>
            <div class="ui-form-grid__full" x-show="scheduling" x-cloak>
                <x-ui.field name="scheduling_max_days" :label="___('Scheduling: days ahead')" :value="$optionValue('scheduling_max_days', 7)" type="number" />
            </div>
            <div class="ui-form-grid__full" x-show="scheduling" x-cloak>
                <x-ui.field name="scheduling_prep_minutes" :label="___('Scheduling: kitchen prep (minutes)')" :value="$optionValue('scheduling_prep_minutes', 20)" type="number" />
            </div>
            <div class="ui-form-grid__full" x-show="scheduling" x-cloak>
                <x-ui.field name="scheduling_slot_capacity" :label="___('Scheduling: max orders per slot (0 = unlimited)')" :value="$optionValue('scheduling_slot_capacity', 0)" type="number" />
            </div>
            @if(app(\App\Services\Verticals\VerticalRegistry::class)->has($store->store_type ?? null, 'service'))
            <div class="ui-form-grid__full" x-show="scheduling" x-cloak>
                <x-ui.field name="laundry_turnaround_hours" :label="___('Laundry: least hours between pickup and delivery back')" :value="$optionValue('laundry_turnaround_hours', 24)" type="number" />
            </div>
            @endif
        </div>

        {{-- Group: Gift cards — T2. Amounts/limits reveal only when Gift cards are on. --}}
        <p class="ui-muted ui-caps">{{ ___('Gift cards') }}</p>
        <div class="ui-form-grid">
            <div class="ui-form-grid__full">
                <label class="ui-label" for="gift_cards_enabled">{{ ___('Gift cards') }}</label>
                @php $selGc = (string) $optionValue('gift_cards_enabled', '0'); @endphp
                <select class="ui-input" id="gift_cards_enabled" name="gift_cards_enabled" @change="giftcards = ($event.target.value === '1')">
                    <option value="1" @selected($selGc === '1')>{{ ___('Yes') }}</option>
                    <option value="0" @selected($selGc !== '1')>{{ ___('No') }}</option>
                </select>
            </div>
            <div class="ui-form-grid__full" x-show="giftcards" x-cloak>
                <x-ui.field name="gift_card_presets" :label="___('Gift card amounts (comma-separated)')" :value="$optionValue('gift_card_presets', '25,50,100')" />
            </div>
            <div class="ui-form-grid__full" x-show="giftcards" x-cloak>
                <x-ui.field name="gift_card_min" :label="___('Gift card min amount')" :value="$optionValue('gift_card_min', 5)" type="number" />
            </div>
            <div class="ui-form-grid__full" x-show="giftcards" x-cloak>
                <x-ui.field name="gift_card_max" :label="___('Gift card max amount')" :value="$optionValue('gift_card_max', 500)" type="number" />
            </div>
            <div class="ui-form-grid__full" x-show="giftcards" x-cloak>
                <x-ui.field name="gift_card_expiry_months" :label="___('Gift card expiry (months, 0 = never)')" :value="$optionValue('gift_card_expiry_months', 0)" type="number" />
            </div>
        </div>

        {{-- Group: Voice notes — FEATURES-5 Track C. Transcription reveals only when
             voice notes are accepted. --}}
        <p class="ui-muted ui-caps">{{ ___('Voice notes') }}</p>
        <div class="ui-form-grid">
            <div class="ui-form-grid__full">
                <label class="ui-label" for="accept_voice_notes">{{ ___('Accept voice notes on orders') }}</label>
                @php $selVoice = (string) $optionValue('accept_voice_notes', '0'); @endphp
                <select class="ui-input" id="accept_voice_notes" name="accept_voice_notes" @change="voice = ($event.target.value === '1')">
                    <option value="1" @selected($selVoice === '1')>{{ ___('Yes') }}</option>
                    <option value="0" @selected($selVoice !== '1')>{{ ___('No') }}</option>
                </select>
                <small class="ui-text-2">{{ ___('Let customers record a short voice message at checkout for your staff to hear.') }}</small>
            </div>
            <div class="ui-form-grid__full" x-show="voice" x-cloak>
                {{-- ZERO-TOUCH Track B: auto-transcribe (default ON when a voice AI provider is configured). --}}
                <label class="ui-label" for="voice_note_transcription">{{ ___('Transcribe voice notes automatically') }}</label>
                <select class="ui-input" id="voice_note_transcription" name="voice_note_transcription">
                    <option value="1" @selected((string) $optionValue('voice_note_transcription', '1') !== '0')>{{ ___('Yes') }}</option>
                    <option value="0" @selected((string) $optionValue('voice_note_transcription', '1') === '0')>{{ ___('No') }}</option>
                </select>
                <small class="ui-text-2">{{ ___('Show the voice note as text for your staff. Needs a voice-capable AI provider (e.g. Groq or OpenAI).') }}</small>
            </div>
        </div>

        {{-- Group: Tax & compliance --}}
        <p class="ui-muted ui-caps">{{ ___('Tax & compliance') }}</p>
        <div class="ui-form-grid">
            <div class="ui-form-grid__full">
                <x-ui.field name="restaurant_taxx_charge" :label="___('Tax rate')" :value="$optionValue('restaurant_taxx_charge', 0)" type="number" />
            </div>
            <div class="ui-form-grid__full">
                <label class="ui-label" for="restaurant_tax_type">{{ ___('Tax type') }}</label>
                <select class="ui-input" id="restaurant_tax_type" name="restaurant_tax_type">
                    <option value="percentage" @selected($optionValue('restaurant_tax_type', 'percentage') === 'percentage')>{{ ___('Percentage') }}</option>
                    <option value="fixed" @selected($optionValue('restaurant_tax_type') === 'fixed')>{{ ___('Fixed') }}</option>
                </select>
            </div>
            <div class="ui-form-grid__full">
                <x-ui.field name="restaurant_tax_label" :label="___('Tax label')" :value="$optionValue('restaurant_tax_label', 'Tax')" />
            </div>
            {{-- P6-99b: the VAT engine (TAX-ENGINE.md) — opt-in, so no store's prices change silently. --}}
            {{-- P6-186: in KSA the engine is always on by default; the owner can switch off (whole-order VAT) unless the platform locked it on. --}}
            @php
                $vatCountry = (string) $optionValue('fiscal_country', '');
                $vatOn = (string) $optionValue('tax_engine', '') === 'vat';
                $ksOpts = store_options($store->id);
                $isKsa = \Juwri\Modules\Ordering\Services\Tax\TaxEngine::isKsa($ksOpts);
                $ksaLocked = \Juwri\Modules\Ordering\Services\Tax\TaxEngine::ownerToggleLocked($ksOpts);
                $ksaStoreMode = \Juwri\Modules\Ordering\Services\Tax\TaxEngine::storeMode($ksOpts);
                $ksaVatOn = $ksaStoreMode === 'line'; // J44a: forced (default) shows ON, locked
            @endphp
            @if (! $isKsa)
                @if (! $vatOn && \Juwri\Modules\Ordering\Services\Tax\TaxCountryRules::mandatesFeeVat($vatCountry) && (float) $optionValue('restaurant_taxx_charge', 0) > 0)
                    <div class="ui-form-grid__full">
                        <div class="ui-banner" role="alert">
                            <span class="ui-banner__icon"><x-ui.icon name="alert" size="18" /></span>
                            <div class="ui-banner__body">
                                <strong>{{ ___('Turn on VAT per line') }}</strong> —
                                {{ ___('In your country VAT is also due on delivery and service fees, and a coupon lowers the VAT. Your store still adds tax to items only. Turn on VAT per line below so your orders, tax invoices and accounts show the same VAT.') }}
                            </div>
                        </div>
                    </div>
                @endif
                <div class="ui-form-grid__full">
                    <label class="ui-label" for="tax_engine">{{ ___('VAT per line') }}</label>
                    <select class="ui-input" id="tax_engine" name="tax_engine">
                        <option value="" @selected(! $vatOn)>{{ ___('Off (tax on items only)') }}</option>
                        <option value="vat" @selected($vatOn)>{{ ___('On (per-item rates, VAT on fees, discounts lower the VAT)') }}</option>
                    </select>
                </div>
            @else
                <div class="ui-form-grid__full">
                    <label class="ui-label" for="tax-engine-ksa">{{ ___('VAT per line') }}</label>
                    <select class="ui-input" name="tax_engine" id="tax-engine-ksa" @disabled($ksaLocked)>
                        <option value="vat" @selected($ksaVatOn)>{{ ___('On (per-item rates, VAT on fees, discounts lower the VAT)') }}</option>
                        <option value="order" @selected(! $ksaVatOn)>{{ ___('Off (whole-order VAT)') }}</option>
                    </select>
                    @if ($ksaLocked)
                        <small class="ui-text-2">{{ ___('Set by the platform for ZATCA compliance') }}</small>
                    @else
                        <small class="ui-text-2">{{ ___('Applies to new orders only. Orders and invoices already issued keep their VAT.') }}</small>
                    @endif
                </div>
            @endif
            <div class="ui-form-grid__full">
                <label class="ui-label" for="tax_prices_include">{{ ___('Menu prices include VAT') }}</label>
                <select class="ui-input" id="tax_prices_include" name="tax_prices_include">
                    <option value="0" @selected((string) $optionValue('tax_prices_include', '0') !== '1')>{{ ___('No') }}</option>
                    <option value="1" @selected((string) $optionValue('tax_prices_include', '0') === '1')>{{ ___('Yes') }}</option>
                </select>
                <small class="ui-text-2">{{ ___('Used only when VAT per line is on.') }}</small>
            </div>
            {{-- J32 / P6-116: every uncertain rule is the store's own switch, OFF until turned on. --}}
            @foreach ([
                'tax_delivery_taxable' => ___('VAT on the delivery fee'),
                'tax_tray_taxable' => ___('VAT on the room-service charge'),
                'tax_service_taxable' => ___('VAT on the service charge'),
            ] as $feeKey => $feeLabel)
                <div class="ui-form-grid__full">
                    <label class="ui-label" for="{{ $feeKey }}">{{ $feeLabel }}</label>
                    <select class="ui-input" id="{{ $feeKey }}" name="{{ $feeKey }}">
                        <option value="0" @selected((string) $optionValue($feeKey, '0') !== '1')>{{ ___('No') }}</option>
                        <option value="1" @selected((string) $optionValue($feeKey, '0') === '1')>{{ ___('Yes') }}</option>
                    </select>
                    <small class="ui-text-2">{{ ___('Used only when VAT per line is on.') }}</small>
                </div>
            @endforeach
            @php
                $ratePresets = [];
                foreach (\Juwri\Modules\Ordering\Services\Tax\TaxCountryRules::RATE_PRESETS as $presetKey => $preset) {
                    if ($vatCountry === '' || $preset['country'] === strtoupper($vatCountry)) {
                        $ratePresets[$presetKey] = $preset;
                    }
                }
                $countryRate = \Juwri\Modules\Ordering\Services\Tax\TaxCountryRules::standardRate($vatCountry);
            @endphp
            @if ($countryRate !== null)
                <div class="ui-form-grid__full">
                    <small class="ui-text-2">{{ ___('Usual rate in your country: :rate%. You can change it.', ['rate' => rtrim(rtrim(number_format($countryRate, 2, '.', ''), '0'), '.') ?: '0']) }}</small>
                </div>
            @endif
            @if ($ratePresets !== [])
                <div class="ui-form-grid__full">
                    <label class="ui-label" for="tax_rate_preset">{{ ___('Special tax rate') }}</label>
                    <select class="ui-input" id="tax_rate_preset" name="tax_rate_preset">
                        <option value="" @selected((string) $optionValue('tax_rate_preset', '') === '')>{{ ___('None (use the tax rate above)') }}</option>
                        @foreach ($ratePresets as $presetKey => $preset)
                            <option value="{{ $presetKey }}" @selected((string) $optionValue('tax_rate_preset', '') === $presetKey)>{{ ___($preset['label']) }}</option>
                        @endforeach
                    </select>
                    <small class="ui-text-2">{{ ___('Choosing one sets your tax rate when you save.') }}</small>
                </div>
            @endif
            {{-- P6-175: the ZATCA reason code on zero-rated / exempt items (KSA only); '' = the default code --}}
            @if (strtoupper($vatCountry) === 'SA')
                @foreach ([
                    'Z' => ___('ZATCA reason for zero-rated items'),
                    'E' => ___('ZATCA reason for exempt items'),
                ] as $vatexCat => $vatexLabel)
                    @php
                        $vatexKey = \Juwri\Modules\Fiscal\Services\Zatca\Vatex::STORE_KEYS[$vatexCat];
                    @endphp
                    <div class="ui-form-grid__full">
                        <label class="ui-label" for="{{ $vatexKey }}">{{ $vatexLabel }}</label>
                        <select class="ui-input" id="{{ $vatexKey }}" name="{{ $vatexKey }}">
                            <option value="" @selected((string) $optionValue($vatexKey, '') === '')>{{ ___('Default (:code)', ['code' => \Juwri\Modules\Fiscal\Services\Zatca\Vatex::DEFAULTS[$vatexCat]]) }}</option>
                            @foreach (\Juwri\Modules\Fiscal\Services\Zatca\Vatex::choices($vatexCat) as $vatexCode => $vatexReason)
                                <option value="{{ $vatexCode }}" @selected((string) $optionValue($vatexKey, '') === $vatexCode)>{{ $vatexCode }} — {{ $vatexReason }}</option>
                            @endforeach
                        </select>
                        <small class="ui-text-2">{{ ___('Printed on your tax invoices for items you marked zero-rated or exempt. Check the right reason with your accountant.') }}</small>
                    </div>
                @endforeach
            @endif
            {{-- J32: pre-VAT levies (TaxLevies) — suggestions, all off, all editable --}}
            @php
                $levyRows = \Juwri\Modules\Ordering\Services\Tax\TaxLevies::forForm((object) ['tax_levies' => $optionValue('tax_levies', '[]')]);
                $levyRows[] = ['id' => '', 'name' => '', 'type' => 'percent', 'value' => '', 'base' => 'items', 'over' => null, 'value_over' => null, 'enabled' => false];
            @endphp
            <div class="ui-form-grid__full">
                <div class="ui-label">{{ ___('Extra charges before VAT') }}</div>
                <small class="ui-text-2">{{ ___('For example a municipality fee, table tax or stamp duty. Each one shows as its own line on receipts and invoices. All are off until you turn them on.') }}</small>
                <table class="ui-table">
                    <thead><tr>
                        <th>{{ ___('On') }}</th><th>{{ ___('Name') }}</th><th>{{ ___('Type') }}</th><th>{{ ___('Amount') }}</th>
                        <th>{{ ___('Charged on') }}</th><th>{{ ___('Above this total') }}</th><th>{{ ___('Rate above it') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($levyRows as $li => $levy)
                        <tr>
                            <td>
                                <input type="hidden" name="tax_levies[{{ $li }}][id]" value="{{ $levy['id'] }}">
                                <input type="hidden" name="tax_levies[{{ $li }}][enabled]" value="0">
                                <input type="checkbox" name="tax_levies[{{ $li }}][enabled]" value="1" @checked($levy['enabled']) aria-label="{{ ___('On') }}">
                            </td>
                            <td><input class="ui-input" name="tax_levies[{{ $li }}][name]" value="{{ $levy['name'] }}" maxlength="60" aria-label="{{ ___('Name') }}"></td>
                            <td>
                                <select class="ui-input" name="tax_levies[{{ $li }}][type]" aria-label="{{ ___('Type') }}">
                                    <option value="percent" @selected($levy['type'] === 'percent')>{{ ___('Percentage') }}</option>
                                    <option value="fixed" @selected($levy['type'] === 'fixed')>{{ ___('Fixed') }}</option>
                                </select>
                            </td>
                            <td><input class="ui-input" type="number" step="any" min="0" name="tax_levies[{{ $li }}][value]" value="{{ $levy['value'] }}" aria-label="{{ ___('Amount') }}"></td>
                            <td>
                                <select class="ui-input" name="tax_levies[{{ $li }}][base]" aria-label="{{ ___('Charged on') }}">
                                    <option value="items" @selected($levy['base'] === 'items')>{{ ___('Items') }}</option>
                                    <option value="total" @selected($levy['base'] === 'total')>{{ ___('Items and fees') }}</option>
                                </select>
                            </td>
                            <td><input class="ui-input" type="number" step="any" min="0" name="tax_levies[{{ $li }}][over]" value="{{ $levy['over'] }}" aria-label="{{ ___('Above this total') }}"></td>
                            <td><input class="ui-input" type="number" step="any" min="0" max="100" name="tax_levies[{{ $li }}][value_over]" value="{{ $levy['value_over'] }}" aria-label="{{ ___('Rate above it') }}"></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="ui-form-grid__full">
                <div class="ui-banner" role="note">
                    <span class="ui-banner__icon"><x-ui.icon name="info" size="18" /></span>
                    <div class="ui-banner__body">{{ ___('Tax rates are set by you. Check them with your accountant or tax authority.') }}</div>
                </div>
            </div>
            {{-- M16: read-only tax preview. Renders from the SAME authority the storefront
                 now uses (Order::computeTax → window.mbMoney.tax), so what the merchant sees
                 here equals the tax line every diner is quoted and charged. --}}
            <div class="ui-form-grid__full">
                @php
                    $mbTaxPreview = \App\Models\Order::computeTax(100.00, store_options($store->id));
                @endphp
                <div class="ui-banner" role="status">
                    <span class="ui-banner__icon"><x-ui.icon name="info" size="18" /></span>
                    <div class="ui-banner__body">
                    <strong>{{ ___('Tax preview') }}</strong> —
                    {{ ___('On a :basket order, your customers are quoted and charged :tax in tax.', [
                        'basket' => price_symbol_format(100.00),
                        'tax'    => price_symbol_format($mbTaxPreview),
                    ]) }}
                    @if (! $vatOn)
                    <span class="ui-block">{{ ___('Tax is always calculated on the subtotal before any coupon discount.') }}</span>
                    @endif
                    </div>
                </div>
            </div>
            {{-- TAX-RUN F0: fiscal identity & country (drives the fiscalization profile). --}}
            @if(\App\Services\FeatureRegistry::isEnabled('fiscalization'))
            <div class="ui-form-grid__full">
                <div class="ui-strong">{{ ___('Fiscal identity') }}</div>
                {{-- Pricing build: e-invoicing is a Pro / Chain feature (or an add-on on Starter) — billing edition hook. --}}
                {{ \Juwri\Core\Support\Hooks::first('owner.plan.einvoicing-notice', auth()->user()) }}
            </div>
            <div class="ui-form-grid__full">
                <label class="ui-label" for="fiscal_country">{{ ___('Fiscal country') }}</label>
                <select class="ui-input" id="fiscal_country" name="fiscal_country">
                    <option value="">{{ ___('None') }}</option>
                    @foreach(\Juwri\Modules\Fiscal\Services\FiscalRegistry::selectOptions() as $__cc => $__name)
                        <option value="{{ $__cc }}" @selected($optionValue('fiscal_country') === $__cc)>{{ country_display_name($__cc, $__name) }} ({{ $__cc }})</option>
                    @endforeach
                </select>
            </div>
            <div class="ui-form-grid__full">
                <x-ui.field name="fiscal_legal_name" :label="___('Legal name')" :value="$optionValue('fiscal_legal_name', '')" />
            </div>
            <div class="ui-form-grid__full">
                <x-ui.field name="fiscal_vat_number" :label="___('VAT / Tax registration number')" :value="$optionValue('fiscal_vat_number', '')" />
            </div>
            <div class="ui-form-grid__full">
                <x-ui.field name="fiscal_cr_number" :label="___('Commercial registration (CR) number')" :value="$optionValue('fiscal_cr_number', '')" />
            </div>
            {{-- P6-176: KSA national address of the seller — required on ZATCA standard (B2B) tax invoices. --}}
            @if(strtoupper((string) $optionValue('fiscal_country', '')) === 'SA')
                <div class="ui-form-grid__full" data-ksa-address>
                    <div class="ui-strong">{{ ___('National address (Saudi Arabia)') }}</div>
                    <p class="ui-muted">{{ ___('Needed for tax invoices to business buyers. Use the national address registered with ZATCA. The VAT number has 15 digits and starts and ends with 3.') }}</p>
                </div>
                <div class="ui-form-grid__full">
                    <x-ui.field name="fiscal_street" :label="___('Street')" :value="$optionValue('fiscal_street', '')" />
                </div>
                <div>
                    <x-ui.field name="fiscal_building_number" :label="___('Building number')" :value="$optionValue('fiscal_building_number', '')" inputmode="numeric" maxlength="4" :help="___('4 digits')" />
                </div>
                <div>
                    <x-ui.field name="fiscal_additional_number" :label="___('Additional number')" :value="$optionValue('fiscal_additional_number', '')" inputmode="numeric" maxlength="4" :help="___('4 digits')" />
                </div>
                <div>
                    <x-ui.field name="fiscal_district" :label="___('District')" :value="$optionValue('fiscal_district', '')" />
                </div>
                <div>
                    <x-ui.field name="fiscal_city" :label="___('City')" :value="$optionValue('fiscal_city', '')" />
                </div>
                <div>
                    <x-ui.field name="fiscal_postal_code" :label="___('Postal code')" :value="$optionValue('fiscal_postal_code', '')" inputmode="numeric" maxlength="5" :help="___('5 digits')" />
                </div>
            @endif
            @endif
        </div>

        </div>{{-- /x-data ordering groups --}}

        </div>
    </section>

    {{-- PARITY-RUN T2-3: Order board — the public Preparing/Ready screen and the
         daily token numbers that feed it. Rendered only when the feature is
         allowed for this store (see $boardFeature above). --}}
    @if($boardFeature)
    <section class="ui-card ui-settings__pane" x-show="tab === 'board'" x-cloak x-transition.opacity>
        <div class="ui-card__body">
        <div>
            <p class="ui-muted ui-caps">{{ ___('Order board') }}</p>
            <h3 class="ui-section-title">{{ ___('Preparing / Ready screen and daily numbers') }}</h3>
            <p class="ui-muted">
                {{ ___('Open the board link on a TV or tablet. Customers watch for their number instead of crowding the counter.') }}
            </p>
        </div>

        <div class="ui-form-grid">
            <div class="ui-form-grid__full">
                <label class="ui-label" for="order_board_enabled">{{ ___('Show the order board') }}</label>
                <select class="ui-input" id="order_board_enabled" name="order_board_enabled">
                    <option value="1" @selected((string) $optionValue('order_board_enabled', '0') === '1')>{{ ___('Yes') }}</option>
                    <option value="0" @selected((string) $optionValue('order_board_enabled', '0') !== '1')>{{ ___('No') }}</option>
                </select>
                <small class="ui-text-2">{{ ___('New orders start getting a daily number as soon as this is on.') }}</small>
            </div>

            <div class="ui-form-grid__full">
                <label class="ui-label" for="order_board_ready_minutes">{{ ___('Keep ready numbers on screen (minutes)') }}</label>
                <input type="number" min="0" max="240" class="ui-input" id="order_board_ready_minutes"
                       name="order_board_ready_minutes" value="{{ $optionValue('order_board_ready_minutes', 5) }}">
                <small class="ui-text-2">{{ ___('0 keeps a number up until the order is collected.') }}</small>
            </div>

            <div class="ui-form-grid__full">
                <label class="ui-label" for="order_board_chime">{{ ___('Play a chime when an order is ready') }}</label>
                <select class="ui-input" id="order_board_chime" name="order_board_chime">
                    <option value="1" @selected((string) $optionValue('order_board_chime', '1') !== '0')>{{ ___('Yes') }}</option>
                    <option value="0" @selected((string) $optionValue('order_board_chime', '1') === '0')>{{ ___('No') }}</option>
                </select>
                <small class="ui-text-2">{{ ___('The screen must be touched once before a browser will allow sound.') }}</small>
            </div>

            <div class="ui-form-grid__full">
                <div class="ui-label">{{ ___('Which order types get a number') }}</div>
                <div class="ui-form-grid">
                    @foreach($boardTypes as $typeKey => $typeLabel)
                        <div class="ui-form-grid__full">
                            <label class="ui-label ui-small" for="order_board_type_{{ $typeKey }}">{{ $typeLabel }}</label>
                            <select class="ui-input" id="order_board_type_{{ $typeKey }}" name="order_board_type_{{ $typeKey }}">
                                <option value="1" @selected((string) $optionValue('order_board_type_'.$typeKey, in_array($typeKey, ['takeaway', 'on_table'], true) ? '1' : '0') === '1')>{{ ___('Yes') }}</option>
                                <option value="0" @selected((string) $optionValue('order_board_type_'.$typeKey, in_array($typeKey, ['takeaway', 'on_table'], true) ? '1' : '0') !== '1')>{{ ___('No') }}</option>
                            </select>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div>
            <p class="ui-muted ui-caps">{{ ___('Board link') }}</p>
            <h3 class="ui-section-title">{{ ___('Open this on your screen') }}</h3>
        </div>

        <div class="ui-form-grid">
            <div class="ui-form-grid__full">
                <div class="ui-input-group">
                    <input type="text" class="ui-input" id="board-url" readonly
                           value="{{ route('board.show', $store->slug) }}" aria-label="{{ ___('Board link') }}">
                    <button class="ui-btn ui-btn--secondary" type="button"
                            data-board-copy="board-url" data-copied="{{ ___('Copied') }}">{{ ___('Copy') }}</button>
                    <a class="ui-btn ui-btn--secondary" href="{{ route('board.show', $store->slug) }}" target="_blank" rel="noopener">{{ ___('Open board') }}</a>
                </div>

                @foreach($boardBranches as $boardBranch)
                    <div class="ui-input-group">
                        <span class="ui-input-group__text">{{ $boardBranch->name }}</span>
                        <input type="text" class="ui-input" id="board-url-{{ $boardBranch->id }}" readonly
                               value="{{ route('board.show', $store->slug) }}?branch={{ $boardBranch->id }}">
                        <button class="ui-btn ui-btn--secondary" type="button"
                                data-board-copy="board-url-{{ $boardBranch->id }}" data-copied="{{ ___('Copied') }}">{{ ___('Copy') }}</button>
                        <a class="ui-btn ui-btn--secondary" href="{{ route('board.show', $store->slug) }}?branch={{ $boardBranch->id }}" target="_blank" rel="noopener">{{ ___('Open board') }}</a>
                    </div>
                @endforeach
            </div>

            {{-- T10 seam: the QR design suite will render this through its own
                 template engine. Until then it is the plain qr_svg() helper every
                 other admin screen uses. --}}
            <div class="ui-form-grid__full ui-center">
                <div class="ui-inline">{!! qr_svg(route('board.show', $store->slug), 150) !!}</div>
                <div class="ui-muted">{{ ___('Scan to open the board') }}</div>
            </div>
        </div>
        </div>
    </section>

    @push('scripts_at_bottom')
    <script nonce="{{ csp_nonce() }}">
        /* PARITY-RUN T2: copy-to-clipboard for the board links. Bound from a
           data attribute rather than an inline onclick, so a translation
           containing an apostrophe can never break the handler. */
        document.addEventListener('click', function (event) {
            var btn = event.target.closest('[data-board-copy]');
            if (!btn) { return; }
            var input = document.getElementById(btn.getAttribute('data-board-copy'));
            if (!input || !navigator.clipboard) { return; }
            navigator.clipboard.writeText(input.value).then(function () {
                btn.textContent = btn.getAttribute('data-copied') || btn.textContent;
            }).catch(function () {});
        });
    </script>
    @endpush
    @endif

    <section class="ui-card ui-settings__pane" x-show="tab === 'payments'" x-cloak x-transition.opacity>
        <div class="ui-card__body">
        <div>
            <p class="ui-muted ui-caps">{{ ___('Payments') }}</p>
            <h3 class="ui-section-title">{{ ___('Payment gateways') }}</h3>
        </div>
        <p class="ui-text-2">{{ ___('Enable and configure per-store checkout gateways. Keys are stored with this store.') }}</p>

        {{-- WI-06b / 2026-10-07 (owner decisions): automatic refunds are off by default, the store OWNER's choice only. --}}
        @unless (is_staff_dashboard())
        <input type="hidden" name="auto_refunds_enabled" value="0">
        <x-ui.switch name="auto_refunds_enabled" :checked="(string) $optionValue('auto_refunds_enabled', '0') === '1'"
                     :label="___('Refund automatically to the original payment')"
                     :description="___('When you refund an order, send the money back through the gateway that took it (Stripe, PayPal, Razorpay, Paystack). Off: refunds are tracked and you complete them in the gateway dashboard.')" />
        @if (empty(\Juwri\Core\Support\DeferredGateways::hidden()))
        <input type="hidden" name="auto_refunds_tap_moyasar" value="0">
        <x-ui.switch name="auto_refunds_tap_moyasar" :checked="(string) $optionValue('auto_refunds_tap_moyasar', '0') === '1'"
                     :label="___('Also refund Tap and Moyasar payments automatically')"
                     :description="___('Needs the switch above. Off: refunds of Tap and Moyasar payments stay a manual step in their dashboards.')" />
        @endif
        @endunless

        <div class="ui-stack">
            @foreach($gateways as $gk => $gconf)
                @php $installed = $optionValue('restaurant_'.$gk.'_install') == 1; @endphp
                <details class="ui-card" @if($installed) open @endif>
                    <summary class="ui-cluster ui-cluster--between ui-card__head" style="cursor:pointer">
                        <span class="ui-strong">{{ $gconf['label'] }}</span>
                        <x-ui.pill :tone="$installed ? 'success' : 'neutral'">{{ $installed ? ___('On') : ___('Off') }}</x-ui.pill>
                    </summary>
                    <div class="ui-card__body">
                        @if(!empty($gconf['help']))
                            <p class="ui-text-2"><a href="{{ $gconf['help'] }}" target="_blank" rel="noopener">{{ ___('Get API credentials') }} ↗</a></p>
                        @endif
                        <div class="ui-form-grid">
                            <div class="ui-form-grid__full">
                                <label class="ui-label" for="restaurant_{{ $gk }}_install">{{ ___('Status') }}</label>
                                <select class="ui-input" id="restaurant_{{ $gk }}_install" name="restaurant_{{ $gk }}_install">
                                    <option value="1" @selected($installed)>{{ ___('On') }}</option>
                                    <option value="0" @selected(!$installed)>{{ ___('Off') }}</option>
                                </select>
                            </div>
                            <div class="ui-form-grid__full">
                                <label class="ui-label" for="restaurant_{{ $gk }}_title">{{ ___('Title') }}</label>
                                <input class="ui-input" id="restaurant_{{ $gk }}_title" name="restaurant_{{ $gk }}_title" value="{{ $optionValue('restaurant_'.$gk.'_title') }}" placeholder="{{ $gconf['label'] }}">
                            </div>
                            @if(!empty($gconf['sandbox']))
                                <div class="ui-form-grid__full">
                                    <label class="ui-label" for="restaurant_{{ $gk }}_sandbox_mode">{{ ___('Sandbox mode') }}</label>
                                    <select class="ui-input" id="restaurant_{{ $gk }}_sandbox_mode" name="restaurant_{{ $gk }}_sandbox_mode">
                                        <option value="Yes" @selected($optionValue('restaurant_'.$gk.'_sandbox_mode') === 'Yes')>{{ ___('On') }}</option>
                                        <option value="No" @selected($optionValue('restaurant_'.$gk.'_sandbox_mode') !== 'Yes')>{{ ___('Off') }}</option>
                                    </select>
                                </div>
                            @endif
                            @foreach($gconf['fields'] as $f)
                                <div class="ui-form-grid__full">
                                    <label class="ui-label" for="gw-{{ $gk }}-{{ $f[0] }}">{{ ___($f[1]) }}</label>
                                    @if($gk === 'paytabs' && $f[0] === 'region')
                                        {{-- WI-06b: PayTabs account region (unknown values show "not configured"). --}}
                                        @php $ptRegion = (string) $optionValue('restaurant_paytabs_region'); @endphp
                                        <select class="ui-input" id="gw-{{ $gk }}-{{ $f[0] }}" name="restaurant_paytabs_region">
                                            @if($ptRegion !== '' && ! array_key_exists($ptRegion, $payTabsRegions))
                                                <option value="{{ $ptRegion }}" selected>{{ $ptRegion }}</option>
                                            @endif
                                            @foreach($payTabsRegions as $ptCode => $ptName)
                                                <option value="{{ $ptCode }}" @selected($ptRegion === $ptCode)>{{ $ptName }}</option>
                                            @endforeach
                                        </select>
                                    @elseif($gk === 'paymob' && $f[0] === 'region')
                                        {{-- P6-108b: the Paymob account's region host; blank = from the store country. --}}
                                        @php $pmRegion = (string) $optionValue('restaurant_paymob_region'); @endphp
                                        <select class="ui-input" id="gw-{{ $gk }}-{{ $f[0] }}" name="restaurant_paymob_region">
                                            <option value="" @selected(! in_array($pmRegion, ['eg', 'ksa', 'uae', 'omn'], true))>{{ ___('Automatic (from the store country)') }}</option>
                                            @foreach(['eg' => 'Egypt (accept.paymob.com)', 'ksa' => 'Saudi Arabia (ksa.paymob.com)', 'uae' => 'United Arab Emirates (uae.paymob.com)', 'omn' => 'Oman (oman.paymob.com)'] as $pmCode => $pmName)
                                                <option value="{{ $pmCode }}" @selected($pmRegion === $pmCode)>{{ $pmName }}</option>
                                            @endforeach
                                        </select>
                                    @else
                                        <input class="ui-input" id="gw-{{ $gk }}-{{ $f[0] }}" name="restaurant_{{ $gk }}_{{ $f[0] }}" value="{{ $optionValue('restaurant_'.$gk.'_'.$f[0]) }}" autocomplete="off">
                                    @endif
                                </div>
                            @endforeach
                            @if(in_array($gk, ['stripe', 'paypal'], true))
                                {{-- The store's OWN account notifies this store's URL; it is checked with the store's webhook secret / id only. --}}
                                <div class="ui-form-grid__full">
                                    <label class="ui-label" for="gw-{{ $gk }}-webhook">{{ ___('Webhook URL') }}</label>
                                    <input class="ui-input ui-ltr" id="gw-{{ $gk }}-webhook" type="text" readonly value="{{ route('webhook.store', ['gateway' => $gk, 'store' => $store->id]) }}" data-ui-do="select">
                                    <small class="ui-text-2 ui-block">{{ ___('Add this URL as a webhook in your own account and paste its secret (Stripe) or webhook ID (PayPal) above, so a payment settles even when the customer closes the page.') }}</small>
                                </div>
                            @endif
                            @if(in_array($gk, ['razorpay', 'paytm', 'iyzico', 'ccavenue', 'telr', 'moyasar'], true))
                                {{-- WI-06a/06b: the notification URL the merchant sets in the provider's panel. --}}
                                <div class="ui-form-grid__full">
                                    <label class="ui-label" for="gw-{{ $gk }}-webhook">{{ ___('Webhook URL') }}</label>
                                    <input class="ui-input ui-ltr" id="gw-{{ $gk }}-webhook" type="text" readonly value="{{ url('webhook/'.$gk) }}" data-ui-do="select">
                                    @if($gk === 'razorpay')
                                        <small class="ui-text-2 ui-block">{{ ___('Add this URL as a Razorpay webhook (payment.captured, order.paid) with the same secret.') }}</small>
                                    @elseif($gk === 'moyasar')
                                        <small class="ui-text-2 ui-block">{{ ___('Paste the secret token you set on the Moyasar webhook. Notifications without it are refused.') }}</small>
                                    @endif
                                </div>
                            @endif
                            @if($gk === 'mollie')
                                {{-- H4: Mollie settles server-side via this webhook even if the buyer closes the tab.
                                     The two status keys are written by MollieController, never by this form. --}}
                                <div class="ui-form-grid__full">
                                    <label class="ui-label" for="gw-{{ $gk }}-webhook">{{ ___('Webhook URL') }}</label>
                                    <input class="ui-input" id="gw-{{ $gk }}-webhook" type="text" readonly value="{{ url('webhook/mollie') }}" data-ui-do="select">
                                    <small class="ui-text-2 ui-block">
                                        {{ ___('Paid orders settle here even if the customer closes the tab.') }}
                                        @if($optionValue('restaurant_mollie_webhook_last_at'))
                                            {{ ___('Last callback: :when', ['when' => $optionValue('restaurant_mollie_webhook_last_at')]) }}
                                        @else
                                            {{ ___('No callback received yet.') }}
                                        @endif
                                    </small>
                                </div>
                            @endif
                        </div>
                    </div>
                </details>
            @endforeach
        </div>
        </div>
    </section>

    <section class="ui-card ui-settings__pane" x-show="tab === 'marketing'" x-cloak x-transition.opacity>
        <div class="ui-card__body">
        <div>
            <p class="ui-muted ui-caps">{{ ___('Marketing') }}</p>
            <h3 class="ui-section-title">{{ ___('Meta Pixel, social, SMS & birthday offers') }}</h3>
        </div>

        {{-- #23 wallets note --}}
        <p class="ui-text-2">{{ ___('Apple Pay / Google Pay turn on automatically at checkout once your Stripe keys are set in the Payments tab (and wallets are enabled in your Stripe dashboard).') }}</p>

        {{-- MCP-expansion P2-5: platform Catalog / AI discovery opt-out — relocated
             here from the retired Bio tab. --}}
        <h4 class="ui-section-title">{{ ___('AI & catalog discovery') }}</h4>
        <div class="ui-cluster">
            @php $catalogOn = old('catalog_visible', $store->catalog_visible ?? 1) == 1; @endphp
            <label class="ui-check">
                <input type="hidden" name="catalog_visible" value="0">
                <input type="checkbox" name="catalog_visible" value="1" @checked($catalogOn)>
                <span>{{ ___('List this store in the public AI catalog (lets AI assistants discover it)') }}</span>
            </label>
        </div>

        {{-- #101 Meta Pixel + Conversions API + presence --}}
        <h4 class="ui-section-title">{{ ___('Meta (Facebook/Instagram)') }}</h4>
        <div class="ui-form-grid">
            <div class="ui-form-grid__full">
                <x-ui.field name="meta_pixel_id" :label="___('Meta Pixel ID')" :value="$optionValue('meta_pixel_id')" :help="___('Fires ViewContent / Purchase on your menu.')" />
            </div>
            <div class="ui-form-grid__full">
                <x-ui.field name="meta_capi_token" type="password" :label="___('Conversions API token')" :value="$optionValue('meta_capi_token')" :help="___('Optional: server-side Purchase events (more accurate).')" />
            </div>
            <div class="ui-form-grid__full">
                <x-ui.field name="instagram_url" :label="___('Instagram URL')" :value="$optionValue('instagram_url')" />
            </div>
            <div class="ui-form-grid__full">
                <x-ui.field name="facebook_url" :label="___('Facebook URL')" :value="$optionValue('facebook_url')" />
            </div>
        </div>

        {{-- #104 transactional SMS --}}
        <h4 class="ui-section-title">{{ ___('Customer SMS alerts') }}</h4>
        <p class="ui-text-2">{{ ___('Requires SMS credentials in Integrations. Standard SMS rates apply.') }}</p>
        <div class="ui-form-grid">
            <div class="ui-form-grid__full">
                <label class="ui-label" for="customer_order_sms">{{ ___('Order-received SMS') }}</label>
                <select class="ui-input" id="customer_order_sms" name="customer_order_sms">
                    <option value="1" @selected($optionValue('customer_order_sms') == 1)>{{ ___('On') }}</option>
                    <option value="0" @selected($optionValue('customer_order_sms') != 1)>{{ ___('Off') }}</option>
                </select>
            </div>
            <div class="ui-form-grid__full">
                <label class="ui-label" for="customer_status_sms">{{ ___('Status-update SMS') }}</label>
                <select class="ui-input" id="customer_status_sms" name="customer_status_sms">
                    <option value="1" @selected($optionValue('customer_status_sms') == 1)>{{ ___('On') }}</option>
                    <option value="0" @selected($optionValue('customer_status_sms') != 1)>{{ ___('Off') }}</option>
                </select>
            </div>
            {{-- brand-row B3: notify followers of a new dish / offer (SMS/WhatsApp). Default OFF. --}}
            <div class="ui-form-grid__full">
                <label class="ui-label" for="follower_notify_enabled">{{ ___('Notify followers of new dishes & offers') }}</label>
                <select class="ui-input" id="follower_notify_enabled" name="follower_notify_enabled">
                    <option value="1" @selected($optionValue('follower_notify_enabled') == 1)>{{ ___('On') }}</option>
                    <option value="0" @selected($optionValue('follower_notify_enabled') != 1)>{{ ___('Off') }}</option>
                </select>
                <small class="ui-text-2">{{ ___('Sends at most one message per day to followers who opted in.') }}</small>
            </div>
            {{-- P3-06b (API-29): message the customer when they cancel an order that was waiting for payment. Default OFF. --}}
            <div class="ui-form-grid__full">
                <label class="ui-label" for="notify_customer_pending_payment_cancelled">{{ ___('Message when a customer cancels a pending payment') }}</label>
                <select class="ui-input" id="notify_customer_pending_payment_cancelled" name="notify_customer_pending_payment_cancelled">
                    <option value="1" @selected($optionValue('notify_customer_pending_payment_cancelled') == 1)>{{ ___('On') }}</option>
                    <option value="0" @selected($optionValue('notify_customer_pending_payment_cancelled') != 1)>{{ ___('Off') }}</option>
                </select>
            </div>
        </div>

        {{-- #63 birthday offers --}}
        <h4 class="ui-section-title">{{ ___('Birthday offers') }}</h4>
        <div class="ui-form-grid">
            <div class="ui-form-grid__full">
                <label class="ui-label" for="birthday_offers_enabled">{{ ___('Enable birthday coupons') }}</label>
                <select class="ui-input" id="birthday_offers_enabled" name="birthday_offers_enabled">
                    <option value="1" @selected($optionValue('birthday_offers_enabled') == 1)>{{ ___('On') }}</option>
                    <option value="0" @selected($optionValue('birthday_offers_enabled') != 1)>{{ ___('Off') }}</option>
                </select>
            </div>
            <div class="ui-form-grid__full">
                <label class="ui-label" for="birthday_channel">{{ ___('Delivery channel') }}</label>
                <select class="ui-input" id="birthday_channel" name="birthday_channel">
                    @php $bch = $optionValue('birthday_channel', 'email,sms'); @endphp
                    <option value="email,sms" @selected($bch === 'email,sms' || $bch === '')>{{ ___('Email + SMS') }}</option>
                    <option value="email" @selected($bch === 'email')>{{ ___('Email only') }}</option>
                    <option value="sms" @selected($bch === 'sms')>{{ ___('SMS only') }}</option>
                </select>
            </div>
            <div class="ui-form-grid__full">
                <x-ui.field name="birthday_reward_percentage" type="number" :label="___('Reward (% off)')" :value="$optionValue('birthday_reward_percentage', '10')" />
            </div>
            <div class="ui-form-grid__full">
                <x-ui.field name="birthday_validity_days" type="number" :label="___('Coupon valid for (days)')" :value="$optionValue('birthday_validity_days', '7')" />
            </div>
        </div>

        {{-- #34 AI auto-translate: tone + glossary --}}
        <h4 class="ui-section-title">{{ ___('AI menu translation') }}</h4>
        <div class="ui-form-grid">
            <div class="ui-form-grid__full">
                <label class="ui-label" for="translation_tone">{{ ___('Translation tone') }}</label>
                <select class="ui-input" id="translation_tone" name="translation_tone">
                    @php $tone = $optionValue('translation_tone', ''); @endphp
                    <option value="" @selected($tone === '')>{{ ___('Neutral (default)') }}</option>
                    <option value="formal" @selected($tone === 'formal')>{{ ___('Formal') }}</option>
                    <option value="casual" @selected($tone === 'casual')>{{ ___('Casual') }}</option>
                </select>
            </div>
            <div class="ui-form-grid__full">
                <label class="ui-label" for="translation_glossary">{{ ___('Glossary — one term per line; "term" to keep as-is, "term = translation" to fix') }}</label>
                <textarea class="ui-input" id="translation_glossary" name="translation_glossary" rows="4" placeholder="Shawarma&#10;Big Mac&#10;Coke = كوكاكولا">{{ $optionValue('translation_glossary') }}</textarea>
            </div>
        </div>
        </div>
    </section>

    {{-- #85: custom domains (managed via JSON endpoints, not the main form) --}}
    <section class="ui-card ui-settings__pane" x-show="tab === 'domains'" x-cloak x-transition.opacity>
        <div class="ui-card__body">
        <div>
            <p class="ui-muted ui-caps">{{ ___('Domains') }}</p>
            <h3 class="ui-section-title">{{ ___('Serve your menu on your own domain') }}</h3>
        </div>
        <div x-data="{
            list: [], target: '', newDomain: '', busy: false, msg: '',
            token: @js(csrf_token()),
            base: @js(url('stores/'.$store->id.'/domains')),
            async load() {
                try { const r = await fetch(this.base, { headers: { 'Accept':'application/json' } });
                    const d = await r.json(); this.list = d.domains || []; this.target = d.target || ''; } catch(e){}
            },
            async add() {
                if (!this.newDomain || this.busy) return; this.busy = true; this.msg = '';
                try { const r = await fetch(this.base, { method:'POST', headers:{ 'Content-Type':'application/json','X-CSRF-TOKEN':this.token,'Accept':'application/json' }, body: JSON.stringify({ domain: this.newDomain }) });
                    const d = await r.json(); if (!r.ok) { this.msg = d.error || 'Error'; } else { this.newDomain = ''; await this.load(); } }
                finally { this.busy = false; }
            },
            async verify(id) {
                this.busy = true; this.msg = '';
                try { const r = await fetch(this.base + '/' + id + '/verify', { method:'POST', headers:{ 'X-CSRF-TOKEN':this.token,'Accept':'application/json' } });
                    const d = await r.json(); this.msg = d.verified ? @js(___('Verified! Your menu will serve on this domain.')) : (d.message || @js(___('Not detected yet.'))); await this.load(); }
                finally { this.busy = false; }
            },
            async remove(id) {
                if (this.busy) return; this.busy = true;
                try { await fetch(this.base + '/' + id, { method:'DELETE', headers:{ 'X-CSRF-TOKEN':this.token,'Accept':'application/json' } }); await this.load(); }
                finally { this.busy = false; }
            }
        }" x-init="load()">
            <p class="ui-text-2">{{ ___('Add your domain, create the TXT record shown to prove you own it, and point a CNAME to the address shown. Then click Verify. SSL is issued automatically once verified.') }}</p>

            <div class="ui-form-grid">
                <div class="ui-form-grid__full">
                    <label class="ui-label" for="add-domain">{{ ___('Add a domain') }}</label>
                    <input type="text" class="ui-input" id="add-domain" x-model="newDomain" placeholder="menu.yourbrand.com" autocomplete="off">
                </div>
                <div class="ui-form-grid__full">
                    <x-ui.button type="button" icon="plus" x-bind:disabled="busy" @click="add()">{{ ___('Add domain') }}</x-ui.button>
                </div>
            </div>
            <p class="ui-text-2" x-show="target"><strong>{{ ___('CNAME target:') }}</strong> <code x-text="target"></code></p>
            <p class="ui-text-2" x-show="msg" x-text="msg"></p>

            <template x-if="list.length">
                <div class="ui-stack">
                    <template x-for="d in list" :key="d.id">
                        <div class="ui-bordered">
                            <div class="ui-cluster">
                                <strong class="ui-grow min-w-0 ui-break" x-text="d.domain"></strong>
                                <span class="ui-pill" :data-tone="d.verified ? 'success' : 'warning'" x-text="d.verified ? @js(___('Verified')) : @js(___('Pending'))"></span>
                                <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" x-show="!d.verified" @click="verify(d.id)">{{ ___('Verify') }}</button>
                                <button type="button" class="ui-btn ui-btn--danger ui-btn--sm" @click="remove(d.id)">{{ ___('Remove') }}</button>
                            </div>
                            {{-- DNS records (CNAME + TXT). Previously the edit tab omitted these,
                                 so owners here never saw the TXT token needed to verify. --}}
                            <template x-if="!d.verified">
                                <div>
                                    <p class="ui-muted">{{ ___('Add these records at your DNS provider, then click Verify. DNS changes usually take effect within a few minutes.') }}</p>
                                    <div class="ui-settings__dns-wrap">
                                        <table class="ui-table ui-settings__dns">
                                            <thead><tr><th>{{ ___('Type') }}</th><th>{{ ___('Host / Name') }}</th><th>{{ ___('Value / Points to') }}</th><th><span class="ui-visually-hidden">{{ ___('Copy') }}</span></th></tr></thead>
                                            <tbody>
                                                <tr>
                                                    <td data-primary><code>CNAME</code></td>
                                                    <td data-label="{{ ___('Host / Name') }}"><code class="ui-break" x-text="d.domain"></code></td>
                                                    <td data-label="{{ ___('Value / Points to') }}"><code class="ui-break" x-text="target"></code></td>
                                                    <td class="ui-catalog__row-actions"><button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" @click="navigator.clipboard && navigator.clipboard.writeText(target)">{{ ___('Copy') }}</button></td>
                                                </tr>
                                                <tr>
                                                    <td data-primary><code>TXT</code></td>
                                                    <td data-label="{{ ___('Host / Name') }}"><code class="ui-break" x-text="@js(\Juwri\Modules\Storefront\Services\DomainVerifier::CHALLENGE_LABEL.'.') + d.domain"></code></td>
                                                    <td data-label="{{ ___('Value / Points to') }}"><code class="ui-break" x-text="d.verification_token"></code></td>
                                                    <td class="ui-catalog__row-actions"><button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" @click="navigator.clipboard && navigator.clipboard.writeText(d.verification_token)">{{ ___('Copy') }}</button></td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <p class="ui-muted">{{ ___('Some providers want only the subdomain label in the Host field (for example "menu"). The CNAME is what routes visitors to your menu; the TXT record only proves ownership.') }}</p>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
            </template>
            <p class="ui-text-2" x-show="!list.length">{{ ___('No custom domains yet.') }}</p>
        </div>
        </div>
    </section>

    <section class="ui-card ui-settings__pane" x-show="tab === 'languages'" x-cloak x-transition.opacity>
        <div class="ui-card__body">
        <div>
            <p class="ui-muted ui-caps">{{ ___('Languages & currency') }}</p>
            <h3 class="ui-section-title">{{ ___('Menu language and currency') }}</h3>
        </div>

        <div class="ui-cluster">
            @foreach($activeLanguages as $code => $language)
                <label class="ui-check">
                    <input type="checkbox" name="menu_languages[]" value="{{ $code }}" @checked(in_array($code, $selectedLanguages))>
                    <span>{{ language_display_name($code, $language['name']) }}</span>
                </label>
            @endforeach
        </div>

        <div class="ui-form-grid">
            <div class="ui-form-grid__full">
                <label class="ui-label" for="default_language">{{ ___('Default Language') }}</label>
                <select class="ui-input" id="default_language" name="default_language">
                    <option value="">{{ ___('Choose') }}</option>
                    @foreach($activeLanguages as $code => $language)
                        <option value="{{ $code }}" @selected($optionValue('default_language') === $code)>{{ language_display_name($code, $language['name']) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="ui-form-grid__full">
                <x-ui.field name="currency_code" :label="___('Currency code')" :value="$optionValue('currency_code', config('settings.currency_code'))" required />
            </div>
            <div class="ui-form-grid__full">
                <x-ui.field name="currency_sign" :label="___('Currency sign')" :value="$optionValue('currency_sign', config('settings.currency_sign'))" required />
                {{-- S4: one-click fill of the official new currency symbol — Saudi
                     Riyal (U+20C1) or UAE Dirham (U+20C3). Explicit — never
                     auto-overwrites what the merchant typed. Shown only when the
                     currency code owns a new sign; the glyph is set by JS. --}}
                <button type="button" id="mb-use-riyal-symbol" class="ui-btn ui-btn--secondary ui-btn--sm" style="display:none">
                    {{ ___('Use new symbol') }} <span class="mb-use-sym-glyph">&#x20C1;</span>
                </button>
                @include('partials.currency-autofill')
            </div>
            <div class="ui-form-grid__full">
                <label class="ui-label" for="currency_pos">{{ ___('Currency position') }}</label>
                <select class="ui-input" id="currency_pos" name="currency_pos" required>
                    <option value="1" @selected((string) $optionValue('currency_pos', 1) === '1')>{{ ___('Before price') }}</option>
                    <option value="0" @selected((string) $optionValue('currency_pos', 0) === '0')>{{ ___('After price') }}</option>
                </select>
                <div class="ui-check">
                    {{-- Hidden 0 so an unchecked box still submits (checkbox overrides when checked). --}}
                    <input type="hidden" name="currency_rtl_auto" value="0">
                    <input type="checkbox" name="currency_rtl_auto" value="1" id="currency_rtl_auto"
                        @checked((string) $optionValue('currency_rtl_auto', '1') !== '0')>
                    <label for="currency_rtl_auto">
                        {{ ___('Place the symbol after the number for right-to-left languages (Arabic, Urdu, …). Recommended — the position above then applies only to left-to-right languages.') }}
                    </label>
                </div>
            </div>
            {{-- MC-12: a store with existing orders must confirm a currency change,
                 because it does not convert menu prices or past order totals. --}}
            @if (\App\Models\Order::where('store_id', $store->id)->exists())
                <div class="ui-form-grid__full">
                    <div class="ui-check">
                        <input type="checkbox" id="confirm_currency_change" name="confirm_currency_change" value="1">
                        <label for="confirm_currency_change">
                            {{ ___('I understand changing the currency will not convert existing prices or order totals.') }}
                        </label>
                    </div>
                </div>
            @endif
        </div>

        <div>
            <p class="ui-muted ui-caps">{{ ___('Currency') }}</p>
            <h3 class="ui-section-title">{{ ___('Multi-currency display') }}</h3>
        </div>
        {{-- FLAG-3 fix: `multi` stays a boolean for the x-show logic, but the
             select is NO LONGER x-model-bound — Alpine stringified the boolean and
             matched neither "1"/"0", snapping the control to the first option
             ("Yes") on init and writing enable_multi_currency=1 on a plain save.
             The server-rendered @selected now decides the option; @change keeps
             `multi` (and the dependent fields) in sync. --}}
        <div class="ui-form-grid" x-data="{ multi: @js($optionValue('enable_multi_currency') == 1), mode: @js($optionValue('currency_rates_mode') ?: 'manual') }">
            <div class="ui-form-grid__full">
                <label class="ui-label" for="enable_multi_currency">{{ ___('Show prices in multiple currencies') }}</label>
                <select class="ui-input" id="enable_multi_currency" name="enable_multi_currency" @change="multi = ($event.target.value == '1')">
                    <option value="1" @selected($optionValue('enable_multi_currency') == 1)>{{ ___('Yes') }}</option>
                    <option value="0" @selected($optionValue('enable_multi_currency') != 1)>{{ ___('No') }}</option>
                </select>
            </div>
            {{-- MC-33: rate source — manual lines, or the daily FX feed for a code list. --}}
            <div class="ui-form-grid__full" x-show="multi" x-cloak>
                <label class="ui-label" for="currency_rates_mode">{{ ___('Exchange rate source') }}</label>
                <select class="ui-input" id="currency_rates_mode" name="currency_rates_mode" x-model="mode">
                    <option value="manual" @selected(($optionValue('currency_rates_mode') ?: 'manual') === 'manual')>{{ ___('Manual rates') }}</option>
                    <option value="auto" @selected($optionValue('currency_rates_mode') === 'auto')>{{ ___('Automatic (daily FX feed)') }}</option>
                </select>
                <small class="ui-text-2">{{ ___('Automatic rates refresh daily and show an approximate marker; manual lines below always override.') }}</small>
            </div>
            <div class="ui-form-grid__full" x-show="multi && mode === 'auto'" x-cloak>
                <label class="ui-label" for="display_currencies">{{ ___('Display currencies (comma-separated ISO codes)') }}</label>
                <input type="text" class="ui-input" id="display_currencies" name="display_currencies" value="{{ $optionValue('display_currencies') }}" placeholder="EUR, GBP, SAR">
            </div>
            <div class="ui-form-grid__full" x-show="multi" x-cloak>
                <label class="ui-label" for="currency_rates_manual">{{ ___('Manual exchange rates (one per line, e.g. EUR=0.92)') }}</label>
                <textarea class="ui-input" id="currency_rates_manual" name="currency_rates_manual" rows="4" placeholder="EUR=0.92&#10;GBP=0.79&#10;JPY=149.5">{{ $optionValue('currency_rates_manual') }}</textarea>
            </div>
        </div>
        </div>
    </section>


    <section class="ui-card ui-settings__pane" x-show="tab === 'pages'" x-cloak x-transition.opacity>
        <div class="ui-card__body">
        <div>
            <p class="ui-muted ui-caps">{{ ___('Storefront pages') }}</p>
            <h3 class="ui-section-title">{{ ___('Terms, privacy, about & help') }}</h3>
            <p class="ui-muted">{{ ___('Shown in every theme’s account menu, and at your store link /page/…. Leave a box empty to hide that link. Plain text — line breaks are kept.') }}</p>
        </div>
        <div class="ui-stack">
            @foreach([
                'sf_page_terms' => ___('Terms & Conditions'),
                'sf_page_privacy' => ___('Privacy Policy'),
                'sf_page_about' => ___('About Us'),
                'sf_page_help' => ___('Help & FAQ'),
            ] as $pk => $plabel)
                <div>
                    <label class="ui-label" for="{{ $pk }}">{{ $plabel }}</label>
                    <textarea class="ui-input" id="{{ $pk }}" name="{{ $pk }}" rows="6" placeholder="{{ ___('Leave empty to hide this page') }}">{{ old($pk, $optionValue($pk)) }}</textarea>
                </div>
            @endforeach
        </div>
        </div>
    </section>

    {{-- rev/multi-vertical convert+duplicate: CONVERT this store's type in place,
         or DUPLICATE it as a new store under a different vertical. Hotel stores
         never render this pane (see the `tabs`/nav filters above). --}}
    @if(($store->store_type ?? 'restaurant') !== 'hotel')
        <section class="ui-card ui-settings__pane" x-show="tab === 'vertical'" x-cloak x-transition.opacity>
            <div class="ui-card__body">
                @include('owner.stores.partials.store-type-convert', ['store' => $store, 'user' => $user])
            </div>
        </section>
    @endif

    <section class="ui-card ui-settings__pane" x-show="tab === 'advanced'" x-cloak x-transition.opacity>
        <div class="ui-card__body">
        <div>
            <p class="ui-muted ui-caps">{{ ___('Advanced') }}</p>
            <h3 class="ui-section-title">{{ ___('Tracking and code') }}</h3>
        </div>

        <div class="ui-form-grid">
            <div class="ui-form-grid__full">
                <x-ui.field name="google_review_url" :label="___('Google review URL')" :value="$optionValue('google_review_url')" />
            </div>
            {{-- P9-4: raw custom-code fields (head/footer/CSS/JS + raw Pixel/Tag)
                 retired. Analytics + fonts are set as validated structured
                 integrations — safe by construction, no arbitrary script. --}}
            <p class="ui-muted ui-form-grid__full">
                {{ ___('Google Analytics, Meta Pixel and web fonts are now set as validated integrations.') }}
                @if(Route::has('integrations.structured.show'))
                    <a href="{{ route('integrations.structured.show', $store->id) }}">{{ ___('Open integrations') }}</a>.
                @endif
            </p>
        </div>

        {{-- rev/external-redirect B1: platform-migration opt-out. Redirect this
             store's public menu (and, independently, its bio page) to an external
             URL — printed QR codes keep working. --}}
        <hr>
        <div>
            <p class="ui-muted ui-caps">{{ ___('Migration') }}</p>
            <h3 class="ui-section-title">{{ ___('External redirect') }}</h3>
            <p class="ui-muted">{{ ___('Send visitors of your public menu to a different website. Your printed QR codes keep working.') }}</p>
        </div>

        @php
            $erEnabled = old('external_redirect_enabled', $optionValue('external_redirect_enabled')) == 1;
            $erInterstitial = old('external_redirect_interstitial', $optionValue('external_redirect_interstitial')) == 1;
            $bioErEnabled = old('bio_external_redirect_enabled', $optionValue('bio_external_redirect_enabled')) == 1;
        @endphp

        <div class="ui-form-grid">
            <div class="ui-form-grid__full">
                <label class="ui-check">
                    <input type="hidden" name="external_redirect_enabled" value="0">
                    <input type="checkbox" name="external_redirect_enabled" value="1" @checked($erEnabled)>
                    <span>{{ ___('Redirect my public menu to another URL') }}</span>
                </label>
            </div>
            <div class="ui-form-grid__full">
                <x-ui.field name="external_redirect_url" type="url" :label="___('Menu redirect URL')" :value="old('external_redirect_url', $optionValue('external_redirect_url'))" placeholder="https://legacy.example.com/{slug}{query}" />
                <p class="ui-muted">{{ ___('Use {slug} and {query} to forward the store name and table/QR parameters. Must start with https:// and point to a different site.') }}</p>
            </div>
            <div class="ui-form-grid__full">
                <label class="ui-check">
                    <input type="hidden" name="external_redirect_interstitial" value="0">
                    <input type="checkbox" name="external_redirect_interstitial" value="1" @checked($erInterstitial)>
                    <span>{{ ___('Show a "menu has moved" page for 2 seconds instead of redirecting instantly') }}</span>
                </label>
            </div>
        </div>

        <div>
            <h3 class="ui-section-title">{{ ___('Bio page redirect') }}</h3>
            <p class="ui-muted">{{ ___('An independent redirect for your bio / link page (always instant).') }}</p>
        </div>
        <div class="ui-form-grid">
            <div class="ui-form-grid__full">
                <label class="ui-check">
                    <input type="hidden" name="bio_external_redirect_enabled" value="0">
                    <input type="checkbox" name="bio_external_redirect_enabled" value="1" @checked($bioErEnabled)>
                    <span>{{ ___('Redirect my bio page to another URL') }}</span>
                </label>
            </div>
            <div class="ui-form-grid__full">
                <x-ui.field name="bio_external_redirect_url" type="url" :label="___('Bio redirect URL')" :value="old('bio_external_redirect_url', $optionValue('bio_external_redirect_url'))" placeholder="https://legacy.example.com/{slug}/bio" />
            </div>
        </div>
        </div>
    </section>

    </div>

    <div class="sticky-savebar" data-bridge-hide="form">
        @if(Route::has('stores.index'))
            <x-ui.button :href="route('stores.index')" variant="secondary">
                <span class="ui-btn__icon"><x-ui.icon name="back" :flip="true" /></span>
                {{ ___('Back') }}
            </x-ui.button>
        @endif
        <x-ui.button type="submit" icon="check" data-bridge--form-target="submit" data-bridge-title="{{ ___('Save') }}">
            {{ ___('Save changes') }}
        </x-ui.button>
    </div>
</form>
@endsection

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
    window.linenRestaurantEdit = function (config) {
        return {
            tab: config.tab || 'general',
            logoPreview: config.logoFallback,
            coverPreview: config.coverFallback,
            init: function () {
                // Deep link: /stores/{id}/edit#design opens that tab.
                // (Template switching moved to the customizer's Theme library.)
                var hash = (window.location.hash || '').slice(1);
                if (hash && (config.tabs || []).indexOf(hash) !== -1) {
                    this.tab = hash;
                }
                this.$watch('tab', function (t) {
                    history.replaceState(null, '', '#' + t);
                });
            },
            previewFile: function (event, target) {
                var file = event.target.files && event.target.files[0];

                if (!file) {
                    return;
                }

                this[target] = URL.createObjectURL(file);
            }
        };
    };
</script>
@endpush

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
    // P6-186 (J44): when KSA owners flip the engine off, ZATCA invoices move to whole-order VAT — confirm.
    document.addEventListener('change', function (event) {
        var sel = event.target;
        if (!sel || sel.id !== 'tax-engine-ksa' || sel.disabled) { return; }
        if (sel.value !== 'order') { return; }
        window.dashConfirm({ body: @json(___('ZATCA invoices and receipts will use whole-order VAT; check with your accountant')) }).then(function (ok) {
            if (!ok) { sel.value = 'vat'; }
        });
    });
</script>
@endpush
