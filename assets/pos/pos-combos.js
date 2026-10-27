// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* ==========================================================================
   POS-V2 T6 — combo tile + configurator. Lets a cashier ring up a combo meal.

   Registers on window.PosModules; the core factory composes it onto the
   register instance (parallel-track pattern, like pos-hold / pos-merge).

   The SERVER owns every price. pos.orders.store already accepts a `combos[*]`
   payload and runs it through ComboService::resolve(), which re-derives
   unit_price = combo.price + Σ picked-option upcharge from the owner's rows and
   ignores anything the client sends. So the cart line's `unitPrice` here is
   DISPLAY ONLY; the money on the receipt is the server's. Combos load once at
   boot (there are few of them).
   ========================================================================== */
window.PosModules = window.PosModules || {};

window.PosModules.combos = {
    combos: [],
    combosLoading: false,
    combosError: false,
    // configurator sheet: `picks` maps slot_id -> [option_id, ...]
    comboSheet: { open: false, combo: null, picks: {}, note: '', qty: 1 },

    // Loaded from GET pos/combos (ComboService::sellable -> payload). Called from
    // core boot() via `if (this.initCombos) this.initCombos()`.
    async initCombos() {
        const url = (window.PosBoot.routes || {}).combos;
        if (!url) return;
        this.combosLoading = true;
        this.combosError = false;
        try {
            const r = await fetch(this.posUrl(url), { headers: { Accept: 'application/json' } });
            const d = r.ok ? await r.json().catch(() => ({})) : {};
            this.combos = Array.isArray(d.combos) ? d.combos : [];
        } catch (e) {
            this.combosError = true;
        } finally {
            this.combosLoading = false;
        }
    },

    hasCombos() { return (this.combos || []).length > 0; },

    // combos matching the topbar search box (name / description), like visibleItems().
    visibleCombos() {
        const q = (this.search || '').trim().toLowerCase();
        if (!q) return this.combos;
        return this.combos.filter(c => (c.name + ' ' + (c.description || '')).toLowerCase().includes(q));
    },

    // Tiles to show in the grid: only under the "All" or "Combos" chip.
    comboTiles() {
        if (!this.hasCombos()) return [];
        if (this.activeCat !== 'all' && this.activeCat !== 'combos') return [];
        return this.visibleCombos();
    },

    openCombo(c) {
        // Pre-select nothing: a required single-pick slot is a deliberate cashier choice.
        this.comboSheet = { open: true, combo: c, picks: {}, note: '', qty: 1 };
    },
    closeCombo() { this.comboSheet.open = false; },

    comboSingle(slot) { return Number(slot.max) === 1; },              // radio vs checkbox
    comboEffMax(slot) { const m = Number(slot.max); return m > 0 ? m : Infinity; }, // 0 = unlimited
    comboPicked(slotId) { return this.comboSheet.picks[slotId] || []; },
    comboIsPicked(slotId, optId) { return this.comboPicked(slotId).indexOf(optId) !== -1; },

    comboToggle(slot, optId) {
        const cur = this.comboPicked(slot.id).slice();
        const at = cur.indexOf(optId);
        if (this.comboSingle(slot)) {
            // Radio: tap-again clears an optional slot; otherwise replace.
            this.comboSheet.picks[slot.id] = (at !== -1) ? [] : [optId];
            return;
        }
        if (at !== -1) { cur.splice(at, 1); }
        else if (cur.length < this.comboEffMax(slot)) { cur.push(optId); }
        this.comboSheet.picks[slot.id] = cur;
    },

    comboSlotValid(slot) {
        const n = this.comboPicked(slot.id).length;
        return n >= (Number(slot.min) || 0) && n <= this.comboEffMax(slot);
    },
    comboValid() {
        const c = this.comboSheet.combo;
        return !!c && (c.slots || []).every(s => this.comboSlotValid(s));
    },
    // Mirrors pos-core optGroupHelper (reuses the same translated strings).
    comboSlotHelper(slot) {
        const T = (window.PosBoot && window.PosBoot.i18n) || {};
        const min = Number(slot.min) || 0, max = Number(slot.max) || 0;
        if (min === 0 && max === 0) return '';
        if (min === max) return (T.pick || 'Pick') + ' ' + min;
        if (min > 0 && max > 0) return (T.pick || 'Pick') + ' ' + min + '–' + max;
        if (min > 0) return (T.atLeast || 'Pick at least') + ' ' + min;
        return (T.upTo || 'Pick up to') + ' ' + max;
    },

    comboOptName(slot, optId) {
        const o = (slot.options || []).find(x => x.id === optId);
        return o ? o.name : '';
    },
    // DISPLAY total only — the server re-derives this in ComboService::resolve().
    comboUnitPrice() {
        const c = this.comboSheet.combo;
        if (!c) return 0;
        let p = Number(c.price) || 0;
        for (const s of (c.slots || [])) {
            for (const optId of this.comboPicked(s.id)) {
                const o = (s.options || []).find(x => x.id === optId);
                if (o) p += Number(o.upcharge) || 0;
            }
        }
        return p;
    },

    confirmCombo() {
        if (!this.comboValid()) return;
        const c = this.comboSheet.combo;
        const picks = [];       // -> combos[*].picks, what ComboService::resolve consumes
        const optionLines = []; // reuse the item-line `options` shape so the cart renders picks
        for (const s of (c.slots || [])) {
            const ids = this.comboPicked(s.id);
            if (!ids.length) continue;
            picks.push({ slot_id: s.id, option_ids: ids.slice() });
            optionLines.push({
                group_id: s.id,
                title: s.title,
                selected: ids.map(id => ({ option: this.comboOptName(s, id) })),
            });
        }
        const note = (this.comboSheet.note || '').trim();
        const qty = this.comboSheet.qty;
        const sig = 'combo:' + c.id + '|'
            + picks.map(p => p.slot_id + ':' + p.option_ids.slice().sort().join('+')).join(';')
            + '|' + note;
        // `combo_id` flags this as a combo line for payload(); item-line fields are
        // stubbed so the shared cart template renders it. editLine() no-ops on it
        // (allItems() won't find a combo id), so a combo line is remove/qty only.
        const line = {
            sig, combo: true, combo_id: c.id, name: c.name,
            picks, note, qty,
            variationLabel: '', extras: [], options: optionLines,
            unitPrice: this.comboUnitPrice(),
        };
        const existing = this.cart.find(l => l.sig === sig);
        if (existing) { existing.qty += qty; } else { this.cart.push(line); }
        this.comboSheet.open = false;
    },
};
