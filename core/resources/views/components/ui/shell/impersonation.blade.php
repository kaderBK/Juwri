{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-ui.shell.impersonation /> — sticky strip while a reseller is inside a customer
  account (support session), naming what is switched off and offering the way out.
--}}
@if (is_array(session('impersonating')) && Route::has('reseller.impersonate.exit')){{-- P1-13 13f: the Reseller module's (ee) --}}
    @php $__imp = session('impersonating'); @endphp
    <div class="ui-imp" role="alert">
        <strong>{{ ___('Support session — viewing :name as :brand.', ['name' => $__imp['customer_name'] ?? '', 'brand' => $__imp['reseller_brand'] ?? '']) }}</strong>
        <span>{{ ___('Ownership transfer, API tokens and webhooks are disabled in a support session.') }}</span>
        <form method="POST" action="{{ route('reseller.impersonate.exit') }}">
            @csrf
            <button type="submit" class="ui-btn ui-btn--dark ui-btn--sm">{{ ___('Exit impersonation') }}</button>
        </form>
    </div>
@endif
