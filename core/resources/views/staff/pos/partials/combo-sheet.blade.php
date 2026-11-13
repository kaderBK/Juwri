{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Combo configurator: one section per slot (radio when max=1, else checkboxes),
     min/max enforced, qty + note. The server (ComboService::resolve, already wired
     into pos.orders.store) re-prices; comboUnitPrice() here is display only. --}}
<div class="sheet" x-show="comboSheet.open" x-cloak @keydown.escape.window="closeCombo()">
    <div class="card2" @click.outside="closeCombo()">
        <header>
            <template x-if="comboSheet.combo && comboSheet.combo.image_url"><img :src="comboSheet.combo.image_url" :alt="comboSheet.combo.name"></template>
            <h3 x-text="comboSheet.combo ? comboSheet.combo.name : ''"></h3>
            <span class="sp" style="flex:1"></span>
            <button class="closebtn" @click="closeCombo()">&times;</button>
        </header>
        <div class="scroll">
            <template x-for="s in (comboSheet.combo ? comboSheet.combo.slots : [])" :key="s.id">
                <div class="grp">
                    <h4>
                        <span x-text="s.title"></span>
                        <span class="pos-opt-req" x-show="Number(s.min) > 0">{{ ___('Required') }}</span>
                        <span class="pos-opt-help" x-show="comboSlotHelper(s)" x-text="comboSlotHelper(s)"></span>
                    </h4>
                    <template x-for="o in s.options" :key="o.id">
                        <label class="opt" :class="{on: comboIsPicked(s.id, o.id)}">
                            <input :type="comboSingle(s) ? 'radio' : 'checkbox'" :name="'pos-combo-' + s.id"
                                   :checked="comboIsPicked(s.id, o.id)" @change="comboToggle(s, o.id)">
                            <template x-if="o.image"><img class="opt-thumb" :src="o.image" :alt="o.name" loading="lazy"></template>
                            <span class="lbl" x-text="o.name"></span>
                            <span class="op" x-show="o.upcharge > 0" x-text="'+' + money(o.upcharge)"></span>
                        </label>
                    </template>
                </div>
            </template>
            {{-- Note --}}
            <div class="grp">
                <h4>{{ ___('Note') }}</h4>
                <textarea x-model="comboSheet.note" maxlength="255" placeholder="{{ ___('e.g. no onions, extra spicy') }}"></textarea>
            </div>
        </div>
        <footer>
            <div class="qtybox">
                <button @click="comboSheet.qty = Math.max(1, comboSheet.qty - 1)">−</button>
                <span x-text="comboSheet.qty"></span>
                <button @click="comboSheet.qty++">+</button>
            </div>
            <button class="addbtn" @click="confirmCombo()" :disabled="!comboValid()">
                <span>{{ ___('Add') }}</span> · <span x-text="money(comboUnitPrice() * comboSheet.qty)"></span>
            </button>
        </footer>
    </div>
</div>
