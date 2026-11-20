{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    // Admin navigation model: sections → groups → items [route, label, glyph, badge, routeIs pattern].
    // Glyphs are the retired icon-font keys; <x-ui.shell.nav-icon> maps them to Tabler/kit SVGs.
    // A tab is "active" when the current route matches any of its items → its rail
    // section opens and the item gets aria-current. Server-side so absolute route()
    // URLs highlight correctly.
    $has  = fn ($name) => Route::has($name);
    $on   = fn ($pattern) => request()->routeIs($pattern);
    $item = function ($route, $label, $icon, $badge = null, $pattern = null) use ($has, $on) {
        if (! Route::has($route)) return null;
        $pattern ??= preg_replace('/\.(index|overview|settings|funnel|partnerOps)$/', '.*', $route);
        // routes that are a single leaf (no resource siblings) still match themselves
        return [
            'route'  => $route,
            'url'    => route($route),
            'label'  => $label,
            'icon'   => $icon,
            'badge'  => $badge,
            'active' => $on($pattern) || $on($route),
        ];
    };

    $tabs = [
        [
            'key' => 'dashboard', 'title' => ___('Dashboard & Ops'), 'rail' => 'fi-rr-apps',
            'groups' => [
                [___('Overview'), array_filter([
                    $item('admin.dashboard', ___('Dashboard'), 'fi-rr-house-blank', null, 'admin.dashboard'),
                    $item('admin.analytics', ___('Analytics'), 'fi-rr-chart-histogram', null, 'admin.analytics*'),
                    $item('admin.ownerMobileUsage', ___('Owner mobile usage'), 'fi-rr-smartphone', null, 'admin.ownerMobileUsage*'),
                    $item('admin.notifications.index', ___('Notifications'), 'fi-rr-bell', $totalUnreadNotifications ?? 0, 'admin.notifications.*'),
                ])],
                [___('Operations'), array_filter([
                    $item('admin.orders.index', ___('Orders'), 'fi-rr-shopping-cart'),
                    $item('admin.support.index', ___('Support Queue'), 'fi-rr-headset', null, 'admin.support.*'),
                    $item('admin.webhooks.index', ___('Webhooks'), 'fi-rr-sitemap'),
                    $item('admin.integrations.index', ___('Integrations'), 'fi-rr-apps-add', null, 'admin.integrations.*'),
                    $item('admin.alerts.index', ___('Platform Alerts'), 'fi-rr-bell-ring', null, 'admin.alerts.*'),
                    $item('admin.menu-cache.index', ___('Menu Cache'), 'fi-rr-refresh', null, 'admin.menu-cache.*'),
                    $item('admin.backup-health.index', ___('Backup Health'), 'fi-rr-shield-check', null, 'admin.backup-health.*'),
                    $item('admin.security.accounts', ___('Account security'), 'fi-rr-lock', null, 'admin.security.*'),
                    $item('admin.failed-jobs.index', ___('Queue Health'), 'fi-rr-cross-circle', null, 'admin.failed-jobs.*'),
                    $item('admin.ai-usage.index', ___('AI Usage'), 'fi-rr-robot'),
                    $item('admin.ai-providers.index', ___('AI Providers'), 'fi-rr-key'),
                    $item('admin.social-providers.index', ___('Social Login'), 'fi-rr-following'),
                    $item('admin.mobile-push.index', ___('Mobile push'), 'fi-rr-mobile-notch', null, 'admin.mobile-push.*'),
                    $item('admin.email-suppressions.index', ___('Suppressed e-mails'), 'fi-rr-envelope-ban', null, 'admin.email-suppressions.*'), // P6-249
                    $item('admin.public-media.index', ___('Public Media Library'), 'fi-rr-picture', null, 'admin.public-media.*'),
                    $item('admin.media-sources.index', ___('Media Sources'), 'fi-rr-cloud-upload', null, 'admin.media-sources.*'),
                    $item('admin.activity.index', ___('Activity Log'), 'fi-rr-time-past'),
                    $item('admin.legal-acceptances.index', ___('Legal acceptances'), 'fi-rr-document', null, 'admin.legal-acceptances.*'),
                ])],
            ],
        ],
        [
            'key' => 'restaurants', 'title' => ___('Restaurants & Users'), 'rail' => 'fi-rr-users',
            'groups' => [
                [___('Customers'), array_filter([
                    $item('admin.users.index', ___('Users'), 'fi-rr-users'),
                    $item('admin.stores.index', ___('Stores'), 'fi-rr-store-alt'),
                    $item('admin.subscriber.index', ___('Newsletter'), 'fi-rr-envelope'),
                ])],
            ],
        ],
        [
            'key' => 'billing', 'title' => ___('Plans & Billing'), 'rail' => 'fi-rr-chart-pie',
            'groups' => [
                [___('Plans'), array_filter([
                    $item('admin.plans.index', ___('Membership Plans'), 'fi-rr-layers'),
                    $item('admin.planoption.index', ___('Plan Options'), 'fi-rr-list-check'),
                    $item('admin.feature-matrix.index', ___('Feature Matrix'), 'fi-rr-apps'),
                    // Pricing follow-ups: list price per market currency (SAR / AED / EGP).
                    $item('admin.market-prices.index', ___('Market Prices'), 'fi-rr-globe'),
                    // Credit packs (PRICING.md §8): provider costs + pack catalogue.
                    $item('admin.credits.index', ___('Prepaid credits'), 'fi-rr-coins', null, 'admin.credits.*'),
                    /* Add-on Products + Feature Catalog moved to the "Apps, Themes
                       & Add-ons" tab — they are catalog, not plan configuration. */
                ])],
                [___('Billing'), array_filter([
                    $item('admin.transactions.index', ___('Transactions'), 'fi-rr-receipt'),
                    $item('admin.taxes.index', ___('Taxes'), 'fi-rr-bank'),
                    $item('admin.fiscal.compliance', ___('Fiscal Compliance'), 'fi-rr-check-circle', null, 'admin.fiscal.compliance'),
                    $item('admin.pharmacy-licences.index', ___('Pharmacy licences'), 'fi-rr-document', null, 'admin.pharmacy-licences.*'),
                    $item('admin.offers.index', ___('Offers'), 'fi-rr-badge-percent', null, 'admin.offers.*'),
                    $item('admin.coupons.index', ___('Coupon Codes'), 'fi-rr-ticket'),
                    $item('admin.couponsWallets.overview', ___('Coupons & Wallets'), 'fi-rr-gift'),
                    $item('admin.gateways.index', ___('Payment Gateways'), 'fi-rr-credit-card', null, 'admin.gateways.*'),
                    $item('admin.wallet.index', ___('Wallet Ledger'), 'fi-rr-wallet'),
                    // Enterprise true-up (PRICING.md §7): draft invoices of self-hosted licences, reviewed by hand.
                    $item('admin.licence-true-ups.index', ___('Licence true-ups'), 'fi-rr-document', null, 'admin.licence-true-ups.*'),
                    $item('admin.withdrawals.index', ___('Withdrawals'), 'fi-rr-coins', null, 'admin.withdrawals.*'),
                ])],
            ],
        ],
        [
            'key' => 'resellers', 'title' => ___('Resellers & Partners'), 'rail' => 'fi-rr-briefcase',
            'groups' => [
                [___('Resellers'), array_filter([
                    $item('admin.resellers.index', ___('Resellers'), 'fi-rr-users', null, 'admin.resellers.index'),
                    $item('admin.reseller-tiers.index', ___('Reseller Tiers'), 'fi-rr-layers'),
                    $item('admin.resellers.golive.index', ___('Go-Live Requests'), 'fi-rr-rocket-lunch', null, 'admin.resellers.golive.*'),
                    $item('admin.resellers.funnel', ___('Partner Funnel'), 'fi-rr-chart-pie', null, 'admin.resellers.funnel'),
                    $item('admin.resellers.partnerOps', ___('Partner Ops'), 'fi-rr-settings-sliders', null, 'admin.resellers.partnerOps'),
                    $item('admin.resellers.report', ___('Reseller Report'), 'fi-rr-stats', null, 'admin.resellers.report'),
                    $item('admin.resellers.adjustments', ___('Subscription adjustments'), 'fi-rr-calendar-clock', null, 'admin.resellers.adjustments*'),
                    $item('admin.locked-templates.index', ___('Page Templates'), 'fi-rr-layout-fluid', null, 'admin.locked-templates.*'),
                ])],
            ],
        ],
        /* Everything sold on top of a plan — apps, themes and add-ons — lives in
           ONE tab. It used to be scattered: add-ons sat under Plans & Billing,
           themes and app reviews under a tab that also held the (unrelated)
           Fusion delivery console. */
        [
            'key' => 'store', 'title' => ___('Apps, Themes & Add-ons'), 'rail' => 'fi-rr-shop',
            'groups' => [
                [___('Apps'), array_filter([
                    $item('admin.app-catalog.index', ___('App Catalog'), 'fi-rr-apps', null, 'admin.app-catalog.*'),
                    $item('admin.app-reviews.index', ___('App Submissions'), 'fi-rr-shield-check', null, 'admin.app-reviews.*'),
                ])],
                [___('Themes'), array_filter([
                    $item('admin.theme-reviews.index', ___('Theme Submissions'), 'fi-rr-palette', null, 'admin.theme-reviews.*'),
                    $item('admin.theme-studio.generate', ___('AI theme generator'), 'fi-rr-magic-wand', null, 'admin.theme-studio.*'),
                    \App\Services\FeatureRegistry::isEnabled('ai_section_author')
                        ? $item('admin.section-author.index', ___('AI section author'), 'fi-rr-code-simple', null, 'admin.section-author.*')
                        : null,
                    $item('admin.templates.index', ___('Site Templates'), 'fi-rr-browser'),
                    $item('admin.menu-templates.index', ___('Menu Templates'), 'fi-rr-document', null, 'admin.menu-templates.*'),
                ])],
                [___('Add-ons'), array_filter([
                    $item('admin.addon-products.index', ___('Add-on Products'), 'fi-rr-box-open'),
                    $item('admin.feature-catalog.index', ___('Feature Catalog'), 'fi-rr-list-check'),
                    // WI-C1: did any of this ever convert?
                    $item('admin.spotlight.index', ___('Spotlight Report'), 'fi-rr-chart-histogram'),
                ])],
                [___('Reviews'), array_filter([
                    $item('admin.reviews.index', ___('Store Reviews'), 'fi-rr-comment', null, 'admin.reviews.*'),
                ])],
            ],
        ],
        [
            'key' => 'marketplace', 'title' => ___('Fusion & Directory'), 'rail' => 'fi-rr-globe',
            'groups' => [
                /* L5 T5-1: Fusion delivery-marketplace console */
                [___('Fusion Marketplace'), array_filter([
                    $item('admin.fusion.geo', ___('Fusion Console'), 'fi-rr-globe', null, 'admin.fusion.*'),
                    /* DIRECTORY P0-3: always listed, even while the module is
                       dark — this console is where an operator turns it on. */
                    $item('admin.directory.overview', ___('Business Directory'), 'fi-rr-marker', null, 'admin.directory.*'),
                ])],
            ],
        ],
        [
            'key' => 'trust', 'title' => ___('Trust & Growth'), 'rail' => 'fi-rr-shield-check',
            'groups' => [
                [___('Trust & Safety'), array_filter([
                    $item('admin.abuse-guard.index', ___('Abuse Guard'), 'fi-rr-shield-check', null, 'admin.abuse-guard.*'),
                ])],
                [___('Growth'), array_filter([
                    $item('admin.referrals.index', ___('Referrals'), 'fi-rr-gift'),
                    $item('admin.ab-experiments.index', ___('A/B Experiments'), 'fi-rr-chart-pie', null, 'admin.ab-experiments.*'),
                ])],
            ],
        ],
        [
            'key' => 'content', 'title' => ___('Content & CMS'), 'rail' => 'fi-rr-document',
            'groups' => [
                [___('Content'), array_filter([
                    $item('admin.blogs.index', ___('Blogs'), 'fi-rr-newspaper', null, 'admin.blogs.*'),
                    $item('admin.blog.autopilot.index', ___('Blog Autopilot'), 'fi-rr-robot', null, 'admin.blog.autopilot.*'),
                    $item('admin.blog.category.index', ___('Blog Categories'), 'fi-rr-tags', null, 'admin.blog.category.*'),
                    $item('admin.blog.comments.index', ___('Comments'), 'fi-rr-comment', $totalUnapprovedComments ?? 0, 'admin.blog.comments.*'),
                    $item('admin.pages.index', ___('Pages'), 'fi-rr-document'),
                    $item('admin.faqs.index', ___('FAQs'), 'fi-rr-list-check'),
                    $item('admin.testimonials.index', ___('Testimonials'), 'fi-rr-comment'),
                ])],
                [___('Campaigns'), array_filter([
                    $item('admin.announcements.index', ___('Announcements'), 'fi-rr-megaphone'),
                    $item('admin.advertisements.index', ___('Advertisements'), 'fi-rr-flag', null, 'admin.advertisements.*'),
                    $item('admin.allergies.index', ___('Allergies'), 'fi-rr-tags'),
                    $item('admin.merchants.campaign', ___('Email Campaign'), 'fi-rr-send', null, 'admin.merchants.campaign*'),
                ])],
            ],
        ],
        [
            'key' => 'settings', 'title' => ___('Settings & System'), 'rail' => 'fi-rr-settings-sliders',
            'groups' => [
                [___('Platform'), array_filter([
                    $item('admin.settings.index', ___('Settings'), 'fi-rr-settings-sliders', null, 'admin.settings.*'),
                    $item('admin.languages.index', ___('Languages'), 'fi-rr-language'),
                    $item('admin.mailtemplates.index', ___('Email Templates'), 'fi-rr-inbox'),
                    $item('admin.plugins.index', ___('Plugins'), 'fi-rr-plug'),
                    $item('admin.whatsapp.settings', ___('WhatsApp'), 'fi-rr-comment', null, 'admin.whatsapp.*'),
                ])],
            ],
        ],
    ];

    // active tab = first tab containing an active item; fall back to dashboard.
    $activeTab = 'dashboard';
    foreach ($tabs as $t) {
        foreach ($t['groups'] as [$g, $items]) {
            foreach ($items as $it) { if ($it['active']) { $activeTab = $t['key']; break 3; } }
        }
    }
@endphp
@php
    $brandName = trim(\Illuminate\Support\Str::afterLast((string) ($settings->site_title ?? ''), '|'));
    $brandName = $brandName !== '' ? $brandName : ___('Admin');
    $adminLogo = $settings->site_admin_logo ?? null;
    $adminLogoDark = ($settings->site_logo_footer ?? null) ?: $adminLogo;
    $me = auth()->user();
    $meName = $me->name ?? $me->username ?? ___('Admin');
@endphp
<aside class="ui-side" id="appMenubar" aria-label="{{ ___('Sidebar navigation') }}">
    <div class="ui-side__rail">
        <a class="ui-side__logo brand-tile" href="{{ route('admin.dashboard') }}" aria-label="{{ $brandName }}">
            @if ($adminLogo)
                <img class="ui-side__logo-light" src="{{ asset('storage/logo/'.$adminLogo) }}" alt="">
                <img class="ui-side__logo-dark" src="{{ asset('storage/logo/'.$adminLogoDark) }}" alt="">
            @else
                <span class="ui-side__initials">{{ mb_strtoupper(mb_substr($brandName, 0, 2)) }}</span>
            @endif
        </a>
        <div class="ui-side__sections" role="tablist" aria-orientation="vertical">
            @foreach ($tabs as $t)
                <button type="button" class="ui-side__section" role="tab" id="ui-side-tab-{{ $t['key'] }}"
                        aria-controls="ui-side-panel-{{ $t['key'] }}" aria-selected="{{ $t['key'] === $activeTab ? 'true' : 'false' }}"
                        data-ui-side-tab="{{ $t['key'] }}" title="{{ $t['title'] }}">
                    <x-ui.shell.nav-icon :glyph="$t['rail']" size="22" />
                    <span class="ui-side__rail-label">{{ $t['title'] }}</span>
                </button>
            @endforeach
        </div>
    </div>

    <div class="ui-side__panel">
        <div class="ui-side__head">
            <a class="ui-side__name" href="{{ route('admin.dashboard') }}">{{ \Illuminate\Support\Str::limit($brandName, 18) }}</a>
            <span class="ui-side__tag">{{ ___('Superadmin') }}</span>
            <button type="button" class="ui-side__close" data-ui-drawer-close aria-label="{{ ___('Close menu') }}" title="{{ ___('Close menu') }}">
                <x-ui.icon name="x" size="18" />
            </button>
        </div>

        <div class="ui-side__scroll">
            @foreach ($tabs as $t)
                <nav class="ui-side__nav" role="tabpanel" id="ui-side-panel-{{ $t['key'] }}" aria-labelledby="ui-side-tab-{{ $t['key'] }}"
                     data-ui-side-panel="{{ $t['key'] }}" @if ($t['key'] !== $activeTab) hidden @endif>
                    @foreach ($t['groups'] as [$groupLabel, $items])
                        @continue(! count($items))
                        @if ($groupLabel !== $t['title'])
                            <p class="ui-side__group">{{ $groupLabel }}</p>
                        @endif
                        <ul class="ui-side__list">
                            @foreach ($items as $it)
                                <li>
                                    <a class="ui-side__link" href="{{ $it['url'] }}" @if ($it['active']) aria-current="page" @endif>
                                        <x-ui.shell.nav-icon :glyph="$it['icon']" size="18" />
                                        <span class="ui-side__label">{{ $it['label'] }}</span>
                                        @if ($it['badge'])
                                            <span class="ui-side__badge">{{ is_numeric($it['badge']) && $it['badge'] > 99 ? '99+' : $it['badge'] }}</span>
                                        @endif
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endforeach
                </nav>
            @endforeach
        </div>

        <div class="ui-side__foot">
            <a href="{{ url('/') }}" class="ui-side__link" target="_blank" rel="noopener">
                <x-ui.shell.nav-icon glyph="fi-rr-globe" size="18" />
                <span class="ui-side__label">{{ ___('View site') }}</span>
            </a>
            <a href="{{ route('admin.settings.index') }}" class="ui-side__account">
                <span class="ui-side__avatar">{{ mb_strtoupper(mb_substr($meName, 0, 1)) }}</span>
                <span class="ui-side__who">
                    <strong>{{ $meName }}</strong>
                    <span>{{ ___('Super Admin') }}</span>
                </span>
            </a>
        </div>
    </div>
</aside>
<div class="ui-side-scrim" data-ui-drawer-close hidden></div>
