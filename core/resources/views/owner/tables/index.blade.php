{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Tables'))
@section('page_title', ___('Tables & QR codes'))
@section('page_sub', $store->title.' - '.___('Per-table QR codes and share links.'))

@section('page_actions')
    @php
        $__tvAllowed = Route::has('stores.tableVerify.index') && \App\Services\FeatureAccess::storeAllows((int) $store->id, \Juwri\Modules\Tables\Services\TableSessionService::FEATURE);
        $__qrDesigner = Route::has('qr.designer') && \App\Services\FeatureAccess::storeAllows($store->id, 'qr_designer');
    @endphp
    <div class="ui-pop-host ui-menu-more" data-ui-pop>
        <button type="button" class="ui-btn ui-btn--secondary" data-ui-pop-toggle aria-haspopup="true" aria-expanded="false">
            <span class="ui-btn__icon"><x-ui.icon name="dots" /></span>{{ ___('More') }}
        </button>
        <div class="ui-pop ui-pop--end" data-ui-pop-panel hidden>
            {{-- PARITY-RUN T3: entry point to the verification settings. Hidden unless the
                 platform kill-switch is on for this store — the route itself carries
                 feature:table_otp, so an unentitled owner would only find a 403. --}}
            @if($__tvAllowed)
                <a class="ui-pop__item" href="{{ route('stores.tableVerify.index', $store->id).($branchId ? '?branch='.$branchId : '') }}"><x-ui.icon name="check" size="18" /> {{ ___('Table verification') }}</a>
            @endif
            {{-- PARITY-RUN T10: the QR design suite. Gated exactly like its route, so this
                 never advertises a link that 403s. --}}
            @if($__qrDesigner)
                <a class="ui-pop__item" href="{{ route('qr.designer') }}?store_id={{ $store->id }}"><x-ui.icon name="qr" size="18" /> {{ ___('QR Designer') }}</a>
            @endif
            @if(Route::has('floor-map.index'))
                <a class="ui-pop__item" href="{{ route('floor-map.index', ['storeId' => $store->id] + ($branchId ? ['branch' => $branchId] : [])) }}"><x-ui.icon name="more" size="18" /> {{ ___('Floor Map') }}</a>
            @endif
        </div>
    </div>
    <button type="button" class="ui-btn" x-data @click="$dispatch('table-add')" data-controller="bridge--button" data-bridge-title="{{ ___('Add table') }}" data-bridge-ios-image="plus" data-bridge-android-image="add">
        <span class="ui-btn__icon"><x-ui.icon name="plus" /></span>{{ ___('Add table') }}
    </button>
@endsection

@section('content')
<div class="ui-scope ui-rooms" x-data="tablesPage()" @table-add.window="openAdd()">
    <section class="ui-card ui-card--flush">
        <div class="ui-card__head ui-rooms__head">
            <h2 class="ui-card__title">{{ ___('All tables') }}</h2>
            @if($branches->count() > 1)
                {{-- Same branch scoping as the Floor Map, so both screens show one list. --}}
                <nav class="ui-chips" aria-label="{{ ___('Branch') }}">
                    @foreach($branches as $b)
                        <a href="{{ route('stores.tables', $store->id) }}?branch={{ $b->id }}" class="ui-chip" @if((int) $branchId === (int) $b->id) aria-current="true" @endif>{{ $b->name }}</a>
                    @endforeach
                </nav>
            @endif
        </div>
        @if($tables->isEmpty())
            <x-ui.empty :title="___('No tables yet')" :body="___('Add tables to generate a unique QR code and link for each one.')" icon="dine-in" />
        @else
            @php
                // T3: the code column only appears once the store is actually
                // running code-based verification — otherwise it is noise.
                $__tvCodes = \Juwri\Modules\Tables\Services\TableSessionService::mode((int) $store->id) === \Juwri\Modules\Tables\Services\TableSessionService::MODE_TABLE_CODE;
            @endphp
            <table class="ui-table">
                <thead><tr><th>{{ ___('Table') }}</th>@if($__tvCodes)<th>{{ ___('Table code') }}</th>@endif<th class="ui-table__num">{{ ___('Actions') }}</th></tr></thead>
                <tbody>
                    @foreach($tables as $table)
                        <tr>
                            <td data-primary><span class="ui-ltr"><strong>#{{ $table->table_no }}</strong></span></td>
                            @if($__tvCodes)
                                <td data-label="{{ ___('Table code') }}">
                                    @if($table->verify_code)
                                        <code class="ui-ltr">{{ $table->verify_code }}</code>
                                    @else
                                        <span class="ui-orders-muted">{{ ___('Not set') }}</span>
                                    @endif
                                </td>
                            @endif
                            <td class="ui-catalog__row-actions">
                                <div class="ui-rooms__actions">
                                    <a class="ui-icon-btn" href="{{ route('stores.qrDesigner', $store->id).'?table='.$table->key }}" aria-label="{{ ___('QR') }}" title="{{ ___('QR') }}"><x-ui.icon name="qr" /></a>
                                    {{-- NOTE: @js() inside a component attribute reaches Alpine
                                         uncompiled and fails silently — use a data attribute + the
                                         shared copy handler in the scripts stack instead. --}}
                                    <button type="button" class="ui-icon-btn" data-copy-link="{{ store_public_qr_url($store, $table->key) }}" aria-label="{{ ___('Copy link') }}" title="{{ ___('Copy link') }}"><x-ui.icon name="share" /></button>
                                    @if(Route::has('qr.designer.download') && \App\Services\FeatureAccess::storeAllows($store->id, 'qr_designer'))
                                        {{-- T10: the branded PNG, and a rotation that kills the printed code. --}}
                                        <a class="ui-icon-btn" href="{{ route('qr.designer.download').'?store_id='.$store->id.'&target=table&id='.$table->id.'&format=png' }}" aria-label="PNG" title="PNG"><x-ui.icon name="download" /></a>
                                        <form action="{{ route('qr.designer.rotate') }}?store_id={{ $store->id }}" method="post" data-confirm="{{ ___('Regenerate this QR? The printed code stops working.') }}">
                                            @csrf
                                            <input type="hidden" name="table_id" value="{{ $table->id }}">
                                            <button type="submit" class="ui-icon-btn" aria-label="{{ ___('Regenerate') }}" title="{{ ___('Regenerate') }}"><x-ui.icon name="refresh" /></button>
                                        </form>
                                    @endif
                                    <button type="button" class="ui-icon-btn" aria-label="{{ ___('Edit') }}" title="{{ ___('Edit') }}" @click="openEdit({{ $table->id }}, {{ $table->table_no }})"><x-ui.icon name="pencil" /></button>
                                    <form action="{{ route('stores.deleteTable', $store->id) }}" method="post" data-confirm="{{ ___('Delete this table?') }}" data-confirm-danger>
                                        @csrf
                                        <input type="hidden" name="id" value="{{ $table->id }}">
                                        <button type="submit" class="ui-icon-btn ui-menu-item__delete" aria-label="{{ ___('Delete') }}" title="{{ ___('Delete') }}"><x-ui.icon name="trash" /></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <x-ui.sheet id="table-dialog" :title="___('Add table')" x-ref="dlg">
        <form action="{{ route('stores.updateTable', $store->id) }}" method="post" id="table-dialog-form">
            @csrf
            <input type="hidden" name="id" x-model="editId">
            <input type="hidden" name="branch_id" value="{{ $branchId }}">
            <label class="ui-label" for="table-no">{{ ___('Table number') }}</label>
            <input type="number" id="table-no" name="table_number" class="ui-input" x-model="tableNo" required min="1" max="255">
        </form>
        <x-slot:footer>
            <button type="button" class="ui-btn ui-btn--secondary" data-ui-close>{{ ___('Cancel') }}</button>
            <button type="submit" class="ui-btn" form="table-dialog-form"><span class="ui-btn__icon"><x-ui.icon name="check" /></span>{{ ___('Save') }}</button>
        </x-slot:footer>
    </x-ui.sheet>
</div>
@endsection

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
    function tablesPage() {
        return {
            editId: '', tableNo: '',
            openAdd() { this.editId=''; this.tableNo=''; this.$refs.dlg.showModal(); },
            openEdit(id, no) { this.editId=id; this.tableNo=no; this.$refs.dlg.showModal(); },
        };
    }

    document.querySelectorAll('[data-copy-link]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            navigator.clipboard.writeText(btn.dataset.copyLink).then(function () {
                if (window.ownerUi) { window.ownerUi.toast(@js(___('Copied'))); }
                else { (btn.querySelector('.btn__label') || btn).textContent = @js(___('Copied')); }
            });
        });
    });
</script>
@endpush
