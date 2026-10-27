{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    $searchText = strtolower(trim($menu->name.' '.$menu->description));
    $image = $menu->image ?: 'default.png';
    $description = \Illuminate\Support\Str::limit(strip_tags((string) $menu->description), 96);
    $activeLang = $_COOKIE['Quick_user_lang_code'] ?? null;
    $restaurantDefaultLang = store_options($store->id, 'default_language');
    $isUntranslated = $activeLang
        && $restaurantDefaultLang
        && $activeLang !== $restaurantDefaultLang
        && empty($menu->translations->{$activeLang}->title);
    // #34: AI-translated field awaiting owner review.
    $isMachineTranslated = $activeLang
        && $restaurantDefaultLang
        && $activeLang !== $restaurantDefaultLang
        && !empty($menu->translations->{$activeLang}->title)
        && !empty($menu->translations->{$activeLang}->_machine);
@endphp
@php
    $tags = [];
    if ($menu->is_sold_out) $tags[] = ['danger', ___('Sold Out'), null];
    if ($menu->is_popular) $tags[] = ['accent', ___('Popular'), null];
    if ($menu->is_daily_special) $tags[] = ['warning', ___('Special'), null];
    if ($menu->is_vegan) $tags[] = ['success', ___('Vegan'), null];
    if ($menu->is_halal) $tags[] = ['neutral', ___('Halal'), null];
    if ($menu->is_gluten_free) $tags[] = ['neutral', ___('Gluten Free'), null];
@endphp

{{-- One menu item row (owner UI kit). The page script relies on the id, data-drag-id,
     draggable handlers and the Alpine bindings — not on classes. --}}
<article id="menu-item-{{ $menu->id }}" class="menu-row ui-menu-item" data-search="{{ $searchText }}" data-drag-id="{{ $menu->id }}" draggable="true" @dragstart="dragStart($event, 'item')" @dragend="dragEnd($event, 'item')" x-show="matches(@js($searchText))" x-transition.opacity :class="selected.includes({{ $menu->id }}) && 'is-selected'" @if($menu->active != '1') data-inactive @endif>
    <span class="menu-drag-handle ui-menu-item__grip" aria-hidden="true"><x-ui.icon name="grip" size="16" /></span>
    <label class="menu-row__select ui-menu-item__select">
        <input type="checkbox" :checked="selected.includes({{ $menu->id }})" @change="toggleSelect({{ $menu->id }})" aria-label="{{ ___('Select item') }}">
    </label>
    <button type="button" class="menu-row__image-btn ui-menu-item__image"
            @click="quickImage({ id: {{ $menu->id }} })" title="{{ ___('Change image') }}" aria-label="{{ ___('Change image') }}">
        <img id="menu-row-img-{{ $menu->id }}" class="menu-row__image" src="{{ asset('storage/menu/'.$image) }}" alt="{{ $menu->name }}" loading="lazy">
        <span class="ui-menu-item__image-edit" aria-hidden="true"><x-ui.icon name="pencil" size="12" /></span>
    </button>
    <div class="menu-row__main ui-menu-item__main">
        <h4 class="ui-menu-item__name" x-data="{ editing: false, current: @js($menu->name), draft: '' }">
            <span x-show="!editing" x-text="current" @click="draft = current; editing = true; $nextTick(() => { $refs.nameInput.focus(); $refs.nameInput.select(); })"
                  class="quick-edit-text" role="button" tabindex="0" @keydown.enter.prevent="draft = current; editing = true; $nextTick(() => $refs.nameInput.focus())"
                  title="{{ ___('Click to edit') }}">{{ $menu->name }}</span>
            <input x-show="editing" x-cloak x-ref="nameInput" type="text" x-model="draft" class="ui-menu-item__input"
                   @click.stop @keydown.enter.prevent="$refs.nameInput.blur()" @keydown.escape.prevent="editing = false"
                   @blur="editing = false; var v = draft.trim(); if (v && v !== current) { quickSaveField({{ $menu->id }}, 'name', v).then(function (r) { if (r.ok) current = r.name; }); }">
        </h4>
        @if($description)
            <p class="ui-menu-item__desc">{{ $description }}</p>
        @endif
        <div class="menu-row__badges ui-menu-item__tags">
            <span class="ui-menu-item__diet" data-diet="{{ $menu->type === 'veg' ? 'veg' : 'nonveg' }}" title="{{ $menu->type === 'veg' ? ___('Veg') : ___('Non Veg') }}">{{ $menu->type === 'veg' ? ___('Veg') : ___('Non Veg') }}</span>
            <x-ui.pill :tone="$menu->active == '1' ? 'success' : 'neutral'">{{ $menu->active == '1' ? ___('Available') : ___('Unavailable') }}</x-ui.pill>
            @foreach($tags as [$tone, $label])
                <x-ui.tag :tone="$tone">{{ $label }}</x-ui.tag>
            @endforeach
            @if(!empty($menu->allergies) && ($allergies ?? collect())->isNotEmpty())
                @php $allergenMap = $allergies->keyBy('id'); @endphp
                @foreach(array_filter(array_map('trim', explode(',', $menu->allergies))) as $aid)
                    @if($allergenMap->has($aid))
                        <x-ui.tag tone="danger" title="{{ ___('Contains allergen') }}">{{ $allergenMap[$aid]->title }}</x-ui.tag>
                    @endif
                @endforeach
            @endif
            @if($isUntranslated)
                <x-ui.tag tone="warning">{{ ___('Untranslated') }}</x-ui.tag>
            @endif
            @if($isMachineTranslated)
                <x-ui.tag tone="accent" title="{{ ___('AI translation — review and confirm') }}">{{ ___('AI · review') }}</x-ui.tag>
            @endif
        </div>
    </div>
    <div class="menu-row__meta ui-menu-item__meta">
        <strong class="ui-menu-item__price" x-data="{ editing: false, current: @js(price_symbol_format($menu->price)), raw: @js((string) $menu->price), draft: '' }">
            <span x-show="!editing" x-text="current" @click="draft = raw; editing = true; $nextTick(() => { $refs.priceInput.focus(); $refs.priceInput.select(); })"
                  class="quick-edit-text" role="button" tabindex="0" @keydown.enter.prevent="draft = raw; editing = true; $nextTick(() => $refs.priceInput.focus())"
                  title="{{ ___('Click to edit') }}">{{ price_symbol_format($menu->price) }}</span>
            <input x-show="editing" x-cloak x-ref="priceInput" type="number" min="0" step="0.01" x-model="draft" class="ui-menu-item__input ui-menu-item__input--price"
                   @click.stop @keydown.enter.prevent="$refs.priceInput.blur()" @keydown.escape.prevent="editing = false"
                   @blur="editing = false; var v = String(draft).trim(); if (v !== '' && v !== raw) { quickSaveField({{ $menu->id }}, 'price', v).then(function (r) { if (r.ok) { current = r.price_formatted; raw = v; } }); }">
        </strong>
        @if(!empty($menu->calories))
            <span class="ui-menu-item__sub">{{ number_format($menu->calories) }} {{ ___('kcal') }}</span>
        @endif
        @if(!empty($menu->available_from) && !empty($menu->available_to))
            <span class="ui-menu-item__sub ui-ltr">{{ $menu->available_from }} - {{ $menu->available_to }}</span>
        @endif
    </div>
    <div class="menu-row__actions ui-menu-item__actions">
            @php
                $editPayload = [
                    'id' => $menu->id,
                    'category_id' => $menu->category_id,
                    'name' => $menu->name,
                    'price' => $menu->price,
                    'cost' => $menu->cost,
                    'description' => $menu->description,
                    'type' => $menu->type,
                    'spice_level' => $menu->spice_level,
                    'is_vegan' => $menu->is_vegan,
                    'is_halal' => $menu->is_halal,
                    'is_gluten_free' => $menu->is_gluten_free,
                    'is_popular' => $menu->is_popular,
                    'is_daily_special' => $menu->is_daily_special,
                    'is_sold_out' => $menu->is_sold_out,
                    'active' => $menu->active,
                    'calories' => $menu->calories,
                    'tax_rate' => $menu->tax_rate,
                    'tax_category' => $menu->tax_category,
                    'protein' => $menu->protein,
                    'carbs' => $menu->carbs,
                    'fat' => $menu->fat,
                    'available_from' => $menu->available_from,
                    'available_to' => $menu->available_to,
                    'available_days' => $menu->available_days,
                    'available_order_types' => $menu->available_order_types, // T5
                    'allergies' => $menu->allergies,
                    'image' => ($menu->image && $menu->image !== 'default.png') ? $menu->image : '',
                    'image_url' => ($menu->image && $menu->image !== 'default.png') ? media_url('storage/menu/', $menu->image, $store->id) : '',
                    'track_inventory' => $menu->track_inventory,
                    'stock_qty' => $menu->stock_qty,
                    'low_stock_threshold' => $menu->low_stock_threshold,
                    'course' => $menu->course,
                    'prep_minutes' => $menu->prep_minutes,
                    'gallery' => \Juwri\Core\Support\Views\OwnerOpsViewHelpers::menuGalleryPayload($menu->gallery_images, (int) $store->id),
                ];
            @endphp
        <button type="button" class="ui-icon-btn" @click='openEditItem(@json($editPayload))' aria-label="{{ ___('Edit') }}" title="{{ ___('Edit') }}">
            <x-ui.icon name="pencil" />
        </button>
        @if(Route::has('stores.menuItemExtras'))
            <a class="ui-icon-btn" href="{{ route('stores.menuItemExtras', ['store' => $store->id, 'menu' => $menu->id]) }}" aria-label="{{ ___('Extra & Variants') }}" title="{{ ___('Extras & variants') }}">
                <x-ui.icon name="filter" />
            </a>
        @endif
        <button type="button" class="menu-row__danger ui-icon-btn ui-menu-item__delete" @click="deleteItem({{ $menu->id }})" aria-label="{{ ___('Delete') }}" title="{{ ___('Delete') }}">
            <x-ui.icon name="trash" />
        </button>
    </div>
</article>
