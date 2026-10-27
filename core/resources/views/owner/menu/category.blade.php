{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    $categoryMenus = $category->menus;
    $subcategories = $category->subcategories;
    $categoryItemCount = $categoryMenus->count() + $subcategories->flatMap->menus->count();
    $activeLang = $_COOKIE['Quick_user_lang_code'] ?? null;
    $restaurantDefaultLang = store_options($store->id, 'default_language');
    $catUntranslated = $activeLang
        && $restaurantDefaultLang
        && $activeLang !== $restaurantDefaultLang
        && empty($category->translations->{$activeLang}->title);
    // #34: AI-translated category name awaiting owner review.
    $catMachineTranslated = $activeLang
        && $restaurantDefaultLang
        && $activeLang !== $restaurantDefaultLang
        && !empty($category->translations->{$activeLang}->title)
        && !empty($category->translations->{$activeLang}->_machine);
@endphp

{{-- One menu category (owner UI kit). data-drag-id + the draggable header drive reordering. --}}
<article class="menu-category ui-menu-cat" data-id="{{ $category->id }}" data-drag-id="{{ $category->id }}" @if((string) $category->active === '0') data-hidden @endif>
    <header class="menu-category__head ui-menu-cat__head" draggable="true" @dragstart="dragStart($event, 'category')" @dragend="dragEnd($event, 'category')">
        <button type="button" class="menu-drag-handle ui-icon-btn ui-icon-btn--ghost ui-menu-cat__grip" aria-label="{{ ___('Drag to reorder') }}">
            <x-ui.icon name="grip" size="18" />
        </button>
        @if($category->image)
            <img class="menu-category__thumb ui-menu-cat__thumb" src="{{ $category->image_url }}" alt="{{ $category->name }}" loading="lazy">
        @endif
        <div class="ui-menu-cat__title">
            <h3 class="ui-menu-cat__name">{{ $category->name }}</h3>
            <span class="ui-menu-cat__meta">
                <span>{{ ___(':count items', ['count' => number_format($categoryItemCount)]) }}</span>
                @if((string) $category->active === '0')<x-ui.tag title="{{ ___('Hidden from the customer menu — show it again under Edit category') }}">{{ ___('Hidden') }}</x-ui.tag>@endif
                @if($catUntranslated)<x-ui.tag tone="warning">{{ ___('Untranslated') }}</x-ui.tag>@endif
                @if($catMachineTranslated)<x-ui.tag tone="accent" title="{{ ___('AI translation — review and confirm') }}">{{ ___('AI · review') }}</x-ui.tag>@endif
            </span>
        </div>
        <div class="menu-category__actions ui-menu-cat__actions">
            @if(!$reachedMenuLimit)
                <button type="button" class="ui-icon-btn" @click="openAddItem({{ $category->id }})" aria-label="{{ ___('Add Menu Item') }}" title="{{ ___('Add Menu Item') }}">
                    <x-ui.icon name="plus" />
                </button>
            @elseif(Route::has('subscription'))
                <a class="ui-icon-btn" href="{{ route('subscription') }}" aria-label="{{ ___('Upgrade plan') }}" title="{{ ___('Upgrade plan') }}">
                    <x-ui.icon name="sparkle" />
                </a>
            @endif
            <button type="button" class="ui-icon-btn ui-menu-item__delete" @click="deleteCategory({{ $category->id }})" aria-label="{{ ___('Delete Category') }}" title="{{ ___('Delete Category') }}">
                <x-ui.icon name="trash" />
            </button>
        </div>
    </header>

    <div class="menu-category__tools ui-menu-cat__tools">
        <details>
            <summary class="ui-chip">
                <x-ui.icon name="pencil" size="16" />
                <span>{{ ___('Edit Category') }}</span>
            </summary>
            <form method="post" action="{{ route('stores.updateCategory', $store->id) }}" class="ui-cluster ui-cluster--bottom menu-inline-form"
                  x-data="imagePickerField({ target: 'cat-{{ $category->id }}', storeId: {{ $store->id }}, folder: 'category', initial: @js($category->image), url: @js($category->image_url) })">
                @csrf
                <input type="hidden" name="id" value="{{ $category->id }}">
                <div class="edit-field">
                    <label class="ui-label" for="cat-name-{{ $category->id }}">{{ ___('Name') }}</label>
                    <input class="ui-input" id="cat-name-{{ $category->id }}" name="name" value="{{ $category->name }}" required>
                </div>
                {{-- HIDDEN CATEGORY: unchecked = the whole category (and its
                     items) stays off the customer menu while remaining fully
                     editable here. The audit command lands rescued items in an
                     inactive "Uncategorized" bucket with this exact flag. The
                     hidden 0 makes an UNCHECKED box post active=0 — without it
                     an unchecked box posts nothing and reads as "leave as-is". --}}
                <input type="hidden" name="active" value="0">
                <label class="ui-cluster ui-check">
                    <input type="checkbox" name="active" value="1"
                           @checked((string) $category->active !== '0')>
                    <span class="ui-nowrap">{{ ___('Show on menu') }}</span>
                </label>
                <input type="hidden" name="image" :value="filename">
                <div class="ui-cluster image-pick">
                    <img class="image-pick__thumb" x-show="url" :src="url" alt="" x-cloak style="width:40px;height:40px;object-fit:cover;">
                    <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" @click="choose()">{{ ___('Image') }}</button>
                    <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" x-show="filename" @click="clear()" x-cloak>{{ ___('Remove') }}</button>
                </div>
                {{-- PARITY-RUN T5: category-level availability. Schedules a whole
                     day-part menu ("Breakfast 07:00–11:00") instead of stamping
                     the same window onto every item inside it. A closed category
                     closes its items, on the restaurant's own clock. --}}
                @php
                    $catDays = array_filter(explode(',', (string) $category->available_days));
                    $catTypes = array_filter(explode(',', (string) $category->available_order_types));
                @endphp
                <div class="ui-w-full ui-bordered">
                    <div class="ui-small ui-strong">{{ ___('Scheduled availability (optional)') }}</div>
                    <div class="ui-cluster ui-cluster--bottom">
                        <div class="edit-field">
                            <label class="ui-label" for="cat-available-from-{{ $category->id }}">{{ ___('From') }}</label>
                            <input class="ui-input" id="cat-available-from-{{ $category->id }}" type="time" name="available_from"
                                   value="{{ $category->available_from ? substr($category->available_from, 0, 5) : '' }}">
                        </div>
                        <div class="edit-field">
                            <label class="ui-label" for="cat-available-to-{{ $category->id }}">{{ ___('To') }}</label>
                            <input class="ui-input" id="cat-available-to-{{ $category->id }}" type="time" name="available_to"
                                   value="{{ $category->available_to ? substr($category->available_to, 0, 5) : '' }}">
                        </div>
                    </div>
                    <div class="ui-cluster">
                        @foreach(['mon'=>___('Mon'),'tue'=>___('Tue'),'wed'=>___('Wed'),'thu'=>___('Thu'),'fri'=>___('Fri'),'sat'=>___('Sat'),'sun'=>___('Sun')] as $dCode => $dLabel)
                            <label class="ui-check">
                                <input type="checkbox" name="available_days[]"
                                       value="{{ $dCode }}" @checked(in_array($dCode, $catDays, true))>
                                <span>{{ $dLabel }}</span>
                            </label>
                        @endforeach
                    </div>
                    <div class="ui-cluster">
                        @foreach(['on-table'=>___('Dine-in'),'takeaway'=>___('Takeaway'),'delivery'=>___('Delivery'),'room_service'=>___('Room service')] as $otCode => $otLabel)
                            <label class="ui-check">
                                <input type="checkbox" name="available_order_types[]"
                                       value="{{ $otCode }}" @checked(in_array($otCode, $catTypes, true))>
                                <span>{{ $otLabel }}</span>
                            </label>
                        @endforeach
                    </div>
                    <div class="ui-field__help">{{ ___('No day selected means every day.') }}</div>
                </div>
                <x-ui.button type="submit" size="sm" icon="check">{{ ___('Save') }}</x-ui.button>
            </form>
        </details>

        @if(!$reachedCategoryLimit)
            <details>
                <summary class="ui-chip">
                    <x-ui.icon name="plus" size="16" />
                    <span>{{ ___('Add Sub Category') }}</span>
                </summary>
                <form method="post" action="{{ route('stores.addSubCategory', $store->id) }}" class="ui-cluster ui-cluster--bottom menu-inline-form">
                    @csrf
                    <input type="hidden" name="parent" value="{{ $category->id }}">
                    <div class="edit-field">
                        <label class="ui-label" for="sub-name-new-{{ $category->id }}">{{ ___('Name') }}</label>
                        <input class="ui-input" id="sub-name-new-{{ $category->id }}" name="name" placeholder="{{ ___('Desserts') }}" required>
                    </div>
                    <x-ui.button type="submit" size="sm" icon="plus">{{ ___('Add Sub Category') }}</x-ui.button>
                </form>
            </details>
        @endif
    </div>

    <div class="ui-menu-cat__items">
        @include('owner.menu.partials.items', ['menus' => $categoryMenus, 'store' => $store])
    </div>

    @foreach($subcategories as $subcategory)
        <div class="menu-subcategory ui-menu-sub" data-id="{{ $subcategory->id }}">
            <header class="ui-menu-sub__head">
                <div class="ui-menu-cat__title">
                    <span class="ui-overline">{{ ___('Sub category') }}</span>
                    <h4 class="ui-menu-sub__name">{{ $subcategory->name }}</h4>
                    <span class="ui-menu-cat__meta">{{ ___(':count items', ['count' => number_format($subcategory->menus->count())]) }}</span>
                </div>
                <div class="menu-category__actions ui-menu-cat__actions">
                    @if(!$reachedMenuLimit)
                        <button type="button" class="ui-icon-btn" @click="openAddItem({{ $subcategory->id }})" aria-label="{{ ___('Add Menu Item') }}" title="{{ ___('Add Menu Item') }}">
                            <x-ui.icon name="plus" />
                        </button>
                    @endif
                    <button type="button" class="ui-icon-btn ui-menu-item__delete" @click="deleteCategory({{ $subcategory->id }})" aria-label="{{ ___('Delete Sub Category') }}" title="{{ ___('Delete Sub Category') }}">
                        <x-ui.icon name="trash" />
                    </button>
                </div>
            </header>

            <details class="menu-subcategory__edit ui-menu-cat__tools">
                <summary class="ui-chip">
                    <x-ui.icon name="pencil" size="16" />
                    <span>{{ ___('Edit Sub Category') }}</span>
                </summary>
                <form method="post" action="{{ route('stores.updateSubCategory', $store->id) }}" class="ui-cluster ui-cluster--bottom menu-inline-form">
                    @csrf
                    <input type="hidden" name="id" value="{{ $subcategory->id }}">
                    <input type="hidden" name="parent" value="{{ $category->id }}">
                    <div class="edit-field">
                        <label class="ui-label" for="sub-name-{{ $subcategory->id }}">{{ ___('Name') }}</label>
                        <input class="ui-input" id="sub-name-{{ $subcategory->id }}" name="name" value="{{ $subcategory->name }}" required>
                    </div>
                    <x-ui.button type="submit" size="sm" icon="check">{{ ___('Save') }}</x-ui.button>
                </form>
            </details>

            @include('owner.menu.partials.items', ['menus' => $subcategory->menus, 'store' => $store])
        </div>
    @endforeach
</article>
