<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
|--------------------------------------------------------------------------
| PCI DSS 4.0 req. 6.4.3 / 11.6.1 — payment-page script inventory
|--------------------------------------------------------------------------
| The authorised script list of every page that hosts payment entry or sends the buyer to it, kept as code
| (Juwri\Core\Pci\PaymentScriptInventory; how to add a script: docs/dev/pci-scripts.md). A script that is not
| listed for a page fails tests/Feature/Pci, and the page's enforced CSP does not allow its host.
| An installed edition package lists its own pages and scripts in its `payment_scripts` manifest file.
*/

return [

    'csp' => [
        // Send the strict payment-page policy at all (off = the page keeps its usual storefront/dashboard policy).
        'enabled' => (bool) env('PCI_CSP_ENABLED', true),
        // Enforce it (default). false = report-only, for a short investigation window only.
        'enforce' => (bool) env('PCI_CSP_ENFORCE', true),
    ],

    'reports' => [
        // Stored CSP violation rows per payment page and hour; the rest is dropped (flood guard).
        'max_per_page_per_hour' => (int) env('PCI_CSP_REPORTS_PER_HOUR', 200),
        // Requests per minute per client on the report endpoint.
        'per_minute' => (int) env('PCI_CSP_REPORTS_PER_MINUTE', 30),
        'retention_days' => (int) env('PCI_CSP_REPORTS_RETENTION_DAYS', 90),
    ],

    /*
     * Req. 11.6.1 change detection (pci:check-payment-pages). Weekly by default; any cron expression.
     * `targets`: extra pages to fetch as a customer would — JSON list of {"page": "<key>", "url": "<absolute or /path>"},
     * e.g. a live store's gift-card page. `probe_store`: slug of the store whose storefront payment pages the built-in
     * probes render (default: the oldest active store); probes render the real views with a synthetic payment.
     */
    'monitor' => [
        'enabled' => (bool) env('PCI_MONITOR_ENABLED', true),
        'cron' => (string) env('PCI_MONITOR_CRON', '30 4 * * 1'),
        'targets' => json_decode((string) env('PCI_MONITOR_TARGETS', '[]'), true) ?: [],
        'probes' => (bool) env('PCI_MONITOR_PROBES', true),
        'probe_store' => env('PCI_PROBE_STORE'),
        'timeout' => (int) env('PCI_MONITOR_TIMEOUT', 20),
        'user_agent' => 'Mozilla/5.0 (compatible; juwri-payment-page-monitor/1.0; PCI DSS 11.6.1)',
        // Headers compared with the baseline (lower case). The CSP nonce is normalised before hashing.
        'headers' => ['content-security-policy', 'content-security-policy-report-only', 'reporting-endpoints', 'x-frame-options',
            'x-content-type-options', 'strict-transport-security', 'referrer-policy', 'permissions-policy', 'cross-origin-opener-policy'],
    ],

    /*
     * Payment pages. kind: entry = hosts payment entry (provider widget, card form, hand-off to the provider);
     * choice = the gateway choice that sends the buyer to payment entry. shell = the layout that draws it.
     */
    'pages' => [
        'storefront.pay' => ['views' => ['store_templates.payment'], 'shell' => 'storefront', 'kind' => 'choice',
            'reason' => 'Storefront order payment page: the diner picks a gateway and is sent to it (payment.index).',
            // The chosen gateway answers the POST with a redirect to its hosted page (any of the 30 gateways).
            'csp' => ['form-action' => ['https:']]],
        'storefront.bank_transfer' => ['views' => ['store_templates.gateways.bank-transfer'], 'shell' => 'storefront', 'kind' => 'entry',
            'reason' => 'Bank-transfer instructions of a store payment (manual tender, no card data).'],
        'storefront.wallet_transfer' => ['views' => ['store_templates.gateways.wallet-transfer'], 'shell' => 'storefront', 'kind' => 'entry',
            'reason' => 'Wallet-transfer claim form of a store payment (manual tender, no card data).'],
        'storefront.wallet_topup' => ['views' => ['store_templates._partials.wallet-topup'], 'shell' => 'standalone', 'kind' => 'choice',
            'reason' => 'Customer wallet top-up: amount + gateway choice, then the gateway.', 'csp' => ['form-action' => ['https:']]],
        'gateway.midtrans' => ['views' => ['owner.billing.gateways.midtrans'], 'shell' => 'bare', 'kind' => 'entry',
            'reason' => 'Midtrans Snap payment popup (card entry inside Midtrans\' frame).'],
        'gateway.paystack' => ['views' => ['owner.billing.gateways.paystack'], 'shell' => 'bare', 'kind' => 'entry',
            'reason' => 'Paystack Inline payment popup (card entry inside Paystack\'s frame).'],
        'gateway.razorpay' => ['views' => ['owner.billing.gateways.razorpay'], 'shell' => 'bare', 'kind' => 'entry',
            'reason' => 'Razorpay Standard Checkout popup (card entry inside Razorpay\'s frame).'],
        'gateway.two_checkout' => ['views' => ['owner.billing.gateways.two-checkout'], 'shell' => 'bare', 'kind' => 'entry',
            'reason' => '2Checkout card form: 2co.js tokenises the card in the browser; only the token is posted to us.'],
        'gateway.redirect_form' => ['views' => ['owner.billing.gateways.redirect-form'], 'shell' => 'standalone', 'kind' => 'entry',
            'reason' => 'Auto-posting hand-off to a provider\'s hosted payment page (CCAvenue, Paytm).',
            'csp' => ['form-action' => ['https://secure.ccavenue.com', 'https://test.ccavenue.com', 'https://securegw.paytm.in', 'https://securegw-stage.paytm.in']]],
        'gateway.wire_transfer' => ['views' => ['owner.billing.gateways.wire-transfer'], 'shell' => 'bare', 'kind' => 'entry',
            'reason' => 'Bank-deposit instructions of a platform payment (manual tender, no card data).'],
        'giftcards.buy' => ['views' => ['giftcards.buy'], 'shell' => 'standalone', 'kind' => 'choice',
            'reason' => 'Gift-card purchase: amount + gateway choice, then the gateway.', 'csp' => ['form-action' => ['https:']]],
        'catering.deposit' => ['views' => ['catering.deposit-pay'], 'shell' => 'standalone', 'kind' => 'choice',
            'reason' => 'Catering deposit: gateway choice, then the gateway.', 'csp' => ['form-action' => ['https:']]],
        'reservations.deposit' => ['views' => ['reservations.deposit-pay'], 'shell' => 'standalone', 'kind' => 'choice',
            'reason' => 'Table-reservation deposit: gateway choice, then the gateway.', 'csp' => ['form-action' => ['https:']]],
        'owner.app_purchase' => ['views' => ['owner.marketplace.app-show'], 'shell' => 'dashboard', 'kind' => 'choice',
            'reason' => 'App Store app page: a paid install tops the wallet up through a gateway.',
            'unsafe_eval' => 'Dashboard shell runs Alpine.js, which evaluates its x-data/@click expressions; script sources stay pinned.',
            'csp' => ['form-action' => ['https:']]],
        'owner.theme_purchase' => ['views' => ['owner.marketplace.theme-show'], 'shell' => 'dashboard', 'kind' => 'choice',
            'reason' => 'Theme Store theme page: a paid theme tops the wallet up through a gateway.',
            'unsafe_eval' => 'Dashboard shell runs Alpine.js, which evaluates its x-data/@click expressions; script sources stay pinned.',
            'csp' => ['form-action' => ['https:']]],
    ],

    /*
     * Authorised scripts. First-party `src` = path from the site root (the `?v=` cache buster is ignored);
     * third-party `src` = full https:// URL with `integrity` (vendor serves a fixed file) or `sri_exempt` (why not).
     */
    'scripts' => [
        // ---- storefront layout (store_templates.layout) ----
        'jquery' => ['src' => '/assets/global/js/jquery.min.js', 'pages' => ['shell:storefront', 'shell:bare', 'shell:dashboard'],
            'reason' => 'jQuery 3 (self-hosted): storefront theme scripts and the gateway widgets that need it (Razorpay button, 2Checkout form).'],
        'popper' => ['src' => 'https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.11.0/umd/popper.min.js', 'pages' => ['shell:storefront'],
            'reason' => 'Popper 1.11 (tooltip/dropdown positioning used by the storefront themes).',
            'integrity' => 'sha384-b/U6ypiBEHpOf/4+1nzFpr53nxSS+GLCkfwBdFNTxtclqqenISfwAzpKaMNFNmj4'],
        'storefront-money' => ['src' => '/assets/store_templates/_shared/js/mb-money.js', 'pages' => ['shell:storefront'],
            'reason' => 'Storefront money formatting (amounts in the store currency, minor units).'],
        'storefront-currency' => ['src' => '/assets/store_templates/_shared/js/mb-currency.js', 'pages' => ['shell:storefront'],
            'reason' => 'Storefront display-currency estimate switcher.'],
        'storefront-notify' => ['src' => '/assets/store_templates/_shared/js/mb-notify.js', 'pages' => ['shell:storefront'],
            'reason' => 'Storefront toast/notice helper (payment errors, declined card message).'],
        'storefront-theme' => ['src' => '/assets/store_templates/_shared/js/mb-theme.js', 'pages' => ['shell:storefront'],
            'reason' => 'Storefront light/dark theme switch.'],
        'storefront-reorder' => ['src' => '/assets/store_templates/_shared/js/reorder-pickup.js', 'pages' => ['shell:storefront'],
            'reason' => 'P6-301 "order again": reads the diner\'s own reorder lines from localStorage into the menu cart (self-hosted, no network, no payment data).'],
        'storefront-theme-script' => ['src' => '/assets/store_templates/*/js/script.js', 'pages' => ['shell:storefront'],
            'reason' => 'The store\'s theme script (one self-hosted file per theme under assets/store_templates/<theme>/js/).'],
        // P3-13: the store's service worker. A worker registered by a page handles every request in its scope — on a
        // store's own address (scope "/") that includes the payment pages — so it is listed like a script.
        'storefront-sw-menu' => ['src' => '/sw-menu.js', 'pages' => ['shell:storefront'],
            'reason' => 'Per-store menu service worker of the legacy and premium (D11) themes: offline menu browsing and order push notifications. Self-hosted and versioned; it handles GET requests only (a payment POST never passes through it) and never stores a no-store/private response.',
            'loaded_by' => 'store_templates.layout (navigator.serviceWorker.register, scope = the store address; not on a kit theme)'],
        'storefront-sw-store' => ['src' => '/sw-store.js', 'pages' => ['shell:storefront'],
            'reason' => 'Per-store service worker of the kit themes (offline menu snapshot, offline order queue). Registered by the kit layouts, not by a payment page, but a payment page in its scope is fetched through it: network only, a page is kept only when the server marks it anonymous (never a payment page).',
            'loaded_by' => 'store_templates._partials.storefront-core (kit layouts)'],
        // Store analytics (owner-configured, consent-gated: they load only after the visitor accepts the cookie category).
        'google-tag' => ['src' => 'https://www.googletagmanager.com/gtag/js?id=*', 'pages' => ['shell:storefront', 'shell:dashboard'],
            'reason' => 'Google Analytics 4 tag of the store or the platform (checkout conversion), loaded only after analytics consent.',
            'sri_exempt' => 'Google generates gtag.js per measurement id and changes it without notice (SRI impossible); pinned to Google\'s tag host by the page CSP.',
            'loaded_by' => 'partials.analytics / store_templates.partials.structured-integrations (consent banner)',
            'csp' => ['script-src' => ['https://www.googletagmanager.com'], 'connect-src' => ['https://www.google-analytics.com', 'https://*.google-analytics.com', 'https://*.analytics.google.com', 'https://www.googletagmanager.com'], 'img-src' => ['https://www.google-analytics.com', 'https://www.googletagmanager.com']]],
        'google-tag-manager' => ['src' => 'https://www.googletagmanager.com/gtm.js?id=*', 'pages' => ['shell:storefront'],
            'reason' => 'The store\'s own Google Tag Manager container (structured integrations), loaded only after marketing consent.',
            'sri_exempt' => 'Google generates gtm.js per container (SRI impossible); only Google\'s tag host is allowed, so tags that load other hosts are refused by the page CSP.',
            'loaded_by' => 'store_templates.partials.structured-integrations (consent banner)',
            'csp' => ['script-src' => ['https://www.googletagmanager.com'], 'frame-src' => ['https://www.googletagmanager.com']]],
        'meta-pixel' => ['src' => 'https://connect.facebook.net/en_US/fbevents.js', 'pages' => ['shell:storefront'],
            'reason' => 'The store\'s own Meta pixel (structured integrations: ad conversion), loaded only after marketing consent.',
            'sri_exempt' => 'Meta updates fbevents.js in place and loads per-pixel config from the same host (SRI impossible); pinned to that host by the page CSP.',
            'loaded_by' => 'store_templates.partials.structured-integrations (consent banner)',
            'csp' => ['script-src' => ['https://connect.facebook.net'], 'connect-src' => ['https://www.facebook.com', 'https://connect.facebook.net'], 'img-src' => ['https://www.facebook.com']]],

        // ---- bare gateway layout (owner.layout-bare) + dashboard shell ----
        'owner-ui-actions' => ['src' => '/assets/owner-ui/actions.js', 'pages' => ['shell:bare', 'shell:dashboard'],
            'reason' => 'Owner UI kit: delegated data-ui-* button actions (no inline handlers), e.g. the Midtrans "Pay now" button.'],
        'owner-ui' => ['src' => '/assets/owner-ui/owner-ui.js', 'pages' => ['shell:dashboard', 'shell:reseller'],
            'reason' => 'Owner UI kit runtime (sheets, dialogs, toasts, form helpers).'],
        'owner-ui-layout' => ['src' => '/assets/owner-ui/layout.js', 'pages' => ['shell:dashboard', 'shell:reseller'],
            'reason' => 'Dashboard shell layout (sidebar, top bar, responsive navigation).'],
        'owner-ui-services' => ['src' => '/assets/owner-ui/services.js', 'pages' => ['shell:dashboard'],
            'reason' => 'Dashboard services (data-lm hooks, toast store) used by the shell partials.'],
        'owner-ui-pwa' => ['src' => '/assets/owner-ui/pwa.js', 'pages' => ['shell:dashboard'],
            'reason' => 'Dashboard PWA install prompt and service-worker registration.'],
        'owner-ui-turbo-compat' => ['src' => '/assets/owner-ui/turbo-compat.js', 'pages' => ['shell:dashboard'],
            'reason' => 'Keeps the dashboard\'s native forms working under Turbo Drive (payment pages turn Turbo off, see the shell head).'],
        'turbo' => ['src' => '/assets/vendor/turbo/turbo.min.js', 'pages' => ['shell:dashboard'],
            'reason' => 'Hotwire Turbo (self-hosted): dashboard link navigation and the owner app shell.'],
        'alpine' => ['src' => '/assets/vendor/alpinejs/alpine.min.js', 'pages' => ['shell:dashboard', 'shell:reseller'],
            'reason' => 'Alpine.js (self-hosted, version checked by VendoredAlpineTest): dashboard interactivity (checkout gateway/billing toggles).'],
        'apexcharts' => ['src' => '/assets/vendor/apexcharts/apexcharts.min.js', 'pages' => ['shell:dashboard'],
            'reason' => 'ApexCharts (self-hosted): loaded by the shared dashboard shell for chart widgets.'],
        'howler' => ['src' => '/assets/js/howler.min.js', 'pages' => ['shell:dashboard'],
            'reason' => 'Howler (self-hosted): new-order sound of the dashboard shell.'],
        'notify-sounds' => ['src' => '/assets/js/notify-sounds.js', 'pages' => ['shell:dashboard'],
            'reason' => 'Dashboard new-order sound player.'],
        'owner-app-bridge' => ['src' => '/assets/owner-app/bridge.js', 'pages' => ['shell:dashboard'],
            'reason' => 'Owner mobile app bridge (loaded only inside the native app shell).'],

        // ---- gateway entry pages (third-party SDKs) ----
        'midtrans-snap' => ['src' => 'https://app.midtrans.com/snap/snap.js', 'pages' => ['gateway.midtrans'],
            'reason' => 'Midtrans Snap: opens the Midtrans payment popup (card data never touches our page).',
            'sri_exempt' => 'Midtrans updates snap.js in place and publishes no SRI hash; pinned to this exact URL by the page CSP.',
            'csp' => ['frame-src' => ['https://app.midtrans.com'], 'connect-src' => ['https://app.midtrans.com', 'https://api.midtrans.com']]],
        'midtrans-snap-sandbox' => ['src' => 'https://app.sandbox.midtrans.com/snap/snap.js', 'pages' => ['gateway.midtrans'],
            'reason' => 'Midtrans Snap, sandbox mode (a store or the platform testing with sandbox keys).',
            'sri_exempt' => 'Midtrans updates snap.js in place and publishes no SRI hash; pinned to this exact URL by the page CSP.',
            'csp' => ['frame-src' => ['https://app.sandbox.midtrans.com'], 'connect-src' => ['https://app.sandbox.midtrans.com', 'https://api.sandbox.midtrans.com']]],
        'paystack-inline' => ['src' => 'https://js.paystack.co/v1/inline.js', 'pages' => ['gateway.paystack'],
            'reason' => 'Paystack Inline: opens the Paystack payment popup (card data never touches our page).',
            'sri_exempt' => 'Paystack serves inline.js as a living file (no SRI support); pinned to this exact URL by the page CSP.',
            'csp' => ['frame-src' => ['https://checkout.paystack.com'], 'connect-src' => ['https://api.paystack.co', 'https://checkout.paystack.com']]],
        'razorpay-checkout' => ['src' => 'https://checkout.razorpay.com/v1/checkout.js', 'pages' => ['gateway.razorpay'],
            'reason' => 'Razorpay Standard Checkout: opens the Razorpay payment popup (card data never touches our page).',
            'sri_exempt' => 'Razorpay updates checkout.js in place and asks merchants not to pin or self-host it; pinned to this exact URL by the page CSP.',
            'csp' => ['frame-src' => ['https://api.razorpay.com', 'https://checkout.razorpay.com'], 'connect-src' => ['https://api.razorpay.com', 'https://lumberjack.razorpay.com']]],
        'two-checkout-2co' => ['src' => 'https://www.2checkout.com/checkout/api/2co.min.js', 'pages' => ['gateway.two_checkout'],
            'reason' => '2Checkout 2co.js: turns the card fields into a one-time token in the browser (raw card data is never posted to us).',
            'sri_exempt' => '2Checkout updates 2co.min.js in place; its token call is a JSONP script from the same host, so the host is allow-listed.',
            'csp' => ['script-src' => ['https://www.2checkout.com'], 'connect-src' => ['https://www.2checkout.com'], 'frame-src' => ['https://www.2checkout.com']]],
        'jquery-payment' => ['src' => '/assets/global/js/jquery.payment.js', 'pages' => ['gateway.two_checkout'],
            'reason' => 'jquery.payment (self-hosted): card number/expiry/CVC formatting and Luhn check on the 2Checkout form.'],
    ],

    /*
     * Scripts of a shared layout that are switched OFF on payment pages (the layout checks
     * Juwri\Core\Pci\PaymentPages::current()). The source scan accepts them only from here; a rendering test proves
     * each is absent from the payment pages.
     */
    'excluded' => [
        'tawk-chat' => ['src' => 'https://embed.tawk.to/*', 'pages' => ['*'],
            'reason' => 'Optional Tawk.to live chat (admin setting): no business need on a payment page, so partials.quick-addons never loads it there.'],
        'wati-chat' => ['src' => 'https://wati-integration-service.clare.ai/ShopifyWidget/shopifyWidget.js*', 'pages' => ['shell:storefront'],
            'reason' => 'Optional third-party WhatsApp chat bubble (admin opt-in): no business need on a payment page, so store_templates.layout never loads it there.'],
    ],
];
