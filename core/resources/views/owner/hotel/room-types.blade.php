{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Room types & rates'))
@section('page_title', ___('Room types & rate plans'))
@section('page_sub', $store->title.' - '.___('Room categories, base rates and the plans they sell on.'))

@php
    // Lane A (HotelPmsSetupController) supplies these; defaults keep the blade
    // renderable pre-merge / defensive.
    //   $roomTypes list of objects: id, name, code, base_rate, max_occupancy, sort, active
    //   $ratePlans list of objects: id, name, parent_id, modifier_type, modifier_value,
    //                               refundable, cancel_before_hours, active
    $roomTypes = collect($roomTypes ?? []);
    $ratePlans = collect($ratePlans ?? []);
    $currency  = trim($currency ?? '');

    // The setup page itself lives behind hotel.pms.types (nav B1). Every
    // write action is Route::has()-guarded so a partial lane-A merge never 500s;
    // the CRUD sub-route names follow the booking lifecycle convention
    // (.store base, .update/{id}, .destroy/{id}) — a documented assumption the
    // orchestrator reconciles at merge (SPEC §5 pins these only as `.*`).
    $hasBackend   = Route::has('hotel.pms.types');
    $canTypeStore = Route::has('hotel.pms.types.store');
    $canTypeEdit  = Route::has('hotel.pms.types.update');
    $canTypeDel   = Route::has('hotel.pms.types.delete');
    $canPlanStore = Route::has('hotel.pms.plans.store');
    $canPlanEdit  = Route::has('hotel.pms.plans.update');
    $canPlanDel   = Route::has('hotel.pms.plans.delete');
    $ratesUrl     = Route::has('hotel.pms.rates') ? route('hotel.pms.rates') : null;

@endphp

@if($hasBackend)
@section('page_actions')
    @if($canTypeStore)
        <x-ui.button type="button" icon="plus" x-data @click="$dispatch('type-add')">{{ ___('Add room type') }}</x-ui.button>
    @endif
    @if($canPlanStore)
        <x-ui.button type="button" variant="secondary" icon="products" x-data @click="$dispatch('plan-add')">{{ ___('Add rate plan') }}</x-ui.button>
    @endif
@endsection
@endif

@section('content')
@unless($hasBackend)
    <x-ui.empty :title="___('PMS backend pending')" :body="___('Room types, rate plans and the pricing calendar appear here once the booking engine is enabled.')" />
@else
<div class="ui-stack" x-data="pmsTypesSetup()"
     data-type-store="{{ $canTypeStore ? route('hotel.pms.types.store') : '' }}"
     data-plan-store="{{ $canPlanStore ? route('hotel.pms.plans.store') : '' }}"
     @type-add.window="addType()" @plan-add.window="addPlan()">

    {{-- Room types --}}
    <div class="ui-card">
        <div class="ui-cluster ui-cluster--between ui-card__head">
            <h3 class="ui-section-title">{{ ___('Room types') }}</h3>
            <span class="ui-tag" data-tone="neutral">{{ $roomTypes->count() }}</span>
        </div>
        @if($roomTypes->isEmpty())
            <div class="ui-card__body">
                <x-ui.empty :title="___('No room types yet')" :body="___('Add a room type (e.g. Standard, Deluxe) with a nightly base rate to start taking bookings.')" />
            </div>
        @else
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead><tr>
                        <th>{{ ___('Name') }}</th>
                        <th>{{ ___('Code') }}</th>
                        <th class="ui-end">{{ ___('Base rate') }}</th>
                        <th class="ui-end">{{ ___('Max occupancy') }}</th>
                        <th>{{ ___('Active') }}</th>
                        <th class="ui-end">{{ ___('Actions') }}</th>
                    </tr></thead>
                    <tbody>
                        @foreach($roomTypes as $t)
                            <tr>
                                <td data-label="{{ ___('Name') }}"><strong>{{ $t->name }}</strong></td>
                                <td class="ui-muted" data-label="{{ ___('Code') }}">{{ $t->code ?: '—' }}</td>
                                <td class="ui-end" data-label="{{ ___('Base rate') }}">{{ $currency !== '' ? $currency.' ' : '' }}{{ number_format((float) ($t->base_rate ?? 0), 2) }}</td>
                                <td class="ui-end" data-label="{{ ___('Max occupancy') }}">{{ (int) ($t->max_occupancy ?? 0) }}</td>
                                <td data-label="{{ ___('Active') }}">
                                    @if(($t->active ?? 1))
                                        <span class="ui-tag" data-tone="success">{{ ___('Active') }}</span>
                                    @else
                                        <span class="ui-tag" data-tone="neutral">{{ ___('Hidden') }}</span>
                                    @endif
                                </td>
                                <td class="ui-end" data-label="{{ ___('Actions') }}">
                                    <div class="ui-cluster ui-cluster--end ui-inline">
                                        @if($ratesUrl)
                                            <x-ui.button :href="$ratesUrl.'?room_type_id='.$t->id" variant="ghost" size="sm" icon="calendar">{{ ___('Rates') }}</x-ui.button>
                                        @endif
                                        @if($canTypeEdit)
                                            <x-ui.button type="button" variant="ghost" size="sm" icon="pencil"
                                                @click="editType({{ \Illuminate\Support\Js::from(['id' => $t->id, 'updateUrl' => route('hotel.pms.types.update', $t->id), 'name' => $t->name, 'code' => $t->code, 'base_rate' => $t->base_rate, 'max_occupancy' => $t->max_occupancy, 'sort' => $t->sort ?? 0, 'active' => (bool) ($t->active ?? 1)]) }})">{{ ___('Edit') }}</x-ui.button>
                                        @endif
                                        @if($canTypeDel)
                                            <form action="{{ route('hotel.pms.types.delete', $t->id) }}" method="post" data-confirm="{{ ___('Delete this room type? Rooms and future bookings must be reassigned first.') }}" data-confirm-danger>
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

    {{-- Rate plans --}}
    <div class="ui-card">
        <div class="ui-cluster ui-cluster--between ui-card__head">
            <h3 class="ui-section-title">{{ ___('Rate plans') }}</h3>
            <span class="ui-tag" data-tone="neutral">{{ $ratePlans->count() }}</span>
        </div>
        <div class="ui-muted ui-card__body ui-rule-bottom">
            {{ ___('A base plan prices nights directly. A derived plan takes a parent plan and applies a percentage or fixed adjustment (e.g. “Non-refundable −10%”).') }}
        </div>
        @if($ratePlans->isEmpty())
            <div class="ui-card__body">
                <x-ui.empty :title="___('No rate plans yet')" :body="___('A default “Standard” flexible plan is created automatically. Add more to sell the same room at different terms.')" />
            </div>
        @else
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead><tr>
                        <th>{{ ___('Name') }}</th>
                        <th>{{ ___('Based on') }}</th>
                        <th>{{ ___('Adjustment') }}</th>
                        <th>{{ ___('Refundable') }}</th>
                        <th class="ui-end">{{ ___('Cancel before') }}</th>
                        <th>{{ ___('Active') }}</th>
                        <th class="ui-end">{{ ___('Actions') }}</th>
                    </tr></thead>
                    <tbody>
                        @foreach($ratePlans as $p)
                            <tr>
                                <td data-label="{{ ___('Name') }}"><strong>{{ $p->name }}</strong></td>
                                <td class="ui-small" data-label="{{ ___('Based on') }}">{{ ($p->parent_id ?? null) ? (\Juwri\Core\Support\Views\OwnerOpsViewHelpers::ratePlanName((int) $p->parent_id, $ratePlans) ?? '—') : ___('Base') }}</td>
                                <td class="ui-small" data-label="{{ ___('Adjustment') }}">
                                    @if(($p->parent_id ?? null) && ($p->modifier_type ?? null))
                                        {{ ($p->modifier_type === 'percent') ? rtrim(rtrim(number_format((float) $p->modifier_value, 2), '0'), '.').'%' : ($currency !== '' ? $currency.' ' : '').number_format((float) $p->modifier_value, 2) }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td data-label="{{ ___('Refundable') }}">{{ ($p->refundable ?? 1) ? ___('Yes') : ___('No') }}</td>
                                <td class="ui-end" data-label="{{ ___('Cancel before') }}">{{ (int) ($p->cancel_before_hours ?? 0) }}{{ ___('h') }}</td>
                                <td data-label="{{ ___('Active') }}">
                                    @if(($p->active ?? 1))
                                        <span class="ui-tag" data-tone="success">{{ ___('Active') }}</span>
                                    @else
                                        <span class="ui-tag" data-tone="neutral">{{ ___('Hidden') }}</span>
                                    @endif
                                </td>
                                <td class="ui-end" data-label="{{ ___('Actions') }}">
                                    <div class="ui-cluster ui-cluster--end ui-inline">
                                        @if($canPlanEdit)
                                            <x-ui.button type="button" variant="ghost" size="sm" icon="pencil"
                                                @click="editPlan({{ \Illuminate\Support\Js::from(['id' => $p->id, 'updateUrl' => route('hotel.pms.plans.update', $p->id), 'name' => $p->name, 'parent_id' => $p->parent_id, 'modifier_type' => $p->modifier_type ?? 'percent', 'modifier_value' => $p->modifier_value, 'refundable' => (bool) ($p->refundable ?? 1), 'cancel_before_hours' => $p->cancel_before_hours ?? 24, 'active' => (bool) ($p->active ?? 1)]) }})">{{ ___('Edit') }}</x-ui.button>
                                        @endif
                                        @if($canPlanDel)
                                            <form action="{{ route('hotel.pms.plans.delete', $p->id) }}" method="post" data-confirm="{{ ___('Delete this rate plan? Bookings that reference it keep their pinned price.') }}" data-confirm-danger>
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

    {{-- Room type add / edit dialog --}}
    <dialog class="ui-dialog ui-dialog--flat" x-ref="typeDlg" style="max-width:480px;">
        <form :action="t.action" method="post">
            @csrf
            <div class="ui-cluster ui-cluster--between ui-rule-bottom">
                <h5 x-text="t.id ? @js(___('Edit room type')) : @js(___('Add room type'))">{{ ___('Add room type') }}</h5>
                <button type="button" class="ui-icon-btn ui-icon-btn--ghost" @click="$refs.typeDlg.close()" aria-label="{{ ___('Close') }}"><x-ui.icon name="x" size="18" /></button>
            </div>
            <div class="ui-stack">
                <div>
                    <label class="ui-label">{{ ___('Name') }}</label>
                    <input type="text" name="name" class="ui-input" x-model="t.name" maxlength="80" required>
                </div>
                <div class="ui-form-grid">
                    <div>
                        <label class="ui-label">{{ ___('Code') }} <span class="ui-muted">({{ ___('optional') }})</span></label>
                        <input type="text" name="code" class="ui-input" x-model="t.code" maxlength="20" placeholder="DLX">
                    </div>
                    <div>
                        <label class="ui-label">{{ ___('Max occupancy') }}</label>
                        <input type="number" name="max_occupancy" class="ui-input" x-model="t.max_occupancy" min="1" max="30">
                    </div>
                </div>
                <div class="ui-form-grid">
                    <div>
                        <label class="ui-label">{{ ___('Base rate') }} <span class="ui-muted">{{ $currency }}</span></label>
                        <input type="number" name="base_rate" class="ui-input" x-model="t.base_rate" step="0.01" min="0" required>
                    </div>
                    <div>
                        <label class="ui-label">{{ ___('Sort') }}</label>
                        <input type="number" name="sort" class="ui-input" x-model="t.sort" min="0">
                    </div>
                </div>
                <div>
                    <input type="hidden" name="active" value="0">
                    <div class="ui-check">
                        <input type="checkbox" name="active" value="1" :checked="t.active" @change="t.active = $event.target.checked" id="typeActive">
                        <label for="typeActive">{{ ___('Active (bookable)') }}</label>
                    </div>
                </div>
            </div>
            <div class="ui-cluster ui-cluster--end ui-rule-top">
                <x-ui.button type="button" variant="ghost" @click="$refs.typeDlg.close()">{{ ___('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" icon="check">{{ ___('Save') }}</x-ui.button>
            </div>
        </form>
    </dialog>

    {{-- Rate plan add / edit dialog. Modifier fields only appear once a parent is picked. --}}
    <dialog class="ui-dialog ui-dialog--flat" x-ref="planDlg" style="max-width:480px;">
        <form :action="p.action" method="post">
            @csrf
            <div class="ui-cluster ui-cluster--between ui-rule-bottom">
                <h5 x-text="p.id ? @js(___('Edit rate plan')) : @js(___('Add rate plan'))">{{ ___('Add rate plan') }}</h5>
                <button type="button" class="ui-icon-btn ui-icon-btn--ghost" @click="$refs.planDlg.close()" aria-label="{{ ___('Close') }}"><x-ui.icon name="x" size="18" /></button>
            </div>
            <div class="ui-stack">
                <div>
                    <label class="ui-label">{{ ___('Name') }}</label>
                    <input type="text" name="name" class="ui-input" x-model="p.name" maxlength="80" required>
                </div>
                <div>
                    <label class="ui-label">{{ ___('Based on') }}</label>
                    <select name="parent_id" class="ui-input" x-model="p.parent_id">
                        <option value="">{{ ___('Base plan (prices nights directly)') }}</option>
                        @foreach($ratePlans as $p2)
                            @if(!($p2->parent_id ?? null))
                                <option value="{{ $p2->id }}">{{ $p2->name }}</option>
                            @endif
                        @endforeach
                    </select>
                </div>
                {{-- Only a derived plan carries a modifier. --}}
                <div class="ui-form-grid" x-show="p.parent_id" x-cloak>
                    <div>
                        <label class="ui-label">{{ ___('Adjustment type') }}</label>
                        <select name="modifier_type" class="ui-input" x-model="p.modifier_type">
                            <option value="percent">{{ ___('Percent (%)') }}</option>
                            <option value="fixed">{{ ___('Fixed amount') }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="ui-label">{{ ___('Adjustment value') }}</label>
                        <input type="number" name="modifier_value" class="ui-input" x-model="p.modifier_value" step="0.01" placeholder="{{ ___('e.g. -10') }}">
                    </div>
                </div>
                <div class="ui-form-grid">
                    <div>
                        <label class="ui-label">{{ ___('Cancel before (hours)') }}</label>
                        <input type="number" name="cancel_before_hours" class="ui-input" x-model="p.cancel_before_hours" min="0">
                    </div>
                    <div class="ui-cluster ui-cluster--bottom">
                        <div>
                            <input type="hidden" name="refundable" value="0">
                            <div class="ui-check">
                                <input type="checkbox" name="refundable" value="1" :checked="p.refundable" @change="p.refundable = $event.target.checked" id="planRefundable">
                                <label for="planRefundable">{{ ___('Refundable') }}</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div>
                    <input type="hidden" name="active" value="0">
                    <div class="ui-check">
                        <input type="checkbox" name="active" value="1" :checked="p.active" @change="p.active = $event.target.checked" id="planActive">
                        <label for="planActive">{{ ___('Active') }}</label>
                    </div>
                </div>
            </div>
            <div class="ui-cluster ui-cluster--end ui-rule-top">
                <x-ui.button type="button" variant="ghost" @click="$refs.planDlg.close()">{{ ___('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" icon="check">{{ ___('Save') }}</x-ui.button>
            </div>
        </form>
    </dialog>
</div>
@endunless
@endsection

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
    function pmsTypesSetup() {
        return {
            typeStore: '', planStore: '',
            t: { id: '', action: '', name: '', code: '', base_rate: '', max_occupancy: 2, sort: 0, active: true },
            p: { id: '', action: '', name: '', parent_id: '', modifier_type: 'percent', modifier_value: '', refundable: true, cancel_before_hours: 24, active: true },
            init() {
                this.typeStore = this.$el.dataset.typeStore || '';
                this.planStore = this.$el.dataset.planStore || '';
            },
            addType() {
                this.t = { id: '', action: this.typeStore, name: '', code: '', base_rate: '', max_occupancy: 2, sort: 0, active: true };
                this.$refs.typeDlg.showModal();
            },
            editType(o) {
                this.t = { id: o.id, action: o.updateUrl, name: o.name || '', code: o.code || '', base_rate: o.base_rate, max_occupancy: o.max_occupancy, sort: o.sort, active: !!o.active };
                this.$refs.typeDlg.showModal();
            },
            addPlan() {
                this.p = { id: '', action: this.planStore, name: '', parent_id: '', modifier_type: 'percent', modifier_value: '', refundable: true, cancel_before_hours: 24, active: true };
                this.$refs.planDlg.showModal();
            },
            editPlan(o) {
                this.p = { id: o.id, action: o.updateUrl, name: o.name || '', parent_id: o.parent_id || '', modifier_type: o.modifier_type || 'percent', modifier_value: o.modifier_value, refundable: !!o.refundable, cancel_before_hours: o.cancel_before_hours, active: !!o.active };
                this.$refs.planDlg.showModal();
            },
        };
    }
</script>
@endpush
