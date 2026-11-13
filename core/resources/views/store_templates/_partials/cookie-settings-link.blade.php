{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  P1-23: "Cookie settings" on EVERY storefront theme, so a visitor can change the consent choice on any store.

  The 17 modern templates carry it in the shared info footer (_partials/info-links). Every other theme — the legacy
  templates on the shared layout and the themes with a layout of their own — includes this partial at the end of
  <body>: a small plain link row (no surface of its own, inherits the theme's colours) plus the consent banner it
  re-opens (partials/cookie-consent renders once per request, so a layout that already has it is unaffected).
  $cookieLinkSkipModern = true (shared layout): render nothing for the modern templates (their footer has the link).
  Not shown inside the owner app, like the banner.
--}}
@php
    $cslPost = $store ?? null;
    $cslSkip = ! empty($cookieLinkSkipModern) && is_object($cslPost) && ! empty($cslPost->id)
        && \Juwri\Modules\Storefront\Services\Theme\KeptTemplates::isModern(app(\App\Services\StorefrontThemeService::class)->resolveSaved($cslPost));
@endphp
@unless ($cslSkip || \Juwri\Core\Support\OwnerApp::inApp())
<div class="mbck" data-cookie-link>
    <button type="button" class="mbck__btn" data-cookie-settings>{{ __('site.cookie.settings') }}</button>
</div>
<style @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
.mbck{padding:20px 16px calc(96px + env(safe-area-inset-bottom,0px));text-align:center}
.mbck__btn{background:none;border:0;padding:6px 10px;color:inherit;opacity:.7;font:inherit;font-size:12.5px;text-decoration:underline;cursor:pointer}
.mbck__btn:hover,.mbck__btn:focus-visible{opacity:1}
</style>
@include('partials.cookie-consent')
@endunless
