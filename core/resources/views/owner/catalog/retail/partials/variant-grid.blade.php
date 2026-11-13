{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    rev/multi-vertical Lane A2 — the "variant grid" editor (WORK-ITEMS A2).
    $item and $variants are passed in from edit.blade.php.
--}}
<x-ui.card :title="___('Variants')" class="ui-itemform__variants">
    <x-slot:actions><x-ui.tag>{{ $variants->count() }}</x-ui.tag></x-slot:actions>

    <p class="ui-itemform__hint">
        {{ ___('Generate a size × color (or any two-axis) matrix — each combination becomes its own row with its own price, SKU and stock.') }}
    </p>

    <form method="POST" action="{{ route('retail.products.variants.generate', [$store->id, $item->id]) }}" class="ui-form-grid ui-itemform__gen">
        @csrf
        <div>
            <label class="ui-label ui-small">{{ ___('Axis 1 name') }}</label>
            <input type="text" name="axis_name[]" class="ui-input ui-input--sm" placeholder="Size" value="Size">
        </div>
        <div>
            <label class="ui-label ui-small">{{ ___('Axis 1 values (comma-separated)') }}</label>
            <input type="text" name="axis_values[]" class="ui-input ui-input--sm" placeholder="S, M, L">
        </div>
        <div>
            <label class="ui-label ui-small">{{ ___('Axis 2 name (optional)') }}</label>
            <input type="text" name="axis_name[]" class="ui-input ui-input--sm" placeholder="Color" value="Color">
        </div>
        <div>
            <label class="ui-label ui-small">{{ ___('Axis 2 values (comma-separated)') }}</label>
            <input type="text" name="axis_values[]" class="ui-input ui-input--sm" placeholder="{{ ___('Red, Blue, Green') }}">
        </div>
        <div>
            <x-ui.button type="submit" size="sm" icon="plus" class="ui-w-full">{{ ___('Generate') }}</x-ui.button>
        </div>
    </form>

    @if($variants->isNotEmpty())
        <form method="POST" action="{{ route('retail.products.variants.bulkUpdate', [$store->id, $item->id]) }}">
            @csrf
            <div>
                <table class="ui-table ui-itemform__vtable">
                    <thead>
                        <tr>
                            <th>{{ ___('Combination') }}</th>
                            <th>{{ ___('Price') }}</th>
                            <th>{{ ___('Was price') }}</th>
                            <th>{{ ___('SKU') }}</th>
                            <th>{{ ___('Stock') }}</th>
                            <th>{{ ___('Barcode') }}</th>
                            <th>{{ ___('Active') }}</th>
                            <th><span class="ui-visually-hidden">{{ ___('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($variants as $variant)
                            <tr>
                                <td data-primary><strong>{{ $variant->axes_label }}</strong></td>
                                <td data-label="{{ ___('Price') }}"><input type="number" step="0.01" name="variants[{{ $variant->id }}][price]" class="ui-input ui-input--sm" value="{{ $variant->price }}"></td>
                                @php($wasCurrency = strtoupper(store_currency((int) $store->id)))
                                <td data-label="{{ ___('Was price') }}"><input type="number" step="any" min="0" name="variants[{{ $variant->id }}][compare_at]" class="ui-input ui-input--sm" value="{{ $variant->compare_at_minor !== null ? \Juwri\Core\Money\Money::majorFromMinor((int) $variant->compare_at_minor, $wasCurrency) : '' }}" aria-label="{{ ___('Was price') }}"></td>
                                <td data-label="{{ ___('SKU') }}"><input type="text" name="variants[{{ $variant->id }}][sku]" class="ui-input ui-input--sm" value="{{ $variant->sku }}"></td>
                                <td data-label="{{ ___('Stock') }}"><input type="number" name="variants[{{ $variant->id }}][stock_qty]" class="ui-input ui-input--sm" value="{{ $variant->stock_qty }}"></td>
                                <td data-label="{{ ___('Barcode') }}"><input type="text" name="variants[{{ $variant->id }}][barcode]" class="ui-input ui-input--sm" value="{{ $variant->barcode }}"></td>
                                <td data-label="{{ ___('Active') }}"><span class="ui-check"><input type="checkbox" role="switch" name="variants[{{ $variant->id }}][active]" value="1" @checked($variant->active) aria-label="{{ ___('Active') }}"></span></td>
                                <td class="ui-catalog__row-actions">
                                    <button type="submit" form="delete-variant-{{ $variant->id }}" class="ui-icon-btn ui-menu-item__delete" aria-label="{{ ___('Delete') }}" title="{{ ___('Delete') }}"><x-ui.icon name="trash" /></button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="ui-itemform__vsave"><x-ui.button type="submit" icon="check">{{ ___('Save variants') }}</x-ui.button></div>
        </form>

        @foreach($variants as $variant)
            <form id="delete-variant-{{ $variant->id }}" method="POST" action="{{ route('retail.products.variants.destroy', [$store->id, $item->id, $variant->id]) }}" class="ui-hidden" data-confirm="{{ ___('Delete this variant?') }}" data-confirm-danger>
                @csrf @method('DELETE')
            </form>
        @endforeach
    @else
        <x-ui.empty :title="___('No records found.')" icon="list" />
    @endif
</x-ui.card>
