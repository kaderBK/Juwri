{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    $mkSocials = array_filter([
        'Facebook' => data_get($settings, 'facebook_link'),
        'X' => data_get($settings, 'twitter_link'),
        'Instagram' => data_get($settings, 'instagram_link'),
        'LinkedIn' => data_get($settings, 'linkedin_link'),
        'YouTube' => data_get($settings, 'youtube_link'),
    ]);
    $mkGuideNames = [
        'zatca-einvoicing' => 'ZATCA e-invoicing',
        'jofotara-einvoicing' => 'JoFotara e-invoicing',
        'eta-einvoicing' => 'ETA e-receipt (Egypt)',
        'e-adisyon' => 'e-Adisyon (Türkiye)',
        'fbr-pos' => 'FBR POS (Pakistan)',
        'verifactu' => 'Verifactu (España)',
        'reserve-with-google' => 'Reserve with Google',
        'google-wallet-loyalty' => 'Google Wallet loyalty',
    ];
    $mkCols = [];
    $mkCols[___('Product')] = array_filter([
        Route::has('features') ? [route('features'), ___('Features')] : null,
        Route::has('themes') ? [route('themes'), ___('Themes')] : null,
        Route::has('whatsapp.landing') ? [route('whatsapp.landing'), ___('WhatsApp Orders')] : null,
        Route::has('no-commission') ? [route('no-commission'), ___('Online Ordering with Zero Commission')] : null,
        Route::has('studio') ? [route('studio'), ___('Menu Studio')] : null,
        Route::has('pricing') ? [route('pricing'), ___('Pricing')] : null,
    ]);
    if (Route::has('tools.commission-calculator')) {
        $mkCols[___('Free tools')] = array_filter([
            [route('tools.commission-calculator'), ___('Delivery Commission Calculator')],
            Route::has('tools.qr-generator') ? [route('tools.qr-generator'), ___('Free QR Menu Generator')] : null,
            Route::has('tools.menu-scanner') ? [route('tools.menu-scanner'), ___('AI Menu Scanner')] : null,
            Route::has('marketplace.index') ? [route('marketplace.index'), ___('Theme Store')] : null,
            Route::has('apps.store.index') ? [route('apps.store.index'), ___('App Store')] : null,
        ]);
    }
    $mkCareers = active_cms_page('work-for-juwri');
    $mkCols[___('Company')] = array_filter([
        Route::has('partners.index') ? [route('partners.index'), ___('Become a reseller')] : null,
        Route::has('tools.reseller-calculator') ? [route('tools.reseller-calculator'), ___('Reseller Margin Calculator')] : null,
        Route::has('contact') ? [route('contact'), ___('Contact Us')] : null,
        Route::has('testimonials') ? [route('testimonials'), ___('Testimonials')] : null,
        $mkCareers ? [route('page', $mkCareers->slug), ___('Careers')] : null,
        Route::has('blog.index') ? [route('blog.index'), ___('Blog')] : null,
        Route::has('faqs') ? [route('faqs'), ___('FAQ')] : null,
        Route::has('developers') ? [route('developers'), ___('API & Webhooks')] : null,
        Route::has('developers.apps') ? [route('developers.apps'), ___('Build an App')] : null,
        Route::has('developers.themes') ? [route('developers.themes'), ___('Build a Theme')] : null,
        Route::has('mcp.landing') ? [url('/mcp'), ___('MCP for AI agents')] : null,
        \App\Support\StatusPage::url() ? [\App\Support\StatusPage::url(), __('site.footer.status')] : null,
    ]);
    if (Route::has('compare.show')) {
        $mkCols[___('Compare')] = array_filter(\Juwri\Modules\Content\Services\CompareDefs::footerLinks(\Juwri\Modules\Content\Services\CompareDefs::slugs()));
    }
    if (Route::has('guides.show')) {
        $mkCols[___('Guides')] = \Juwri\Core\Support\Views\PublicSiteViewHelpers::guideFooterLinks(\Juwri\Modules\Content\Services\GuideDefs::slugs(), $mkGuideNames);
    }
    if (Route::has('country.landing')) {
        $mkRegions = [];
        foreach (\App\Services\CountryDefs::menu(get_lang()) as $mkCc => $mkName) {
            $mkRegions[] = [route('country.landing', $mkCc), $mkName];
        }
        $mkCols[___('Regions')] = $mkRegions;
    }
    // P1-23: the publisher's legal pages + company line — only where this instance publishes them, never on a
    // reseller's white-label host (its customers contract with the reseller, Terms §14.1).
    $mkLegal = \Juwri\Core\Legal\LegalDocuments::publishedHere();
    if ($mkLegal) {
        $mkCols[__('site.footer.legal')] = \Juwri\Core\Support\Views\PublicSiteViewHelpers::legalFooterLinks(array_keys(\Juwri\Core\Legal\LegalDocuments::all()));
        if (Route::has('legal.cookies')) {
            $mkCols[__('site.footer.legal')][] = [route('legal.cookies'), __('cookies.title')];
        }
    }
    $mkCols = array_filter($mkCols);
    $mkPrivacy = active_cms_page('the-privacy-policy');
    $mkTerms = active_cms_page('terms-of-service');
    $mkFootLogo = ($mkReseller && brand_logo())
        ? brand_logo()
        : (! empty(data_get($settings, 'site_logo_footer')) ? asset('storage/logo/'.data_get($settings, 'site_logo_footer')) : (! empty(data_get($settings, 'site_logo')) ? asset('storage/logo/'.data_get($settings, 'site_logo')) : null));
@endphp
<footer class="mk-foot">
    <div class="mk-wrap">
        <div class="mk-foot__cta">
            <h2 class="mk-foot__title">{{ ___('Modernize your menu. Delight every diner.') }}</h2>
            <div class="mk-foot__cta-actions">
                <a href="{{ route('register') }}" class="ui-btn">{{ ___('Get started') }}</a>
                @unless (request()->routeIs('home'))
                    <a href="{{ route('home') }}" class="ui-btn ui-btn--secondary">{{ ___('Learn more') }}</a>
                @endunless
            </div>
        </div>

        <div class="mk-foot__grid">
            <div class="mk-foot__about">
                <a href="{{ route('home') }}" class="mk-foot__brand" aria-label="{{ brand_title() }}">
                    @if ($mkFootLogo)
                        <span class="brand-tile brand-tile--sm brand-tile--dark"><img src="{{ $mkFootLogo }}" alt="{{ brand_title() }}" width="140" height="140"></span>
                    @else
                        {{ brand_title() }}
                    @endif
                </a>
                <p>{{ ___('Built for restaurants, our platform helps you launch QR menus and online ordering in minutes.') }}</p>
                <ul class="mk-foot__contact">
                    @if (! empty(data_get($settings, 'invoice_admin_phone')))
                        <li><a href="tel:{{ preg_replace('/[^0-9+]/', '', data_get($settings, 'invoice_admin_phone')) }}" class="ui-ltr">{{ data_get($settings, 'invoice_admin_phone') }}</a></li>
                    @endif
                    @if (! empty(data_get($settings, 'invoice_admin_email')))
                        <li><a href="mailto:{{ data_get($settings, 'invoice_admin_email') }}">{{ data_get($settings, 'invoice_admin_email') }}</a></li>
                    @endif
                    @if (! empty(data_get($settings, 'invoice_admin_address')))
                        <li>{{ data_get($settings, 'invoice_admin_address') }}</li>
                    @endif
                </ul>
                @if (! empty($mkSocials))
                    <ul class="mk-foot__social">
                        @foreach ($mkSocials as $mkNet => $mkUrl)
                            <li><a href="{{ $mkUrl }}" target="_blank" rel="noopener">{{ $mkNet }}</a></li>
                        @endforeach
                    </ul>
                @endif
            </div>
            @foreach ($mkCols as $mkHead => $mkItems)
                <div class="mk-foot__col">
                    <h2 class="mk-foot__head">{{ $mkHead }}</h2>
                    <ul>
                        @foreach ($mkItems as [$mkHref, $mkText])
                            <li><a href="{{ $mkHref }}">{{ $mkText }}</a></li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>

        <div class="mk-foot__bar">
            @if ($mkLegal)
                <div class="mk-foot__company" data-legal-company>
                    <p>{{ __('site.footer.company') }}</p>
                    <p>{{ __('site.footer.copyright') }}</p>
                </div>
            @else
                <p>© {{ date('Y') }} {{ brand_title() }} | {{ ___('All Rights Reserved') }}</p>
            @endif
            <ul>
                @if (! $mkLegal && $mkPrivacy)<li><a href="{{ route('page', $mkPrivacy->slug) }}">{{ ___('Privacy Policy') }}</a></li>@endif
                @if (! $mkLegal && $mkTerms)<li><a href="{{ route('page', $mkTerms->slug) }}">{{ ___('Terms & Conditions') }}</a></li>@endif
                {{-- P1-23: the cookie list (every install; in the Legal column when the legal pages are published). --}}
                @if (! $mkLegal && Route::has('legal.cookies'))<li><a href="{{ route('legal.cookies') }}">{{ __('cookies.title') }}</a></li>@endif
                {{-- P1-23: re-open the cookie banner (partials/cookie-consent) to change the choice. --}}
                <li><button type="button" class="mk-foot__linkbtn" data-cookie-settings>{{ __('site.cookie.settings') }}</button></li>
            </ul>
        </div>
    </div>
</footer>
