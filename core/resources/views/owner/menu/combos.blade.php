{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Combo meals'))
@section('page_title', ___('Combo meals'))
@section('page_sub', ___('Bundle items into a meal deal sold at one price.'))

@section('content')
@php $currency = cur_isolate($currency); @endphp
<div class="ui-scope ui-combos"
     x-data="comboBuilder({
        token: @js(csrf_token()),
        saveUrl: @js($storeId ? route('combos.save') : ''),
        reorderUrl: @js(route('combos.reorder')),
        storeId: {{ (int) $storeId }},
        combos: @js($combosPayload),
        items: @js($items),
        currency: @js($currency),
        currencyPos: @js((string) currency_pos_effective($storeId)),
        text: {
            failed: @js(___('Unable to save.')),
            confirmDelete: @js(___('Delete this combo?')),
            slotDefault: @js(___('Choose an item')),
        }
     })">

    <form method="get" action="{{ route('combos.index') }}">
        @if($stores->count() > 1)
            <select name="store_id" class="ui-input ui-combos__store" data-ui-do="submit" aria-label="{{ ___('Select store…') }}">
                <option value="">{{ ___('Select store…') }}</option>
                @foreach ($stores as $r)
                    <option value="{{ $r->id }}" @selected($storeId == $r->id)>{{ $r->title }}</option>
                @endforeach
            </select>
        @else
            <input type="hidden" name="store_id" value="{{ $storeId }}">
        @endif
    </form>

    @if(!$storeId)
        <div class="ui-card">
            <x-ui.empty icon="store" :title="___('Pick a store to manage its combo meals.')" />
        </div>
    @elseif($items->isEmpty())
        {{-- Empty state that names the real blocker: a combo is made of menu items. --}}
        <div class="ui-card">
            <x-ui.empty icon="products" :title="___('Add some menu items first — a combo is built from items you already sell.')">
                @if(Route::has('stores.menu'))
                    <x-ui.button :href="route('stores.menu', $storeId)" icon="plus">{{ ___('Edit menu') }}</x-ui.button>
                @endif
            </x-ui.empty>
        </div>
    @else
        {{-- List-level error surface: the dialog's alert is invisible while the
             dialog is closed, so a failed inline toggle had nowhere to report. --}}
        <div class="ui-banner" data-tone="danger" role="alert" x-show="error" x-cloak>
            <span class="ui-banner__icon"><x-ui.icon name="error" size="18" /></span>
            <div class="ui-banner__body" x-text="error"></div>
        </div>

        <section class="ui-card">
            <div class="ui-card__head">
                <h2 class="ui-card__title">{{ ___('Combo meals') }} <x-ui.tag class="ui-ltr">{{ $combos->count() }}</x-ui.tag></h2>
                @if($combos->isNotEmpty())
                <div class="ui-card__actions">
                    <x-ui.button size="sm" icon="plus" @click="newCombo()">{{ ___('New combo') }}</x-ui.button>
                </div>
                @endif
            </div>

            @if($combos->isEmpty())
                <x-ui.empty icon="gift" :title="___('No combo meals yet. Create one and it appears in its own section on your menu.')">
                    <x-ui.button icon="plus" @click="newCombo()">{{ ___('New combo') }}</x-ui.button>
                </x-ui.empty>
            @else
                <p class="ui-combos__muted">{{ ___('Drag to reorder. Only active combos show on the storefront.') }}</p>
                <div class="ui-list ui-combos__list" @dragover.prevent="dragOver($event)" @drop.prevent>
                    @foreach ($combos as $c)
                        <article class="ui-row ui-combos__row" data-drag-id="{{ $c->id }}" draggable="true"
                                 @dragstart="dragStart($event)" @dragend="dragEnd($event)">
                            <span class="ui-combos__handle" aria-hidden="true">
                                <x-ui.icon name="grip" size="18" />
                            </span>
                            @if($c->image)
                                <img class="ui-combos__img" src="{{ media_url('storage/menu/', $c->image, $storeId) }}" alt=""
                                     loading="lazy">
                            @endif
                            <div class="ui-row__body">
                                <span class="ui-row__title">{{ $c->name }}</span>
                                <span class="ui-combos__sub">
                                    {{ $c->slots->count() }} {{ ___('choice groups') }}
                                    @php $floor = $c->alaCarteFloor(); $saving = max(0, $floor - (float) $c->price); @endphp
                                    @if($saving > 0)
                                        {{-- class="ui-text-success" is a test contract (ComboBuilderTest); kit colour via .ui-combos__sub. --}}
                                        · <span class="ui-text-success">{{ ___('Saves') }} <span class="ui-ltr">{{ currency_pos_effective($storeId) === 1 ? $currency.number_format($saving, currency_decimals(store_options($storeId, 'currency_code') ?: null)) : number_format($saving, currency_decimals(store_options($storeId, 'currency_code') ?: null)).$currency }}</span></span>
                                    @endif
                                </span>
                            </div>
                            <div class="ui-combos__actions">
                                <strong class="ui-combos__price ui-ltr">{{ currency_pos_effective($storeId) === 1 ? $currency.$c->price : $c->price.$currency }}</strong>
                                @if($c->active)
                                    <x-ui.pill tone="success">{{ ___('Active') }}</x-ui.pill>
                                @else
                                    <x-ui.pill>{{ ___('Hidden') }}</x-ui.pill>
                                @endif
                                {{-- Toggle posts to combos.toggle (JSON: {success, active}) and reloads.
                                     Double-quoted @click on purpose: a single-quoted @click holding
                                     @js output breaks Alpine silently in owner blades. --}}
                                <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm"
                                        :disabled="busy"
                                        @click="toggleCombo(@js(route('combos.toggle', $c->id)))">
                                    {{ $c->active ? ___('Disable') : ___('Enable') }}
                                </button>
                                <button type="button" class="ui-icon-btn" @click="editCombo({{ (int) $c->id }})" aria-label="{{ ___('Edit') }}" title="{{ ___('Edit') }}"><x-ui.icon name="pencil" /></button>
                                <form method="post" action="{{ route('combos.destroy', $c->id) }}"
                                      data-confirm="{{ ___('Delete this combo?') }}" data-confirm-danger>
                                    @csrf @method('DELETE')
                                    <button type="submit" class="ui-icon-btn ui-menu-item__delete" aria-label="{{ ___('Delete') }}" title="{{ ___('Delete') }}"><x-ui.icon name="trash" /></button>
                                </form>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- ── Builder dialog (kit sheet: bottom sheet on phones) ───────────── --}}
        <dialog class="ui-sheet ui-combos__sheet" x-ref="comboDialog" aria-labelledby="combo-dialog-title">
            <div class="ui-sheet__handle" aria-hidden="true"></div>
            <form @submit.prevent="save()"
                  x-data="imagePickerField({ target: 'combo-img', storeId: {{ $storeId }}, folder: 'menu' })"
                  x-effect="draft.image = filename">
                <div class="ui-combos__sheet-head">
                    <h2 class="ui-sheet__title" id="combo-dialog-title" x-text="draft.id ? @js(___('Edit combo')) : @js(___('New combo'))"></h2>
                    <button type="button" class="ui-icon-btn ui-icon-btn--ghost" @click="$refs.comboDialog.close()" aria-label="{{ ___('Close') }}"><x-ui.icon name="x" /></button>
                </div>

                <div class="ui-sheet__body ui-combos__body">
                    <div class="ui-combos__basics">
                        <div class="ui-combos__name">
                            <label class="ui-label" for="combo-name">{{ ___('Combo name') }}</label>
                            <input class="ui-input" id="combo-name" x-model="draft.name" required maxlength="190"
                                   placeholder="{{ ___('Family Meal') }}">
                        </div>
                        <div>
                            <label class="ui-label" for="combo-price">{{ ___('Bundle price') }}</label>
                            <div class="ui-input-group">
                                <span class="ui-input-group__text" x-text="currency"></span>
                                <input class="ui-input" id="combo-price" type="number" step="0.001" min="0" x-model.number="draft.price" required>
                            </div>
                        </div>
                        <div class="ui-combos__full">
                            <label class="ui-label" for="combo-desc">{{ ___('Description') }}</label>
                            <textarea class="ui-input" id="combo-desc" rows="2" x-model="draft.description" maxlength="2000"></textarea>
                        </div>
                        <div class="ui-combos__full ui-combos__media">
                            <img class="ui-combos__thumb" x-show="url" :src="url" alt="" x-cloak>
                            <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" @click="choose()"><span class="ui-btn__icon"><x-ui.icon name="upload" /></span>{{ ___('Choose image') }}</button>
                            <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" x-show="filename" @click="clear()" x-cloak>{{ ___('Remove') }}</button>
                            <label class="ui-check ui-combos__active">
                                <input type="checkbox" x-model="draft.active">
                                <span>{{ ___('Active') }}</span>
                            </label>
                        </div>
                    </div>

                    {{-- Choice groups --}}
                    <template x-for="(slot, si) in draft.slots" :key="si">
                        <div class="ui-combos__slot">
                            <div class="ui-combos__slot-head">
                                <input class="ui-input ui-input--sm ui-combos__slot-title"
                                       x-model="slot.title" required maxlength="190"
                                       placeholder="{{ ___('Choose your drink') }}" aria-label="{{ ___('Choose your drink') }}">
                                <span class="ui-combos__range">
                                    <span>{{ ___('Pick') }}</span>
                                    <input class="ui-input ui-input--sm ui-combos__num" type="number"
                                           min="0" max="20" x-model.number="slot.min" aria-label="{{ ___('Minimum picks') }}">
                                    <span>{{ ___('to') }}</span>
                                    <input class="ui-input ui-input--sm ui-combos__num" type="number"
                                           min="0" max="20" x-model.number="slot.max" aria-label="{{ ___('Maximum picks') }}">
                                </span>
                                <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm ui-menu-item__delete ui-combos__slot-remove" @click="removeSlot(si)">{{ ___('Remove') }}</button>
                            </div>

                            <template x-for="(opt, oi) in slot.options" :key="oi">
                                <div class="ui-combos__opt">
                                    <select class="ui-input ui-input--sm ui-combos__opt-item"
                                            x-model.number="opt.menu_id" @change="opt.variant_id = null" aria-label="{{ ___('Choose an item') }}">
                                        <template x-for="it in items" :key="it.id">
                                            <option :value="it.id" x-text="it.name"></option>
                                        </template>
                                    </select>
                                    <select class="ui-input ui-input--sm ui-combos__opt-variant"
                                            x-model.number="opt.variant_id"
                                            x-show="variantsFor(opt.menu_id).length" x-cloak aria-label="{{ ___('No variant') }}">
                                        <option :value="null">{{ ___('No variant') }}</option>
                                        <template x-for="v in variantsFor(opt.menu_id)" :key="v.id">
                                            <option :value="v.id" x-text="v.name"></option>
                                        </template>
                                    </select>
                                    <div class="ui-input-group ui-input-group--sm ui-combos__opt-up">
                                        <span class="ui-input-group__text">{{ ___('Extra charge') }}</span>
                                        <input class="ui-input" type="number" step="0.001" min="0" x-model.number="opt.upcharge" aria-label="{{ ___('Extra charge') }}">
                                    </div>
                                    <button type="button" class="ui-icon-btn ui-menu-item__delete" @click="removeOption(si, oi)" aria-label="{{ ___('Remove') }}"><x-ui.icon name="x" /></button>
                                </div>
                            </template>

                            <div>
                                <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" @click="addOption(si)">
                                    <span class="ui-btn__icon"><x-ui.icon name="plus" /></span>{{ ___('Add option') }}
                                </button>
                            </div>
                        </div>
                    </template>

                    <div>
                        <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" @click="addSlot()">
                            <span class="ui-btn__icon"><x-ui.icon name="plus" /></span>{{ ___('Add choice group') }}
                        </button>
                    </div>

                    {{-- Sum-vs-bundle preview. Display only — the server re-prices every order. --}}
                    <dl class="ui-combos__preview">
                        <div><dt>{{ ___('Bought separately') }}</dt><dd class="ui-ltr" x-text="currencyPos==='1' ? currency + alaCarte().toFixed(2) : alaCarte().toFixed(2) + currency"></dd></div>
                        <div><dt>{{ ___('Bundle price') }}</dt><dd class="ui-ltr" x-text="currencyPos==='1' ? currency + (Number(draft.price) || 0).toFixed(2) : (Number(draft.price) || 0).toFixed(2) + currency"></dd></div>
                        <div :class="saving() > 0 ? 'is-saving' : ''"><dt>{{ ___('Saves') }}</dt><dd class="ui-ltr" x-text="currencyPos==='1' ? currency + saving().toFixed(2) : saving().toFixed(2) + currency"></dd></div>
                    </dl>

                    <div class="ui-banner" data-tone="danger" role="alert" x-show="error" x-cloak>
                        <span class="ui-banner__icon"><x-ui.icon name="error" size="18" /></span>
                        <div class="ui-banner__body" x-text="error"></div>
                    </div>
                </div>

                <div class="ui-sheet__foot">
                    <x-ui.button variant="secondary" @click="$refs.comboDialog.close()">{{ ___('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit" icon="check" x-bind:disabled="busy">{{ ___('Save') }}</x-ui.button>
                </div>
            </form>
        </dialog>

        @include('owner.media._picker')
    @endif
</div>

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
window.comboBuilder = function (config) {
    return {
        items: config.items || [],
        currency: config.currency || '',
        // '1' = symbol before the amount (currency_pos_effective); the price preview reads it.
        currencyPos: String(config.currencyPos || ''),
        text: config.text,
        busy: false,
        error: '',
        draft: { id: null, name: '', description: '', image: '', price: 0, active: true, slots: [] },
        _drag: null, _container: null,

        variantsFor(menuId) {
            var it = this.items.find(function (i) { return i.id === Number(menuId); });
            return (it && it.variants) || [];
        },
        basePriceFor(menuId, variantId) {
            var it = this.items.find(function (i) { return i.id === Number(menuId); });
            if (!it) return 0;
            if (variantId) {
                var v = (it.variants || []).find(function (x) { return x.id === Number(variantId); });
                // A variant price REPLACES the base price — it is never additive.
                if (v) return Number(v.price) || 0;
            }
            return Number(it.price) || 0;
        },
        /* Mirrors Combo::alaCarteFloor() — the N cheapest options per slot, so the
           saving shown to the owner is never overstated. Display only. */
        alaCarte() {
            var self = this, sum = 0;
            (this.draft.slots || []).forEach(function (slot) {
                var prices = (slot.options || []).map(function (o) {
                    return self.basePriceFor(o.menu_id, o.variant_id) + (Number(o.upcharge) || 0);
                }).sort(function (a, b) { return a - b; });
                var take = Math.max(1, Number(slot.min) || 0);
                sum += prices.slice(0, take).reduce(function (a, b) { return a + b; }, 0);
            });
            return sum;
        },
        saving() { return Math.max(0, this.alaCarte() - (Number(this.draft.price) || 0)); },

        newCombo() {
            this.error = '';
            this.draft = { id: null, name: '', description: '', image: '', price: 0, active: true, slots: [] };
            this.addSlot();
            this.$refs.comboDialog.showModal();
        },
        editCombo(id) {
            this.error = '';
            var src = (config.combos || []).find(function (c) { return c.id === id; });
            if (!src) return;
            this.draft = {
                id: src.id, name: src.name, description: src.description, image: src.image || '',
                price: src.price, active: !!src.active,
                slots: (src.slots || []).map(function (s) {
                    return {
                        title: s.title, min: s.min, max: s.max,
                        options: (s.options || []).map(function (o) {
                            return { menu_id: o.menu_id, variant_id: o.variant_id, upcharge: o.upcharge };
                        }),
                    };
                }),
            };
            if (!this.draft.slots.length) this.addSlot();
            this.$refs.comboDialog.showModal();
        },
        addSlot() {
            this.draft.slots.push({ title: this.text.slotDefault, min: 1, max: 1, options: [] });
            this.addOption(this.draft.slots.length - 1);
        },
        removeSlot(i) { this.draft.slots.splice(i, 1); },
        addOption(si) {
            var first = this.items.length ? this.items[0].id : null;
            this.draft.slots[si].options.push({ menu_id: first, variant_id: null, upcharge: 0 });
        },
        removeOption(si, oi) { this.draft.slots[si].options.splice(oi, 1); },

        /* Flip active/hidden without opening the builder. The endpoint is a
           bare POST (no body) that returns {success, active}; reload so the
           badge, the storefront preview and the ordering all stay in step. */
        toggleCombo(url) {
            var self = this;
            this.error = '';
            this.busy = true;
            fetch(url, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': config.token,
                    'X-Requested-With': 'XMLHttpRequest',
                },
            }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
              .then(function (res) {
                  self.busy = false;
                  if (!res.ok || !res.j.success) { self.error = self.text.failed; return; }
                  window.location.reload();
              }).catch(function () { self.busy = false; self.error = self.text.failed; });
        },

        save() {
            var self = this;
            this.error = '';
            if (!this.draft.slots.length) { this.error = this.text.failed; return; }
            this.busy = true;
            fetch(config.saveUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': config.token,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    id: this.draft.id,
                    store_id: config.storeId,
                    name: this.draft.name,
                    description: this.draft.description,
                    image: this.draft.image || null,
                    price: this.draft.price,
                    active: this.draft.active ? 1 : 0,
                    slots: this.draft.slots.map(function (s) {
                        return {
                            title: s.title, min: s.min, max: s.max,
                            options: s.options.map(function (o) {
                                return { menu_id: o.menu_id, variant_id: o.variant_id || null, upcharge: o.upcharge || 0, active: 1 };
                            }),
                        };
                    }),
                }),
            }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
              .then(function (res) {
                  self.busy = false;
                  if (!res.ok || !res.j.success) {
                      var msg = res.j.message || self.text.failed;
                      if (res.j.errors) { msg = Object.values(res.j.errors).flat().join(' '); }
                      self.error = msg;
                      return;
                  }
                  window.location.reload();
              }).catch(function () { self.busy = false; self.error = self.text.failed; });
        },

        dragStart(e) {
            this._drag = e.target.closest('[data-drag-id]');
            this._container = this._drag.parentNode;
            this._drag.classList.add('is-dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', '');
        },
        dragOver(e) {
            const t = e.target.closest('[data-drag-id]');
            if (!t || t === this._drag || t.parentNode !== this._container) return;
            const r = t.getBoundingClientRect();
            this._container.insertBefore(this._drag, (e.clientY < r.top + r.height / 2) ? t : t.nextSibling);
        },
        dragEnd() {
            if (!this._drag) return;
            this._drag.classList.remove('is-dragging');
            const ids = Array.from(this._container.querySelectorAll(':scope > [data-drag-id]')).map(el => el.getAttribute('data-drag-id'));
            const fd = new FormData(); ids.forEach(id => fd.append('position[]', id));
            fetch(config.reorderUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': config.token, 'X-Requested-With': 'XMLHttpRequest' }, body: fd }).catch(() => {});
            this._drag = null; this._container = null;
        },
    };
};
</script>
@endpush
@endsection
