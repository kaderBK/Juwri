{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Coupons'))
@section('page_title', ___('Discount coupons'))
@section('page_sub', ___('Codes diners enter at checkout. Track usage and expiry.'))

@section('page_actions')
    <x-ui.button :href="route('stores.coupons.create')" icon="plus" data-controller="bridge--button" :data-bridge-title="___('New coupon')" data-bridge-ios-image="plus" data-bridge-android-image="add">{{ ___('New coupon') }}</x-ui.button>
@endsection

@section('content')
@php
    $items = collect($coupons->items());
    $totalUsed = $items->sum('used');
    // P6-353: unlimited rows (limit <= 0) must not pollute the totals row — a
    // /0 sum would read "0" in the Limit tile even though the page has codes
    // that go on forever. Sum only the bounded coupons and leave the rest out.
    $totalLimit = $items->where('limit', '>', 0)->sum('limit');
    $expiringSoon = \Juwri\Core\Support\Views\OwnerOpsViewHelpers::couponsExpiringSoon($items);
@endphp

<div class="ui-scope ui-coupons">
    @if (isset($errors) && $errors->has('plan'))<x-ui.banner tone="danger">{{ $errors->first('plan') }}</x-ui.banner>@endif
    <x-plan-gate-notice feature="coupons" />
    <section class="ui-coupons__stats" aria-label="{{ ___('Discount coupons') }}">
        <x-ui.stat :value="number_format($coupons->total())" :label="___('Total')" icon="gift" />
        <x-ui.stat :value="number_format($totalUsed)" :label="___('Used')" icon="check" tone="success" />
        <x-ui.stat :value="number_format($totalLimit)" :label="___('Limit')" icon="list" />
        <x-ui.stat :value="number_format($expiringSoon)" :label="___('Expiring 7d')" icon="clock" tone="warning" />
        {{-- C5: diners refused an exhausted coupon today. 0 is healthy; a non-zero
             count means a code hit its limit and the merchant may want to raise it. --}}
        <x-ui.stat :value="number_format($deniedToday ?? 0)" :label="___('Declined today')" icon="alert" tone="danger" />
    </section>

    {{-- WI-A2 (upsell-visibility): the adjacent-action teaser. Someone writing
         discount codes by hand is one step from wanting them sent automatically,
         which is the moment the paid feature is worth mentioning. This page is
         ungated, so an unentitled owner actually reaches it — unlike the
         Automations page itself, which redirects them to the upsell before they
         can have an empty state at all. --}}
    <x-spotlight.teaser key="marketing_automation" compact />

    @if ($items->isEmpty())
        <div class="ui-card">
            <x-ui.empty :title="___('No coupons yet')" :body="___('Create your first discount code to share with diners.')" icon="gift">
                <x-ui.button :href="route('stores.coupons.create')" icon="plus">{{ ___('Create coupon') }}</x-ui.button>
            </x-ui.empty>
        </div>
    @else
        <section class="ui-card ui-card--flush">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>{{ ___('Code') }}</th>
                        <th>{{ ___('Type') }}</th>
                        <th class="ui-table__num">{{ ___('Value') }}</th>
                        <th>{{ ___('Used / Limit') }}</th>
                        <th>{{ ___('Expires') }}</th>
                        <th><span class="ui-visually-hidden">{{ ___('Actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($items as $coupon)
                        @php
                            $expTone = $coupon->expiry_at ? ($coupon->isExpiry() ? 'danger' : 'success') : 'neutral';
                            $usedPct = $coupon->limit > 0 ? min(100, round(($coupon->used / $coupon->limit) * 100)) : 0;
                            $usedUp = $coupon->limit > 0 && $coupon->used >= $coupon->limit;
                            // P6-353: a stored limit <= 0 means "unlimited" — render
                            // "used / Unlimited" with no meter fill and no "Used up"
                            // pill. Legacy rows created empty before this fix default to
                            // 9999; surface that so owners can spot them as "essentially
                            // unlimited" but distinguishable from a freshly saved 0.
                            $isUnlimited = (int) $coupon->limit <= 0;
                            $isLegacyDefault = (int) $coupon->limit === 9999;
                        @endphp
                        <tr>
                            <td data-primary><code class="ui-coupons__code">{{ $coupon->code }}</code></td>
                            <td data-label="{{ ___('Type') }}"><x-ui.tag :tone="$coupon->coupon_type === 'percentage' ? 'accent' : 'neutral'">{{ $coupon->coupon_type === 'percentage' ? ___('Percentage') : ___('Fixed') }}</x-ui.tag></td>
                            <td class="ui-table__num" data-label="{{ ___('Value') }}"><strong class="ui-ltr">{{ $coupon->coupon_type === 'percentage' ? $coupon->percentage.'%' : price_format($coupon->percentage) }}</strong></td>
                            <td data-label="{{ ___('Used / Limit') }}">
                                <span class="ui-coupons__usage">
                                    <strong class="ui-ltr">
                                        @if ($isUnlimited)
                                            {{ $coupon->used }} / {{ ___('Unlimited') }}
                                        @else
                                            {{ $coupon->used }} / {{ $coupon->limit }}@if ($isLegacyDefault) ({{ ___('default') }})@endif
                                        @endif
                                    </strong>
                                    @if ($usedUp)<x-ui.pill tone="danger">{{ ___('Used up') }}</x-ui.pill>@endif
                                    <span @class(['ui-ov-meter', 'is-exhausted' => $usedUp]) aria-hidden="true"><span style="inline-size: {{ $usedPct }}%"></span></span>
                                    @if ($coupon->per_customer_limit)
                                        <small>{{ ___('Max :n per diner', ['n' => $coupon->per_customer_limit]) }}</small>
                                    @endif
                                </span>
                            </td>
                            <td data-label="{{ ___('Expires') }}">
                                @if ($coupon->expiry_at)
                                    <x-ui.pill :tone="$expTone">{{ $coupon->expiry_at->copy()->setTimezone($storeTz)->format('d M Y') }}</x-ui.pill>
                                @else
                                    <x-ui.pill>{{ ___('Never') }}</x-ui.pill>
                                @endif
                            </td>
                            <td class="ui-catalog__row-actions">
                                <a class="ui-icon-btn" href="{{ route('stores.coupons.edit', $coupon) }}" aria-label="{{ ___('Edit') }}" title="{{ ___('Edit') }}"><x-ui.icon name="pencil" /></a>
                                <form action="{{ route('stores.coupons.destroy', $coupon) }}" method="post" data-confirm="{{ ___('Delete this coupon?') }}" data-confirm-danger>
                                    @csrf @method('DELETE')
                                    <button type="submit" class="ui-icon-btn ui-menu-item__delete" aria-label="{{ ___('Delete') }}" title="{{ ___('Delete') }}"><x-ui.icon name="trash" /></button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @if ($coupons->hasPages())
                <div class="ui-catalog__pager">{{ $coupons->links() }}</div>
            @endif
        </section>
    @endif
</div>
@endsection
