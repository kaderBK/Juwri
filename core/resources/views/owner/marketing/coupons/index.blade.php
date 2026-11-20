{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Coupons'))
@section('page_title', ___('Discount coupons'))
@section('page_sub', ___('Codes diners enter at checkout. Track usage and expiry.'))

@section('page_actions')
    <x-ui.button variant="secondary" icon="layers" :href="route('stores.coupons.bulk.create')">{{ ___('Bulk Create') }}</x-ui.button>
    <x-ui.button variant="secondary" icon="list" :href="route('stores.coupons.batches.index')">{{ ___('Batches') }}</x-ui.button>
    <x-ui.button variant="secondary" icon="download" :href="route('stores.coupons.export', $filters ?? [])">{{ ___('Export CSV') }}</x-ui.button>
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

    @if (session('success'))<x-ui.banner tone="success">{{ session('success') }}</x-ui.banner>@endif
    @if (session('error'))<x-ui.banner tone="danger">{{ session('error') }}</x-ui.banner>@endif

    <section class="ui-card">
        <form method="GET" action="{{ route('stores.coupons.index') }}" class="ui-coupons__filters">
            <input type="text" name="search" class="ui-input ui-input--sm" value="{{ $filters['search'] ?? '' }}" placeholder="{{ ___('Search code') }}" aria-label="{{ ___('Search code') }}">
            <select name="status" class="ui-input ui-input--sm" aria-label="{{ ___('Status') }}">
                <option value="">{{ ___('All statuses') }}</option>
                <option value="active" @selected(($filters['status'] ?? '') === 'active')>{{ ___('Active') }}</option>
                <option value="never_used" @selected(($filters['status'] ?? '') === 'never_used')>{{ ___('Never used') }}</option>
                <option value="partly_used" @selected(($filters['status'] ?? '') === 'partly_used')>{{ ___('Partly used') }}</option>
                <option value="used_up" @selected(($filters['status'] ?? '') === 'used_up')>{{ ___('Used up') }}</option>
                <option value="expired" @selected(($filters['status'] ?? '') === 'expired')>{{ ___('Expired') }}</option>
            </select>
            <select name="batch_id" class="ui-input ui-input--sm" aria-label="{{ ___('Batch') }}">
                <option value="">{{ ___('All batches') }}</option>
                @foreach (($batches ?? []) as $batch)
                    <option value="{{ $batch->id }}" @selected((string) ($filters['batch_id'] ?? '') === (string) $batch->id)>{{ $batch->name ?: ($batch->prefix ?: '#'.$batch->id) }} ({{ $batch->count }})</option>
                @endforeach
            </select>
            <select name="type" class="ui-input ui-input--sm" aria-label="{{ ___('Type') }}">
                <option value="">{{ ___('All types') }}</option>
                <option value="percentage" @selected(($filters['type'] ?? '') === 'percentage')>{{ ___('Percentage') }}</option>
                <option value="fixed" @selected(($filters['type'] ?? '') === 'fixed')>{{ ___('Fixed') }}</option>
                <option value="bogo" @selected(($filters['type'] ?? '') === 'bogo')>{{ ___('Buy X, get Y') }}</option>
            </select>
            <input type="date" name="created_from" class="ui-input ui-input--sm" value="{{ $filters['created_from'] ?? '' }}" aria-label="{{ ___('Created from') }}">
            <input type="date" name="created_to" class="ui-input ui-input--sm" value="{{ $filters['created_to'] ?? '' }}" aria-label="{{ ___('Created to') }}">
            <x-ui.button type="submit" size="sm" variant="secondary">{{ ___('Filter') }}</x-ui.button>
            @if (! empty($filters))
                <x-ui.button size="sm" variant="ghost" :href="route('stores.coupons.index')">{{ ___('Clear') }}</x-ui.button>
            @endif
        </form>
    </section>

    @if ($items->isEmpty())
        <div class="ui-card">
            <x-ui.empty :title="___('No coupons yet')" :body="___('Create your first discount code to share with diners.')" icon="gift">
                <x-ui.button :href="route('stores.coupons.create')" icon="plus">{{ ___('Create coupon') }}</x-ui.button>
            </x-ui.empty>
        </div>
    @else
        <section class="ui-card ui-card--flush" id="ownerCouponsTableWrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th class="ui-coupons__check-col"><input type="checkbox" id="ownerCouponsCheckAll" aria-label="{{ ___('Select all') }}"></th>
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
                            <td class="ui-coupons__check-col" data-label="{{ ___('Select') }}"><input type="checkbox" class="owner-coupon-check" value="{{ $coupon->id }}" aria-label="{{ $coupon->code }}"></td>
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

        <x-ui.card :title="___('Bulk Actions')">
            <form method="POST" action="{{ route('stores.coupons.bulk.action') }}" id="ownerCouponBulkForm" class="ui-coupons__bulk">
                @csrf
                @foreach (($filters ?? []) as $fkey => $fval)
                    <input type="hidden" name="{{ $fkey }}" value="{{ $fval }}" data-filter-hidden>
                @endforeach
                <select name="action" id="ownerCouponBulkAction" class="ui-input ui-input--sm" required>
                    <option value="delete_unused">{{ ___('Delete unused') }}</option>
                    <option value="expire_now">{{ ___('Expire now') }}</option>
                    <option value="extend_expiry">{{ ___('Extend expiry') }}</option>
                    <option value="set_limit">{{ ___('Set usage limit') }}</option>
                </select>
                <input type="datetime-local" name="extend_to" id="ownerCouponBulkExtend" class="ui-input ui-input--sm" hidden>
                <input type="number" name="limit" id="ownerCouponBulkLimit" class="ui-input ui-input--sm" min="0" placeholder="{{ ___('Uses Limit') }}" hidden>
                <button type="button" id="ownerCouponBulkSelected" class="ui-btn ui-btn--secondary ui-btn--sm">{{ ___('Apply to selected') }}</button>
                <button type="button" id="ownerCouponBulkFiltered" class="ui-btn ui-btn--secondary ui-btn--sm">{{ ___('Apply to all filtered') }}</button>
            </form>
        </x-ui.card>

        <script nonce="{{ csp_nonce() }}">
            (function () {
                'use strict';
                var checkAll = document.getElementById('ownerCouponsCheckAll');
                var form = document.getElementById('ownerCouponBulkForm');
                var actionSel = document.getElementById('ownerCouponBulkAction');
                var extendEl = document.getElementById('ownerCouponBulkExtend');
                var limitEl = document.getElementById('ownerCouponBulkLimit');
                var btnSel = document.getElementById('ownerCouponBulkSelected');
                var btnFlt = document.getElementById('ownerCouponBulkFiltered');
                if (!form || !actionSel) { return; }

                if (checkAll) {
                    checkAll.addEventListener('change', function () {
                        document.querySelectorAll('.owner-coupon-check').forEach(function (cb) {
                            cb.checked = checkAll.checked;
                        });
                    });
                }

                function syncFields() {
                    var v = actionSel.value;
                    if (extendEl) { extendEl.hidden = v !== 'extend_expiry'; }
                    if (limitEl) { limitEl.hidden = v !== 'set_limit'; }
                }
                actionSel.addEventListener('change', syncFields);
                syncFields();

                function submitBulk(applyFiltered) {
                    form.querySelectorAll('input[data-dynamic]').forEach(function (n) { n.remove(); });
                    if (applyFiltered) {
                        var f = document.createElement('input');
                        f.type = 'hidden'; f.name = 'apply_to_filtered'; f.value = '1'; f.setAttribute('data-dynamic', '1');
                        form.appendChild(f);
                    } else {
                        document.querySelectorAll('.owner-coupon-check:checked').forEach(function (cb) {
                            var h = document.createElement('input');
                            h.type = 'hidden'; h.name = 'ids[]'; h.value = cb.value; h.setAttribute('data-dynamic', '1');
                            form.appendChild(h);
                        });
                    }
                    form.submit();
                }

                if (btnSel) { btnSel.addEventListener('click', function () { submitBulk(false); }); }
                if (btnFlt) { btnFlt.addEventListener('click', function () { submitBulk(true); }); }
            })();
        </script>
    @endif
</div>
@endsection
