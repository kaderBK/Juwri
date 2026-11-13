{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- POS-V2 menu column (T1-1: pos-chip row + pos-item photo-card grid, UI-KIT §3).
     Quick-add vs customize-sheet behaviour unchanged (add(it)). Search lives in the
     topbar (P0-4); T1-5 wires the live filtering. --}}
<div class="menu">
    <div class="pos-chips">
        <button type="button" class="pos-chip" :class="{on: activeCat === 'all'}" @click="activeCat='all'">{{ ___('All') }}</button>
        <template x-for="c in categories" :key="c.id">
            <button type="button" class="pos-chip" :class="{on: activeCat === c.id}" @click="activeCat=c.id" x-text="c.name"></button>
        </template>
        {{-- T6: combo meals live in their own chip (they are not a menu category). --}}
        <button type="button" class="pos-chip" x-show="hasCombos && hasCombos()" :class="{on: activeCat === 'combos'}" @click="activeCat='combos'">{{ ___('Combos') }}</button>
    </div>

    {{-- T7-5: loading skeleton + error/retry --}}
    <div class="pos-grid" x-show="menuLoading" x-cloak>
        <template x-for="n in 8" :key="n"><div class="pos-skel"></div></template>
    </div>
    <div class="pos-empty" x-show="menuError" x-cloak>
        <span class="ico">@include('staff.pos.partials.icon', ['name' => 'alert', 'size' => 40])</span>
        <div>{{ ___('Could not load the menu.') }}</div>
        <button type="button" class="pos-mini-apply" @click="load()">{{ ___('Retry') }}</button>
    </div>

    <div class="pos-grid" x-show="!menuLoading && !menuError">
        {{-- T6: combo tiles (server-priced). Shown under the All / Combos chips. --}}
        <template x-for="c in (comboTiles ? comboTiles() : [])" :key="'combo-' + c.id">
            <button type="button" class="pos-item is-combo" @click="openCombo(c)">
                <template x-if="c.image_url">
                    <img class="pos-item-thumb" :src="c.image_url" :alt="c.name" loading="lazy">
                </template>
                <template x-if="!c.image_url">
                    <span class="pos-item-thumb ph">@include('staff.pos.partials.icon', ['name' => 'utensils', 'size' => 34])</span>
                </template>
                <span class="pos-item-body">
                    <span class="pos-item-name" x-text="c.name"></span>
                    <span class="pos-item-price" x-text="money(c.price)"></span>
                    <span class="pos-badges">
                        <span class="pos-badge is-combo">{{ ___('Combo') }}</span>
                        <span class="pos-badge is-save" x-show="c.saving > 0" x-text="'{{ ___('You save') }} ' + money(c.saving)"></span>
                    </span>
                </span>
            </button>
        </template>
        <template x-for="it in visibleItems()" :key="it.id">
            <button type="button" class="pos-item" :class="{'is-sold': it.is_sold_out}" @click="add(it)">
                <template x-if="it.image">
                    <img class="pos-item-thumb" :src="it.image" :alt="it.name" loading="lazy">
                </template>
                <template x-if="!it.image">
                    <span class="pos-item-thumb ph">@include('staff.pos.partials.icon', ['name' => 'utensils', 'size' => 34])</span>
                </template>
                <span class="pos-item-body">
                    <span class="pos-item-name" x-text="it.name"></span>
                    <span class="pos-item-price"
                          x-text="(it.variants && it.variants.length ? '{{ ___('from') }} ' : '') + money(fromPrice(it))"></span>
                    <span class="pos-badges" x-show="hasMods(it) || it.is_sold_out || stockLeft(it) !== null">
                        <span class="pos-badge is-options" x-show="hasMods(it)">{{ ___('Options') }}</span>
                        <span class="pos-badge is-sold" x-show="it.is_sold_out">{{ ___('Sold out') }}</span>
                        {{-- OP-9: local stock view (the saved count minus sales not sent yet) --}}
                        <span class="pos-badge" :class="{'is-sold': stockLeft(it) !== null && stockLeft(it) <= 0}" x-show="stockLeft(it) !== null && !it.is_sold_out" x-text="stockLeft(it) + ' {{ ___('left') }}'"></span>
                    </span>
                </span>
            </button>
        </template>

        <div class="pos-empty" x-show="loaded && !allItems().length" x-cloak style="grid-column:1/-1">
            <span class="ico">@include('staff.pos.partials.icon', ['name' => 'utensils', 'size' => 40])</span>
            <div>{{ ___('No menu items for this :noun yet — add items in the menu manager.', ['noun' => store_noun($store ?? null, 'gen')]) }}</div>
        </div>
        <div class="pos-empty" x-show="loaded && allItems().length && !visibleItems().length" x-cloak style="grid-column:1/-1">
            <span class="ico">@include('staff.pos.partials.icon', ['name' => 'search', 'size' => 40])</span>
            <div>{{ ___('No items match your search.') }}</div>
        </div>
    </div>
</div>
