{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Coupons'))
@section('header_buttons')
    <x-ui.button size="sm" variant="secondary" icon="layers" :href="route('admin.coupons.bulk.create')">{{ ___('Bulk Create') }}</x-ui.button>
    <x-ui.button size="sm" variant="secondary" icon="list" :href="route('admin.coupons.batches.index')">{{ ___('Batches') }}</x-ui.button>
    <x-ui.button size="sm" variant="secondary" icon="download" :href="route('admin.coupons.export')" id="couponExportBtn">{{ ___('Export CSV') }}</x-ui.button>
    <x-ui.button size="sm" icon="plus" :data-url="route('admin.coupons.create')" data-toggle="slidePanel">{{ ___('Add New') }}</x-ui.button>
@endsection
@section('content')
    <div class="ui-stack" id="adminCouponsWrap">
        <x-ui.data-table :source="route('admin.coupons.index')" :delete="route('admin.coupons.delete')" :columns="[
            ['label' => '#'], ['label' => ___('Coupon')], ['label' => ___('Percentage')], ['label' => ___('Used')],
            ['label' => ___('Limit')], ['label' => ___('expiry date')], ['label' => ___('Coupon Type')],
            ['label' => '', 'sort' => false], ['check' => true],
        ]">
            <x-slot:filters>
                <select name="status" class="ui-input ui-input--sm" data-ui-dt-filter aria-label="{{ ___('Status') }}">
                    <option value="">{{ ___('All statuses') }}</option>
                    <option value="active">{{ ___('Active') }}</option>
                    <option value="never_used">{{ ___('Never used') }}</option>
                    <option value="partly_used">{{ ___('Partly used') }}</option>
                    <option value="used_up">{{ ___('Used up') }}</option>
                    <option value="expired">{{ ___('Expired') }}</option>
                </select>
                <select name="batch_id" class="ui-input ui-input--sm" data-ui-dt-filter aria-label="{{ ___('Batch') }}">
                    <option value="">{{ ___('All batches') }}</option>
                    @foreach (($batches ?? []) as $batch)
                        <option value="{{ $batch->id }}">{{ $batch->name ?: ($batch->prefix ?: '#'.$batch->id) }} ({{ $batch->count }})</option>
                    @endforeach
                </select>
                <select name="type" class="ui-input ui-input--sm" data-ui-dt-filter aria-label="{{ ___('Coupon Type') }}">
                    <option value="">{{ ___('All types') }}</option>
                    <option value="percentage">{{ ___('Percentage') }}</option>
                    <option value="fixed">{{ ___('Fixed') }}</option>
                </select>
                <select name="applies_interval" class="ui-input ui-input--sm" data-ui-dt-filter aria-label="{{ ___('Applies to billing interval') }}">
                    <option value="">{{ ___('All intervals') }}</option>
                    <option value="any">{{ ___('Any') }}</option>
                    <option value="monthly">{{ ___('Monthly') }}</option>
                    <option value="yearly">{{ ___('Yearly') }}</option>
                    <option value="lifetime">{{ ___('Lifetime') }}</option>
                </select>
                <input type="date" name="created_from" class="ui-input ui-input--sm" data-ui-dt-filter aria-label="{{ ___('Created from') }}">
                <input type="date" name="created_to" class="ui-input ui-input--sm" data-ui-dt-filter aria-label="{{ ___('Created to') }}">
            </x-slot:filters>
        </x-ui.data-table>

        <x-ui.card :title="___('Bulk Actions')">
            <form method="POST" action="{{ route('admin.coupons.bulk.action') }}" id="couponBulkActionForm" class="ui-cluster">
                @csrf
                <select name="action" id="couponBulkActionSelect" class="ui-input ui-input--sm" required>
                    <option value="delete_unused">{{ ___('Delete unused') }}</option>
                    <option value="expire_now">{{ ___('Expire now') }}</option>
                    <option value="extend_expiry">{{ ___('Extend expiry') }}</option>
                    <option value="set_limit">{{ ___('Set usage limit') }}</option>
                </select>
                <input type="datetime-local" name="extend_to" id="couponBulkExtendTo" class="ui-input ui-input--sm" hidden>
                <input type="number" name="limit" id="couponBulkLimit" class="ui-input ui-input--sm" min="0" placeholder="{{ ___('Uses Limit') }}" hidden>
                <button type="button" id="couponBulkApplySelected" class="ui-btn ui-btn--secondary ui-btn--sm">{{ ___('Apply to selected') }}</button>
                <button type="button" id="couponBulkApplyFiltered" class="ui-btn ui-btn--secondary ui-btn--sm">{{ ___('Apply to all filtered') }}</button>
            </form>
        </x-ui.card>
    </div>

    <script nonce="{{ csp_nonce() }}">
        (function () {
            'use strict';
            var wrap = document.getElementById('adminCouponsWrap');
            var form = document.getElementById('couponBulkActionForm');
            var actionSel = document.getElementById('couponBulkActionSelect');
            var extendInput = document.getElementById('couponBulkExtendTo');
            var limitInput = document.getElementById('couponBulkLimit');
            var exportBtn = document.getElementById('couponExportBtn');
            if (!wrap || !form || !actionSel) { return; }

            var exportBase = exportBtn ? exportBtn.getAttribute('href') : '';

            function currentFilters() {
                var params = new URLSearchParams();
                wrap.querySelectorAll('[data-ui-dt-filter]').forEach(function (el) {
                    if (el.name && el.value) { params.set(el.name, el.value); }
                });
                var search = wrap.querySelector('[data-ui-dt-search]');
                if (search && search.value.trim()) { params.set('search', search.value.trim()); }
                return params;
            }

            function syncExportHref() {
                if (!exportBtn || !exportBase) { return; }
                var qs = currentFilters().toString();
                exportBtn.setAttribute('href', qs ? exportBase + '?' + qs : exportBase);
            }

            function syncActionFields() {
                var v = actionSel.value;
                extendInput.hidden = v !== 'extend_expiry';
                limitInput.hidden = v !== 'set_limit';
            }

            wrap.querySelectorAll('[data-ui-dt-filter]').forEach(function (el) {
                el.addEventListener('change', syncExportHref);
            });
            actionSel.addEventListener('change', syncActionFields);
            syncActionFields();

            function submitBulk(applyFiltered) {
                form.querySelectorAll('input[data-dynamic]').forEach(function (n) { n.remove(); });
                if (applyFiltered) {
                    var f = document.createElement('input');
                    f.type = 'hidden'; f.name = 'apply_to_filtered'; f.value = '1'; f.setAttribute('data-dynamic', '1');
                    form.appendChild(f);
                    currentFilters().forEach(function (val, key) {
                        var h = document.createElement('input');
                        h.type = 'hidden'; h.name = key; h.value = val; h.setAttribute('data-dynamic', '1');
                        form.appendChild(h);
                    });
                } else {
                    wrap.querySelectorAll('.quick-check:checked').forEach(function (cb) {
                        var h = document.createElement('input');
                        h.type = 'hidden'; h.name = 'ids[]'; h.value = cb.value; h.setAttribute('data-dynamic', '1');
                        form.appendChild(h);
                    });
                }
                form.submit();
            }

            document.getElementById('couponBulkApplySelected').addEventListener('click', function () { submitBulk(false); });
            document.getElementById('couponBulkApplyFiltered').addEventListener('click', function () { submitBulk(true); });
        })();
    </script>
@endsection
