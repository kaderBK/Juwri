{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    rev/multi-vertical Lane D — D1: the service item form fields, shared by
    create.blade.php and edit.blade.php. Deliberately NO goods fields (no
    brand/barcode/weight/dimensions/variant grid) and NO food fields (no
    veg/nonveg, allergens, spice, nutrition, course/prep) — a service is
    priced per item or per kilogram and has neither stock nor variants
    (DECISIONS A9, D1: "no stock/variants-as-goods"). $item is null on create.
--}}
@php
    $item = $item ?? null;
    $unit = old('pricing_unit', $item?->pricing_unit ?: 'each');
@endphp

<div class="ui-form-grid">
    <div>
        <x-ui.field name="name" :label="___('Name')" required :value="old('name', $item?->getRawOriginal('name'))" />
    </div>
    <div>
        <x-ui.field name="category_id" type="select" :label="$vocab['category'] ?? ___('Category')"
            :options="['' => ___('— None —')] + $categories->pluck('name', 'id')->all()" :value="old('category_id', $item?->category_id)" />
    </div>

    <div class="ui-form-grid__full">
        <x-ui.field name="description" type="textarea" :label="___('Description')" :value="old('description', $item?->getRawOriginal('description'))" />
    </div>

    <div>
        <x-ui.field name="price" type="number" step="0.01" min="0" :label="___('Price')" required :value="old('price', $item?->price)" />
    </div>
    <div>
        <x-ui.field name="pricing_unit" type="select" :label="___('Priced per')"
            :options="['each' => ___('Item'), 'kg' => ___('Kilogram')]" :value="$unit" />
    </div>
    <div>
        <x-ui.field name="turnaround_hours" type="number" :label="___('Turnaround (hours)')"
            :help="___('How long this service takes, e.g. 24.')"
            :value="old('turnaround_hours', $item?->turnaround_hours)" />
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
