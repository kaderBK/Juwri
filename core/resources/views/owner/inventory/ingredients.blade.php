{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Ingredients'))
@section('page_title', ___('Ingredient inventory'))
@section('page_sub', ___('Track raw stock, costs and reorder points.'))

@section('page_actions')
    @if($storeId)
        <x-ui.button :href="route('inventory.recipes.index', ['store_id' => $storeId])" variant="secondary" size="sm" icon="book-open">
            {{ ___('Recipes') }}
        </x-ui.button>
    @endif
    <x-ui.button :href="route('inventory.index')" variant="ghost" size="sm" icon="back">
        {{ ___('Inventory home') }}
    </x-ui.button>
@endsection

@section('content')
@php
    // Stock transfer between branches is a chain tool (ee): the Inventory ee half names its button/sheet partials in these
    // slots when the install has a Chain or Enterprise licence. Community: no controls (single-branch inventory is complete).
    $__transferButton = $branches->count() >= 2 ? \Juwri\Core\Support\Hooks::first('inventory.ingredients.transfer-button') : null;
    $__transferSheet = $branches->count() >= 2 ? \Juwri\Core\Support\Hooks::first('inventory.ingredients.transfer-sheet') : null;
@endphp
<div class="ui-scope ui-ing" x-data="linenIngredients({
        adjustBase: @js(url('inventory/ingredients')),
        token: @js(csrf_token()),
        units: @js(\App\Models\Ingredient::UNITS),
        deleteConfirm: @js(___('Delete this ingredient? Its stock history is removed too.'))
    })">

    {{-- Hero --}}
    <section @class(['ui-card', 'ui-account__hero', 'ui-ing__hero', 'is-low' => $lowStockCount > 0])>
        <div class="ui-ing__intro">
            <p class="ui-overline">{{ ___('Inventory') }}</p>
            <h2 class="ui-account__name">{{ ___('Stock on hand') }}</h2>
            <p class="ui-account__row-sub">{{ ___('Each sale deducts ingredients through recipes (advanced mode).') }}</p>
        </div>
        <dl class="ui-account__facts">
            <div><dt>{{ ___('Ingredients') }}</dt><dd>{{ $ingredients->count() }}</dd></div>
            <div @class(['is-warning' => $lowStockCount > 0])><dt>{{ ___('Below par') }}</dt><dd>{{ $lowStockCount }}</dd></div>
            <div><dt>{{ ___('Stock value') }}</dt><dd class="ui-ltr">{{ price_symbol_format($stockValue) }}</dd></div>
        </dl>
    </section>

    <form method="get" action="{{ route('inventory.ingredients.index') }}" class="ui-ing__context">
        <select name="store_id" class="ui-input" data-ui-do="submit" aria-label="{{ ___('Store') }}">
            <option value="">{{ ___('All stores') }}</option>
            @foreach ($stores as $r)
                <option value="{{ $r->id }}" @selected($storeId == $r->id)>{{ $r->title }}</option>
            @endforeach
        </select>
        @if($branches->count())
            <select name="branch_id" class="ui-input" data-ui-do="submit" aria-label="{{ ___('Branch') }}">
                <option value="">{{ ___('Shared / all branches') }}</option>
                @foreach ($branches as $b)
                    <option value="{{ $b->id }}" @selected($branchId == $b->id)>{{ $b->name }}</option>
                @endforeach
            </select>
        @endif
    </form>
    @if($branchId)
        <x-ui.banner>{{ ___('Showing on-hand for the selected branch. Stock changes here affect this branch only.') }}</x-ui.banner>
    @endif

    {{-- Add ingredient --}}
    <x-ui.card :title="___('Add ingredient')">
        <form method="post" action="{{ route('inventory.ingredients.store') }}" class="ui-ing__add">
            @csrf
            @if($storeId)
                <input type="hidden" name="store_id" value="{{ $storeId }}">
            @else
                <div class="ui-ing__field ui-ing__field--wide">
                    <label class="ui-label" for="ing-store">{{ ___('Store') }}</label>
                    <select id="ing-store" name="store_id" class="ui-input" required>
                        <option value="">{{ ___('Select…') }}</option>
                        @foreach ($stores as $r)
                            <option value="{{ $r->id }}">{{ $r->title }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div class="ui-ing__field ui-ing__field--wide">
                <label class="ui-label" for="ing-name">{{ ___('Name') }}</label>
                <input id="ing-name" class="ui-input" name="name" placeholder="{{ ___('Mozzarella') }}" required>
            </div>
            <div class="ui-ing__field">
                <label class="ui-label" for="ing-unit">{{ ___('Unit') }}</label>
                <select id="ing-unit" class="ui-input" name="unit">
                    @foreach(\App\Models\Ingredient::UNITS as $u)
                        <option value="{{ $u }}">{{ $u }}</option>
                    @endforeach
                </select>
            </div>
            <div class="ui-ing__field">
                <label class="ui-label" for="ing-open">{{ ___('Opening stock') }}</label>
                <input id="ing-open" class="ui-input" name="current_stock" type="number" step="0.001" min="0" value="0" inputmode="decimal">
            </div>
            <div class="ui-ing__field">
                <label class="ui-label" for="ing-par">{{ ___('Par level') }}</label>
                <input id="ing-par" class="ui-input" name="par_level" type="number" step="0.001" min="0" value="0" inputmode="decimal">
            </div>
            <div class="ui-ing__field">
                <label class="ui-label" for="ing-cost">{{ ___('Cost / unit') }}</label>
                <input id="ing-cost" class="ui-input" name="cost_per_unit" type="number" step="0.01" min="0" value="0" inputmode="decimal">
            </div>
            <div class="ui-ing__submit">
                <x-ui.button type="submit" icon="plus">{{ ___('Add') }}</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    {{-- Ingredient table --}}
    <x-ui.card :title="___('Ingredients')" variant="flush">
        <x-slot:actions><x-ui.tag>{{ $ingredients->count() }}</x-ui.tag></x-slot:actions>
        @if($ingredients->isEmpty())
            <x-ui.empty :title="___('No ingredients yet')" :body="___('Add your first raw item above.')" icon="products" />
        @else
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>{{ ___('Ingredient') }}</th>
                        <th class="ui-table__num">{{ ___('On hand') }}</th>
                        <th class="ui-table__num">{{ ___('Par') }}</th>
                        <th class="ui-table__num">{{ ___('Cost / unit') }}</th>
                        <th class="ui-table__num">{{ ___('Value') }}</th>
                        <th>{{ ___('Status') }}</th>
                        <th><span class="ui-visually-hidden">{{ ___('Actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($ingredients as $ing)
                        <tr>
                            <td data-primary>
                                <span class="ui-ing__name">
                                    <strong>{{ $ing->name }}</strong>
                                    <x-ui.tag>{{ $ing->unit }}</x-ui.tag>
                                </span>
                            </td>
                            <td class="ui-table__num" data-label="{{ ___('On hand') }}"><strong class="ui-ltr">{{ rtrim(rtrim(number_format($ing->current_stock, 3), '0'), '.') }}</strong></td>
                            <td class="ui-table__num" data-label="{{ ___('Par') }}"><span class="ui-ltr">{{ rtrim(rtrim(number_format($ing->par_level, 3), '0'), '.') }}</span></td>
                            <td class="ui-table__num" data-label="{{ ___('Cost / unit') }}"><span class="ui-ltr">{{ price_symbol_format($ing->cost_per_unit) }}</span></td>
                            <td class="ui-table__num" data-label="{{ ___('Value') }}"><span class="ui-ltr">{{ price_symbol_format($ing->stock_value) }}</span></td>
                            <td data-label="{{ ___('Status') }}">
                                @if(!$ing->active)
                                    <x-ui.pill>{{ ___('Inactive') }}</x-ui.pill>
                                @elseif($ing->is_low)
                                    <x-ui.pill tone="warning">{{ ___('Below par') }}</x-ui.pill>
                                @else
                                    <x-ui.pill tone="success">{{ ___('OK') }}</x-ui.pill>
                                @endif
                            </td>
                            <td class="ui-catalog__row-actions ui-ing__actions">
                                <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm"
                                    @click="openAdjust(@js(['id'=>$ing->id,'name'=>$ing->name,'unit'=>$ing->unit,'stock'=>(float)$ing->current_stock]))">
                                    {{ ___('Adjust stock') }}
                                </button>
                                <button type="button" class="ui-icon-btn" aria-label="{{ ___('Edit') }}" title="{{ ___('Edit') }}"
                                    @click="openEdit(@js(['id'=>$ing->id,'name'=>$ing->name,'unit'=>$ing->unit,'par_level'=>(float)$ing->par_level,'cost_per_unit'=>(float)$ing->cost_per_unit,'active'=>(bool)$ing->active]))">
                                    <x-ui.icon name="pencil" />
                                </button>
                                <a class="ui-icon-btn" href="{{ route('inventory.ingredients.history', $ing->id) }}" aria-label="{{ ___('History') }}" title="{{ ___('History') }}"><x-ui.icon name="clock" /></a>
                                @if(is_string($__transferButton ?? null))
                                    @include($__transferButton, ['ing' => $ing])
                                @endif
                                <form method="post" action="{{ route('inventory.ingredients.destroy', $ing->id) }}"
                                    data-confirm="{{ ___('Delete this ingredient? Its stock history is removed too.') }}" data-confirm-danger>
                                    @csrf @method('DELETE')
                                    <button type="submit" class="ui-icon-btn ui-menu-item__delete" aria-label="{{ ___('Delete') }}" title="{{ ___('Delete') }}"><x-ui.icon name="trash" /></button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-ui.card>

    {{-- Adjust dialog --}}
    <x-ui.sheet id="ing-adjust" :title="___('Adjust stock')" x-ref="adjustDialog">
        <form method="post" :action="adjustBase + '/' + adjust.id + '/adjust'" id="ing-adjust-form" class="ui-ing__sheet-form">
            @csrf
            @if($branchId)<input type="hidden" name="branch_id" value="{{ $branchId }}">@endif
            <p class="ui-ing__target" x-text="adjust.name"></p>
            <div>
                <label class="ui-label" for="ia-dir">{{ ___('Action') }}</label>
                <select id="ia-dir" class="ui-input" name="direction" x-model="adjust.direction">
                    <option value="in">{{ ___('Receive (add stock)') }}</option>
                    <option value="out">{{ ___('Use (remove stock)') }}</option>
                    <option value="waste">{{ ___('Waste / spoilage') }}</option>
                    <option value="set">{{ ___('Set exact on-hand') }}</option>
                </select>
            </div>
            <div>
                <label class="ui-label" for="ia-qty" x-text="adjust.direction === 'set' ? @js(___('New on-hand quantity')) : @js(___('Quantity'))"></label>
                <input id="ia-qty" class="ui-input" name="quantity" type="number" step="0.001" min="0" required inputmode="decimal">
            </div>
            <div x-show="adjust.direction === 'in'">
                <label class="ui-label" for="ia-cost">{{ ___('Unit cost (optional)') }}</label>
                <input id="ia-cost" class="ui-input" name="unit_cost" type="number" step="0.01" min="0" inputmode="decimal">
            </div>
            <div>
                <label class="ui-label" for="ia-reason">{{ ___('Reason (optional)') }}</label>
                <input id="ia-reason" class="ui-input" name="reason" maxlength="255">
            </div>
        </form>
        <x-slot:footer>
            <x-ui.button type="button" variant="secondary" @click="$refs.adjustDialog.close()">{{ ___('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="ing-adjust-form" icon="check">{{ ___('Apply') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.sheet>

    {{-- Edit dialog --}}
    <x-ui.sheet id="ing-edit" :title="___('Edit ingredient')" x-ref="editDialog">
        <form method="post" :action="adjustBase + '/' + edit.id" id="ing-edit-form" class="ui-ing__sheet-form">
            @csrf @method('PUT')
            <div>
                <label class="ui-label" for="ie-name">{{ ___('Name') }}</label>
                <input id="ie-name" class="ui-input" name="name" x-model="edit.name" required>
            </div>
            <div>
                <label class="ui-label" for="ie-unit">{{ ___('Unit') }}</label>
                <select id="ie-unit" class="ui-input" name="unit" x-model="edit.unit">
                    <template x-for="u in units" :key="u"><option :value="u" x-text="u"></option></template>
                </select>
            </div>
            <div>
                <label class="ui-label" for="ie-par">{{ ___('Par level') }}</label>
                <input id="ie-par" class="ui-input" name="par_level" type="number" step="0.001" min="0" x-model="edit.par_level" inputmode="decimal">
            </div>
            <div>
                <label class="ui-label" for="ie-cost">{{ ___('Cost / unit') }}</label>
                <input id="ie-cost" class="ui-input" name="cost_per_unit" type="number" step="0.01" min="0" x-model="edit.cost_per_unit" inputmode="decimal">
            </div>
            <label class="ui-check">
                <input type="checkbox" id="ing-active" name="active" value="1" :checked="edit.active" @change="edit.active = $event.target.checked">
                <span>{{ ___('Active') }}</span>
            </label>
        </form>
        <x-slot:footer>
            <x-ui.button type="button" variant="secondary" @click="$refs.editDialog.close()">{{ ___('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="ing-edit-form" icon="check">{{ ___('Save') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.sheet>

    {{-- Stock transfer between branches is a chain tool (ee): its sheet comes from the slot; absent without a licence. --}}
    @if(is_string($__transferSheet ?? null))
        @include($__transferSheet, ['branches' => $branches])
    @endif
</div>
@endsection

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
    window.linenIngredients = function (config) {
        return {
            adjustBase: config.adjustBase,
            deleteConfirm: config.deleteConfirm,
            units: config.units,
            adjust: { id: '', name: '', unit: '', stock: 0, direction: 'in' },
            edit: { id: '', name: '', unit: 'each', par_level: 0, cost_per_unit: 0, active: true },
            openAdjust: function (data) {
                this.adjust = { id: data.id, name: data.name, unit: data.unit, stock: data.stock, direction: 'in' };
                this.$refs.adjustDialog.showModal();
            },
            openEdit: function (data) {
                this.edit = { id: data.id, name: data.name, unit: data.unit, par_level: data.par_level, cost_per_unit: data.cost_per_unit, active: data.active };
                this.$refs.editDialog.showModal();
            }
        };
    };
</script>
@endpush
