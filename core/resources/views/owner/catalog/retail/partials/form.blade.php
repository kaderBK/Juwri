{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    rev/multi-vertical Lane A4 — the retail item form fields, shared by
    create.blade.php and edit.blade.php. Deliberately NO food fields (no
    veg/nonveg, allergens, spice, nutrition, course/prep) — see
    RetailItemController's docblock. $item is null on create.
--}}
@php
    $item = $item ?? null;
    $dims = $item?->dimensions ?? [];
    $attrs = $item?->attributes ?? [];
@endphp

<div class="ui-form-grid">
    <div>
        <x-ui.field name="name" :label="___('Name')" required :value="old('name', $item?->getRawOriginal('name'))" />
    </div>
    <div>
        <x-ui.field name="category_id" type="select" :label="$vocab['category'] ?? ___('Category')"
            :options="['' => ___('— None —')] + $categories->pluck('name', 'id')->all()" :value="old('category_id', $item?->category_id)" />
    </div>
    <div>
        <x-ui.field name="brand_id" type="select" :label="___('Brand')"
            :options="['' => ___('— None —')] + $brands->pluck('name', 'id')->all()" :value="old('brand_id', $item?->brand_id)" />
    </div>

    <div class="ui-form-grid__full">
        <x-ui.field name="description" type="textarea" :label="___('Description')" :value="old('description', $item?->getRawOriginal('description'))" />
    </div>

    <div>
        <x-ui.field name="price" type="number" step="0.01" min="0" :label="___('Price')" required :value="old('price', $item?->price)" />
    </div>
    <div>
        <x-ui.field name="cost" type="number" step="0.01" min="0" :label="___('Cost')" :value="old('cost', $item?->cost)" />
    </div>
    <div>
        <x-ui.field name="barcode" :label="___('Barcode / SKU')" :value="old('barcode', $item?->barcode)" />
    </div>
    <div>
        <x-ui.field name="weight" type="number" step="0.001" min="0" :label="___('Weight (kg)')" :value="old('weight', $item?->weight)" />
    </div>

    <div class="ui-form-grid__full">
        <label class="ui-label">{{ ___('Dimensions') }}</label>
        <div class="ui-form-grid">
            <div><input type="number" step="0.01" name="dimensions_length" class="ui-input" placeholder="{{ ___('Length') }}" value="{{ old('dimensions_length', $dims['length'] ?? '') }}"></div>
            <div><input type="number" step="0.01" name="dimensions_width" class="ui-input" placeholder="{{ ___('Width') }}" value="{{ old('dimensions_width', $dims['width'] ?? '') }}"></div>
            <div><input type="number" step="0.01" name="dimensions_height" class="ui-input" placeholder="{{ ___('Height') }}" value="{{ old('dimensions_height', $dims['height'] ?? '') }}"></div>
            <div>
                <select name="dimensions_unit" class="ui-input">
                    @foreach(['cm', 'in', 'm'] as $unit)
                        <option value="{{ $unit }}" @selected(old('dimensions_unit', $dims['unit'] ?? 'cm') === $unit)>{{ $unit }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    <div class="ui-form-grid__full">
        <label class="ui-label">{{ ___('Attributes') }} <span class="ui-itemform__hint">({{ ___('e.g. fabric, origin, ingredients') }})</span></label>
        @php $attrPairs = old('attr_key') ? array_combine(old('attr_key'), old('attr_value')) : $attrs; $attrPairs = $attrPairs ?: []; @endphp
        @for($i = 0; $i < 6; $i++)
            @php $pairKeys = array_keys($attrPairs); $k = $pairKeys[$i] ?? ''; $v = $k !== '' ? $attrPairs[$k] : ''; @endphp
            <div class="ui-form-grid ui-itemform__attr">
                <div><input type="text" name="attr_key[]" class="ui-input ui-input--sm" placeholder="{{ ___('Attribute') }}" value="{{ $k }}"></div>
                <div><input type="text" name="attr_value[]" class="ui-input ui-input--sm" placeholder="{{ ___('Value') }}" value="{{ $v }}"></div>
            </div>
        @endfor
    </div>

    <div>
        <div class="ui-check">
            <input type="checkbox" role="switch" name="track_inventory" id="track_inventory" value="1" @checked(old('track_inventory', $item?->track_inventory))>
            <label for="track_inventory">{{ ___('Track inventory') }}</label>
        </div>
    </div>
    <div>
        <x-ui.field name="stock_qty" type="number" :label="___('Stock quantity')" :help="___('Only used when this item has no variants.')" :value="old('stock_qty', $item?->stock_qty)" />
    </div>

    <div>
        <label class="ui-label">{{ ___('Image') }}</label>
        <input type="file" name="image_file" class="ui-input" accept="image/png,image/jpeg,image/webp">
        @if($item?->image && $item->image !== 'default.png')
            <img src="{{ media_url('storage/menu/', $item->image, $item->store_id) }}" alt="" class="ui-itemform__thumb">
        @endif
    </div>

    <div class="ui-form-grid__full">
        <div class="ui-check">
            <input type="checkbox" role="switch" name="active" id="active" value="1" @checked(old('active', $item ? $item->active : true))>
            <label for="active">{{ ___('Active (visible on the storefront)') }}</label>
        </div>
    </div>
</div>
