{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-ui.data-table> — server-side list for the superadmin (assets/owner-ui/admin.js).

    source     JSON endpoint (Juwri\Core\Support\Admin\DataTable answer)
    columns    list of column specs, one per row cell:
                 ['label' => ___('Name')]                 sortable column
                 ['label' => ___('Country'), 'sort' => false]
                 ['check' => true]                        the "select all" box (bulk delete)
                 ['label' => '', 'sort' => false]         e.g. the edit button column
    order-col / order-dir   initial sort (default 0 / desc)
    reorder    URL: rows drag by their handle, posts position[]
    delete     URL: bulk delete of ticked rows, posts ids[]
    search     false hides the search box
  Slot `filters`: extra fields in the bar; mark each with data-ui-dt-filter (name → query param).

    <x-ui.data-table :source="route('admin.taxes.index')" :delete="route('admin.taxes.delete')"
        :columns="[['label' => ___('Tax')], ['label' => '', 'sort' => false], ['check' => true]]" />
--}}
@props(['source', 'columns' => [], 'orderCol' => 0, 'orderDir' => 'desc', 'reorder' => null, 'delete' => null, 'search' => true, 'length' => 25])
<div {{ $attributes->class('ui-dt') }} data-ui-dt data-source="{{ $source }}" data-order-col="{{ (int) $orderCol }}"
     data-order-dir="{{ $orderDir === 'asc' ? 'asc' : 'desc' }}" data-length="{{ (int) $length }}"
     @if ($reorder) data-reorder="{{ $reorder }}" @endif @if ($delete) data-delete="{{ $delete }}" @endif>
    <div class="ui-dt__bar">
        @if ($search)
            <label class="ui-dt__search">
                <span class="ui-visually-hidden">{{ ___('Search') }}</span>
                <input type="search" class="ui-input" data-ui-dt-search placeholder="{{ ___('Search') }}" autocomplete="off">
            </label>
        @endif
        {{ $filters ?? '' }}
        <label class="ui-dt__sizer">
            <span>{{ ___('Rows per page') }}</span>
            <select class="ui-input ui-input--sm ui-dt__size" data-ui-dt-size>
                @foreach ([10, 25, 50, 100] as $__n)
                    <option value="{{ $__n }}" @selected($__n === (int) $length)>{{ $__n }}</option>
                @endforeach
            </select>
        </label>
        @if ($delete)
            <div class="ui-dt__bulk" role="status">
                <span data-ui-dt-count></span>
                <button type="button" class="ui-btn ui-btn--danger ui-btn--sm" data-ui-dt-delete>
                    <span class="ui-btn__spinner" aria-hidden="true"></span>
                    <span class="ui-btn__icon"><x-ui.icon name="trash" size="16" /></span>{{ ___('Delete') }}
                </button>
            </div>
        @endif
    </div>
    <div class="ui-dt__scroll">
        <table class="ui-table ui-dt__table">
            <thead>
                <tr>
                    @foreach ($columns as $__c)
                        @if (! empty($__c['check']))
                            <th class="ui-dt__narrow"><input type="checkbox" data-ui-dt-all aria-label="{{ ___('Select all') }}"></th>
                        @elseif (($__c['sort'] ?? true) && ($__c['label'] ?? '') !== '')
                            <th @isset($__c['class']) class="{{ $__c['class'] }}" @endisset><button type="button" class="ui-dt__sort">{{ $__c['label'] }}</button></th>
                        @else
                            <th class="{{ $__c['class'] ?? (($__c['label'] ?? '') === '' ? 'ui-dt__narrow' : '') }}">@if (($__c['label'] ?? '') !== ''){{ $__c['label'] }}@else<span class="ui-visually-hidden">{{ ___('Actions') }}</span>@endif</th>
                        @endif
                    @endforeach
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
    <div class="ui-dt__foot">
        <span data-ui-dt-info aria-live="polite"></span>
        <nav class="ui-dt__pages" data-ui-dt-pages aria-label="{{ ___('Pagination') }}"></nav>
    </div>
</div>
