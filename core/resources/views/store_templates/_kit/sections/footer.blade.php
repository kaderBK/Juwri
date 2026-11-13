{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    $__s = $vm['store'];
    $__kiosk = ! empty($vm['context']['kiosk']['on']);
    $__other = \Juwri\Core\Support\ViewHelpers::otherLocale($__s['locales'] ?? [], $__s['locale']);
    $__note = $vm['theme']['texts']['footer_note'] ?? null;
@endphp
<footer class="c-footer" data-section="footer">
    @include('store_templates._kit.components.identity')
    <ul class="c-footer__links">
        @unless ($__kiosk)
        @foreach ($__s['legal_links'] ?? [] as $__l)<li><a class="c-footer__link" href="{{ $__l['url'] }}">{{ $__l['label'] }}</a></li>@endforeach
        @if (! empty($__s['contact']['phone']))<li><a class="c-footer__link" href="tel:{{ preg_replace('/[^+\d]/', '', $__s['contact']['phone']) }}">{{ $kit->s('Contact store') }}</a></li>@endif
        <li data-cookie-link><button type="button" class="c-footer__link c-footer__cookie" data-cookie-settings>{{ __('site.cookie.settings') }}</button></li>
        @endunless
    </ul>
    @if ($__other && ! $__kiosk)<a class="c-footer__link c-footer__lang" href="{{ $__other['url'] }}" lang="{{ $__other['code'] }}" hreflang="{{ $__other['code'] }}">{{ $kit->icon('globe') }}<span>{{ $__other['name'] }}</span></a>@endif
    @if ($__note)<p class="c-footer__note">{{ $kit->flow($__note) }}</p>@endif
    @if (! empty($__s['powered_by']))<p class="c-footer__powered">{{ $kit->s('Powered by Juwri') }}</p>@endif
</footer>
