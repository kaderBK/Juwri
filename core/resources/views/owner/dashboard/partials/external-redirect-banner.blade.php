{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- rev/external-redirect B2: one warning banner per owned store whose public
     menu is currently redirecting to an external URL, each with a one-click
     "Disable" (POST, ownership-checked). CSP-safe — no inline script/style. --}}
@php
    $__erStores = \Juwri\Core\Support\ViewHelpers::externalRedirectStores($stores ?? []);
@endphp

@foreach($__erStores as $__erStore)
    @php $__erUrl = (string) \App\Models\StoreOption::getStoreOption($__erStore->id, 'external_redirect_url'); @endphp
    <div class="ui-cluster ui-cluster--between ui-note" data-tone="warning" role="alert">
        <div>
            <x-ui.shell.nav-icon glyph="arrow-up-right-from-square" />
            <strong>{{ $__erStore->title }}</strong>
            — {{ ___('your public menu redirects visitors to') }}
            <a href="{{ $__erUrl }}" target="_blank" rel="noopener nofollow" class="ui-break">{{ $__erUrl }}</a>
        </div>
        <form method="POST" action="{{ route('stores.externalRedirect.disable', $__erStore->id) }}">
            @csrf
            <button type="submit" class="ui-btn ui-btn--sm ui-btn--secondary">{{ ___('Disable redirect') }}</button>
        </form>
    </div>
@endforeach
