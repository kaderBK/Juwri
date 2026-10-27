{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Coupons & Wallets'))
@section('content')
    {{-- Coupons & Wallets P7-6b: superadmin oversight — one page for every wallet
         tier's balance (straight off the unified ledger) and every coupon tier's
         redemption stats (the one Coupon table). --}}
    @php
        $sign = cur_isolate(config('settings.currency_sign', '$'));
        $walletLabels = [
            'platform'              => ___('Platform'),
            'reseller'              => ___('Reseller'),
            'merchant'              => ___('Merchant'),
            'customer_store_credit' => ___('Customer store credit'),
        ];
        $scopeLabels = [
            'platform' => ___('Platform'),
            'reseller' => ___('Reseller'),
            'owner'    => ___('Owner'),
        ];
    @endphp

<div class="ui-stack">
    <h2 class="ui-section-title">{{ ___('Wallet balances') }}</h2>
    <section class="ui-kpis" aria-label="{{ ___('Wallet balances') }}">
        @foreach ($wallets as $kind => $w)
            <div class="ui-kpi">
                <span class="ui-kpi__label">{{ $walletLabels[$kind] ?? $kind }}<span class="ui-kpi__icon"><x-ui.icon name="wallet" size="18" /></span></span>
                <span class="ui-kpi__value">{{ money_ltr(currency_pos_effective() === 1 ? $sign.number_format($w['balance'], 2) : number_format($w['balance'], 2).$sign) }}</span>
                <span class="ui-kpi__sub">{{ ___n('account', (int) $w['accounts']) }}</span>
            </div>
        @endforeach
    </section>

    {{-- C5 operator readout: today's refused claims across every settlement rail,
         and the pre-fix over-redemption damage counter. `overRedeemed` can only
         ever fall after this fix — a value that RISES after deploy means the fix
         is not holding and is the signal to roll back. --}}
    {{-- M3: per-customer-gate health. "Redemptions tracked" must climb once any
         per-customer-limited coupon is used; "denials today" tells the owner the
         gate is actually refusing repeat redemptions. --}}
    <section class="ui-kpis" aria-label="{{ ___('Coupons') }}">
        @foreach ([
            [___('Coupon claims declined today'), number_format($claimDeniedToday ?? 0), ___('Exhausted codes refused at settlement'), 'ban'],
            [___('Over-redeemed coupons'), number_format($overRedeemed ?? 0), ___('Historical used > limit; must not rise'), 'alert'],
            [___('Per-customer denials today'), number_format($perCustomerDeniedToday ?? 0), ___('Repeat redemptions refused per diner'), 'tag'],
            [___('Redemptions tracked'), number_format($redemptionsTracked ?? 0), ___('Rows in the redemption ledger'), 'tag'],
        ] as [$__label, $__value, $__sub, $__icon])
            <div class="ui-kpi">
                <span class="ui-kpi__label">{{ $__label }}<span class="ui-kpi__icon"><x-ui.icon :name="$__icon" size="18" /></span></span>
                <span class="ui-kpi__value">{{ $__value }}</span>
                <span class="ui-kpi__sub">{{ $__sub }}</span>
            </div>
        @endforeach
    </section>

    <x-ui.card :title="___('Coupon redemptions')" variant="flush">
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                <tr>
                    <th>{{ ___('Issuer') }}</th>
                    <th>{{ ___('Coupons') }}</th>
                    <th>{{ ___('Total redemptions') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($coupons as $c)
                    <tr>
                        <td data-primary data-label="{{ ___('Issuer') }}">{{ $scopeLabels[$c['issuer_scope']] ?? ($c['issuer_scope'] ?: ___('Unscoped')) }}</td>
                        <td data-label="{{ ___('Coupons') }}">{{ number_format($c['count']) }}</td>
                        <td data-label="{{ ___('Total redemptions') }}">{{ number_format($c['total_used']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="ui-center ui-text-2">{{ ___('No coupons yet') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>
</div>
@endsection
