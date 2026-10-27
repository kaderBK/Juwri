{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Site block: pricing_grid. Live platform plans (status=1, PlanCatalog — none in Community) with the
     summed cycle totals — rendered pricing must match billing truth. The kit
     pricing block does the cycle switch, cards and the compare table (which
     obeys the "compare on home" setting). Optional heading override. --}}
@php
    try {
        $plans = app(\Juwri\Core\Contracts\Billing\PlanCatalog::class)->plans(true);
    } catch (\Throwable $e) {
        $plans = collect();
    }
    $total_annual = $plans->sum('annual_price');
    $total_lifetime = $plans->sum('lifetime_price');
    $sbHeading = (string) ($block['content']['heading'] ?? '');
@endphp
@if ($plans->count())
    <section class="mk-sec mk-sec--tint sb-block" data-site-block="pricing_grid">
        <div class="mk-wrap">
            <div class="mk-sec__head">
                <h2 class="mk-h2">{{ $sbHeading !== '' ? $sbHeading : ___('Flexible pricing plans designed for your needs.') }}</h2>
            </div>
            @include('marketing.sections.pricing', ['plans' => $plans, 'total_annual' => $total_annual, 'total_lifetime' => $total_lifetime, 'gateHome' => true])
        </div>
    </section>
@endif
