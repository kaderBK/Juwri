{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Services & events'))
@section('page_title', ___('Extra services'))
@section('page_sub', $store->title.' - '.___('Chargeable extras — breakfast, minibar, spa — posted to a guest folio.'))

@php
    // Lane A (HotelPmsSetupController) supplies these; defaults keep the blade
    // renderable pre-merge / defensive.
    //   $services list of objects: id, name, price, tax_rate, charge_mode, active
    $services = collect($services ?? []);
    $currency = trim($currency ?? '');

    $hasBackend    = Route::has('hotel.pms.services');
    $canStore      = Route::has('hotel.pms.services.store');
    $canEdit       = Route::has('hotel.pms.services.update');
    $canDel        = Route::has('hotel.pms.services.delete');
    $eventsUrl     = Route::has('hotel.pms.events') ? route('hotel.pms.events') : null;

    $modeLabels = [
        'once' => ___('Once per stay'),
        'per_night' => ___('Per night'),
        'per_person_per_night' => ___('Per guest / night'),
    ];
@endphp

@if($hasBackend)
@section('page_actions')
    @if($eventsUrl)
        <x-ui.button :href="$eventsUrl" variant="secondary" icon="calendar">{{ ___('Events') }}</x-ui.button>
    @endif
    @if($canStore)
        <x-ui.button type="button" icon="plus" x-data @click="$dispatch('service-add')">{{ ___('Add service') }}</x-ui.button>
    @endif
@endsection
@endif

@section('content')
@unless($hasBackend)
    <x-ui.empty :title="___('PMS backend pending')" :body="___('Extra services and events appear here once the booking engine is enabled.')" />
@else
<div class="ui-stack" x-data="pmsServicesCatalog()"
     data-service-store="{{ $canStore ? route('hotel.pms.services.store') : '' }}"
     @service-add.window="addService()">

    <div class="ui-card">
        <div class="ui-cluster ui-cluster--between ui-card__head">
            <h3 class="ui-section-title">{{ ___('Service catalog') }}</h3>
            <span class="ui-tag" data-tone="neutral">{{ $services->count() }}</span>
        </div>
        <div class="ui-muted ui-card__body ui-rule-bottom">
            {{ ___('Each service posts to the guest’s folio. The charge mode decides how the price scales: once per stay, once per night, or per guest per night.') }}
        </div>
        @if($services->isEmpty())
            <div class="ui-card__body">
                <x-ui.empty :title="___('No services yet')" :body="___('Add a service (e.g. Breakfast, Airport transfer) to post it to any open folio.')" />
            </div>
        @else
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead><tr>
                        <th>{{ ___('Name') }}</th>
                        <th class="ui-end">{{ ___('Price') }}</th>
                        <th class="ui-end">{{ ___('Tax rate') }}</th>
                        <th>{{ ___('Charge mode') }}</th>
                        <th>{{ ___('Active') }}</th>
                        <th class="ui-end">{{ ___('Actions') }}</th>
                    </tr></thead>
                    <tbody>
                        @foreach($services as $s)
                            <tr>
                                <td data-label="{{ ___('Name') }}"><strong>{{ $s->name }}</strong></td>
                                <td class="ui-end" data-label="{{ ___('Price') }}">{{ $currency !== '' ? $currency.' ' : '' }}{{ number_format((float) ($s->price ?? 0), 2) }}</td>
                                <td class="ui-end" data-label="{{ ___('Tax rate') }}">{{ rtrim(rtrim(number_format((float) ($s->tax_rate ?? 0), 2), '0'), '.') }}%</td>
                                <td data-label="{{ ___('Charge mode') }}"><span class="ui-tag" data-tone="neutral">{{ $modeLabels[$s->charge_mode ?? 'once'] ?? ___('Once per stay') }}</span></td>
                                <td data-label="{{ ___('Active') }}">
                                    @if(($s->active ?? 1))
                                        <span class="ui-tag" data-tone="success">{{ ___('Active') }}</span>
                                    @else
                                        <span class="ui-tag" data-tone="neutral">{{ ___('Hidden') }}</span>
                                    @endif
                                </td>
                                <td class="ui-end" data-label="{{ ___('Actions') }}">
                                    <div class="ui-cluster ui-cluster--end ui-inline">
                                        @if($canEdit)
                                            <x-ui.button type="button" variant="ghost" size="sm" icon="pencil"
                                                @click="editService({{ \Illuminate\Support\Js::from(['id' => $s->id, 'updateUrl' => route('hotel.pms.services.update', $s->id), 'name' => $s->name, 'price' => $s->price, 'tax_rate' => $s->tax_rate ?? 0, 'charge_mode' => $s->charge_mode ?? 'once', 'active' => (bool) ($s->active ?? 1)]) }})">{{ ___('Edit') }}</x-ui.button>
                                        @endif
                                        @if($canDel)
                                            <form action="{{ route('hotel.pms.services.delete', $s->id) }}" method="post" data-confirm="{{ ___('Delete this service?') }}" data-confirm-danger>
                                                @csrf
                                                <x-ui.button type="submit" variant="danger" size="sm" icon="trash">{{ ___('Delete') }}</x-ui.button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Add / edit dialog --}}
    <dialog class="ui-dialog ui-dialog--flat" x-ref="svcDlg" style="max-width:480px;">
        <form :action="s.action" method="post">
            @csrf
            <div class="ui-cluster ui-cluster--between ui-rule-bottom">
                <h5 x-text="s.id ? @js(___('Edit service')) : @js(___('Add service'))">{{ ___('Add service') }}</h5>
                <button type="button" class="ui-icon-btn ui-icon-btn--ghost" @click="$refs.svcDlg.close()" aria-label="{{ ___('Close') }}"><x-ui.icon name="x" size="18" /></button>
            </div>
            <div class="ui-stack">
                <div>
                    <label class="ui-label">{{ ___('Name') }}</label>
                    <input type="text" name="name" class="ui-input" x-model="s.name" maxlength="80" required>
                </div>
                <div class="ui-form-grid">
                    <div>
                        <label class="ui-label">{{ ___('Price') }} <span class="ui-muted">{{ $currency }}</span></label>
                        <input type="number" name="price" class="ui-input" x-model="s.price" step="0.01" min="0" required>
                    </div>
                    <div>
                        <label class="ui-label">{{ ___('Tax rate') }} (%)</label>
                        <input type="number" name="tax_rate" class="ui-input" x-model="s.tax_rate" step="0.001" min="0">
                    </div>
                </div>
                <div>
                    <label class="ui-label">{{ ___('Charge mode') }}</label>
                    <select name="charge_mode" class="ui-input" x-model="s.charge_mode">
                        <option value="once">{{ ___('Once per stay') }}</option>
                        <option value="per_night">{{ ___('Per night') }}</option>
                        <option value="per_person_per_night">{{ ___('Per guest, per night') }}</option>
                    </select>
                    <div class="ui-field__help">{{ ___('“Once” charges the flat price. “Per night” multiplies by the number of nights. “Per guest, per night” multiplies by guests × nights.') }}</div>
                </div>
                <div>
                    <input type="hidden" name="active" value="0">
                    <div class="ui-check">
                        <input type="checkbox" name="active" value="1" :checked="s.active" @change="s.active = $event.target.checked" id="svcActive">
                        <label for="svcActive">{{ ___('Active') }}</label>
                    </div>
                </div>
            </div>
            <div class="ui-cluster ui-cluster--end ui-rule-top">
                <x-ui.button type="button" variant="ghost" @click="$refs.svcDlg.close()">{{ ___('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" icon="check">{{ ___('Save') }}</x-ui.button>
            </div>
        </form>
    </dialog>
</div>
@endunless
@endsection

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
    function pmsServicesCatalog() {
        return {
            serviceStore: '',
            s: { id: '', action: '', name: '', price: '', tax_rate: 0, charge_mode: 'once', active: true },
            init() { this.serviceStore = this.$el.dataset.serviceStore || ''; },
            addService() {
                this.s = { id: '', action: this.serviceStore, name: '', price: '', tax_rate: 0, charge_mode: 'once', active: true };
                this.$refs.svcDlg.showModal();
            },
            editService(o) {
                this.s = { id: o.id, action: o.updateUrl, name: o.name || '', price: o.price, tax_rate: o.tax_rate, charge_mode: o.charge_mode || 'once', active: !!o.active };
                this.$refs.svcDlg.showModal();
            },
        };
    }
</script>
@endpush
