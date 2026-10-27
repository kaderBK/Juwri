{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Abuse Guard P4 (4.2/4.3): honeypot + Turnstile seam. Include right after @csrf.
     Honeypot: a hidden field bots tend to fill; humans leave it blank. Turnstile
     renders only when a site key is configured (empty = disabled seam). --}}
<div aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;height:0;width:0;overflow:hidden">
    <label>Company URL <input type="text" name="{{ \App\Services\SignupGuard::HONEYPOT }}" tabindex="-1" autocomplete="off" value=""></label>
</div>
@php $turnstileSiteKey = \App\Services\SignupGuard::turnstileSiteKey(); @endphp
@if($turnstileSiteKey !== '')
    <div class="cf-turnstile" style="margin-block: 1rem" data-sitekey="{{ $turnstileSiteKey }}"></div>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
@endif
