{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Customize sheet: pick a priced variant, add extras, set qty + a kitchen note.
     (P0-2 extract; T6 adds the choice-group OPTIONS section.) --}}
<div class="sheet" x-show="sheet.open" x-cloak @keydown.escape.window="closeSheet()">
    <div class="card2" @click.outside="closeSheet()">
        <header>
            <template x-if="sheet.item && sheet.item.image"><img :src="sheet.item.image" :alt="sheet.item.name"></template>
            <h3 x-text="sheet.item ? sheet.item.name : ''"></h3>
            <span class="sp" style="flex:1"></span>
            <button class="closebtn" @click="closeSheet()">&times;</button>
        </header>
        <div class="scroll">
            {{-- Variant (single-select; the first / base option is priced at item price) --}}
            <div class="grp" x-show="sheet.item && sheet.item.variants && sheet.item.variants.length">
                <h4>{{ ___('Choose one') }}</h4>
                <label class="opt" :class="{on: sheet.variation === null}">
                    <input type="radio" name="pos-var" :checked="sheet.variation === null" @change="sheet.variation = null">
                    <span class="lbl">{{ ___('Standard') }}</span>
                    <span class="op" x-text="money(sheet.item ? sheet.item.price : 0)"></span>
                </label>
                <template x-for="v in (sheet.item ? sheet.item.variants : [])" :key="v.id">
                    <label class="opt" :class="{on: sheet.variation === v.id}">
                        <input type="radio" name="pos-var" :checked="sheet.variation === v.id" @change="sheet.variation = v.id">
                        <span class="lbl" x-text="variantLabel(v)"></span>
                        <span class="op" x-text="money(v.price)"></span>
                    </label>
                </template>
            </div>
            {{-- T6-3: choice-groups (variant-option) — radio when max_select=1, else
                 checkboxes; REQUIRED / pick-n–m helper; violating min/max disables Add. --}}
            <template x-for="g in optGroups(sheet.item)" :key="g.id">
                <div class="grp">
                    <h4>
                        <span x-text="g.title"></span>
                        <span class="pos-opt-req" x-show="g.required">{{ ___('Required') }}</span>
                        <span class="pos-opt-help" x-show="optGroupHelper(g)" x-text="optGroupHelper(g)"></span>
                    </h4>
                    <template x-for="o in g.options" :key="o.label">
                        <label class="opt" :class="{on: optSelected(g, o.label)}">
                            <input :type="optSingle(g) ? 'radio' : 'checkbox'" :name="'pos-optg-' + g.id"
                                   :checked="optSelected(g, o.label)" @change="toggleOption(g, o.label)">
                            <span class="lbl" x-text="o.label"></span>
                            <span class="op" x-show="o.price > 0" x-text="'+' + money(o.price)"></span>
                        </label>
                    </template>
                </div>
            </template>

            {{-- Extras (multi-select) --}}
            <div class="grp" x-show="sheet.item && sheet.item.extras && sheet.item.extras.length">
                <h4>{{ ___('Add extras') }}</h4>
                <template x-for="e in (sheet.item ? sheet.item.extras : [])" :key="e.id">
                    <label class="opt" :class="{on: sheet.extras.includes(e.id)}">
                        <input type="checkbox" :checked="sheet.extras.includes(e.id)" @change="toggleExtra(e.id)">
                        <template x-if="e.image"><img class="opt-thumb" :src="e.image" :alt="e.title" loading="lazy"></template>
                        <span class="lbl" x-text="e.title"></span>
                        <span class="op" x-text="'+' + money(e.price)"></span>
                    </label>
                </template>
            </div>
            {{-- Note --}}
            <div class="grp">
                <h4>{{ ___('Note') }}</h4>
                <textarea x-model="sheet.note" maxlength="255" placeholder="{{ ___('e.g. no onions, extra spicy') }}"></textarea>
            </div>
        </div>
        <footer>
            <div class="qtybox">
                <button @click="sheet.qty = Math.max(1, sheet.qty - 1)">−</button>
                <span x-text="sheet.qty"></span>
                <button @click="sheet.qty++">+</button>
            </div>
            <button class="addbtn" @click="confirmSheet()" :disabled="!sheetOptionsValid()">
                <span>{{ ___('Add') }}</span> · <span x-text="money(sheetUnitPrice() * sheet.qty)"></span>
            </button>
        </footer>
    </div>
</div>
