{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    Marketing site frame (WI-16f) on the owner-ui kit.

    Section contract (unchanged for every marketing page):
      title, meta_description, robots, canonical, og_image — SEO head
      stacks: structured_data (JSON-LD), styles, scripts; section content.
    Page variables read here: $blogHreflang / $pageHreflang / $suppressHreflang
    (alternate links). Every marketing page is on the kit (WI-16f/g); the old
    landing bridge left with WI-16h.
--}}
@php
    $settings = $settings ?? null;
    $mkRtl = is_rtl();
    $mkSite = \Juwri\Modules\Content\Services\SitePages\SiteContext::current();
    $mkReseller = function_exists('active_brand_reseller') ? active_brand_reseller() : null;
@endphp
<!DOCTYPE html>
<html lang="{{ get_lang() }}" dir="{{ $mkRtl ? 'rtl' : 'ltr' }}"@if($mkSite && $mkSite->key) data-site="{{ $mkSite->key }}"@endif>
<head>
    @include('marketing.partials.head')
</head>
<body class="mk-body">
    <a class="mk-skip" href="#main">{{ ___('Skip to content') }}</a>

    @include('marketing.partials.nav')

    <main id="main" class="mk-main">
        @yield('content')
    </main>

    @include('marketing.partials.footer')

    {{-- Optional support chat link: only when the platform sets a number
         (settings.support_whatsapp, digits only). Never on a reseller host. --}}
    @php $mkSupportWa = preg_replace('/\D+/', '', (string) data_get($settings, 'support_whatsapp', '')); @endphp
    @if ($mkSupportWa !== '' && ! $mkReseller)
        <a class="mk-support" href="https://wa.me/{{ $mkSupportWa }}" target="_blank" rel="noopener" aria-label="{{ ___('Chat with us on WhatsApp') }}">
            <x-ui.icon name="chat" size="24" />
        </a>
    @endif

    @include('marketing.partials.locale-suggest')

    {{-- Quick add-ons body block (cookie consent, chat widget). P1-23: where the legal pages are published the
         consent banner always asks on the marketing / legal pages. --}}
    @include('partials.quick-addons', ['cookieConsentForce' => \Juwri\Core\Legal\LegalDocuments::enabled()])

    <script src="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/owner-ui/layout.js') }}" defer></script>
    <script src="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/owner-ui/site.js') }}" defer></script>
    @stack('scripts')
</body>
</html>
