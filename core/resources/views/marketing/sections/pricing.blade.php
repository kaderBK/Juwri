{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Pricing block: billing-cycle switch, plan cards and the compare table.
     Needs $plans; $total_annual / $total_lifetime decide which cycles exist;
     $gateHome is true on the home page. --}}
@php
    $prAnnual = ($total_annual ?? collect($plans)->sum('annual_price')) > 0;
    $prLifetime = ($total_lifetime ?? collect($plans)->sum('lifetime_price')) > 0;
@endphp
<div class="mk-pricing">
    @includeIf('billing::marketing.sections.promotion-banner')
    @if ($prAnnual || $prLifetime)
        <p class="mk-center">
            <span class="mk-cycle" role="group" aria-label="{{ ___('Billing period') }}">
                <button type="button" class="mk-cycle__btn is-active" data-cycle="monthly" aria-pressed="true">{{ ___('Monthly') }}</button>
                @if ($prAnnual)<button type="button" class="mk-cycle__btn" data-cycle="yearly" aria-pressed="false">{{ ___('Yearly') }}</button>@endif
                @if ($prLifetime)<button type="button" class="mk-cycle__btn" data-cycle="lifetime" aria-pressed="false">{{ ___('Lifetime') }}</button>@endif
            </span>
        </p>
    @endif
    @include('marketing.sections.pricing-cards', ['plans' => $plans, 'gateHome' => $gateHome ?? false])
</div>
