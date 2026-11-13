// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* ==========================================================================
   PARITY-RUN T4 — table merge + order transfer. T4 zone.

   Registers on window.PosModules; the register factory in pos-core.js composes
   it over the core state (same contract as pos-approvals.js / pos-print.js —
   pos-core.js itself is NOT a T4 zone and gains no edits).

   Two flows live here:

     MERGE     pick 2+ tables on the Select Table screen → the first pick keeps
               the bill (the "primary"), the rest link to it. The server moves
               the secondaries' OPEN ORDERS onto the primary; nothing is created
               and nothing is re-priced, so every figure shown below comes back
               from the server rather than being summed in the browser.

     TRANSFER  move ONE open order to another table from the order detail panel.

   Every mutation uses a plain fetch, deliberately NOT the core `post()` helper:
   that helper parks failed writes in the offline outbox for later replay, and a
   merge replayed against a floor that has since changed is worse than a merge
   that simply failed and told the cashier so.
   ========================================================================== */
window.PosModules = window.PosModules || {};

window.PosModules.merge = {
    mergeEnabled: false,
    mergeGroups: [],        // server-shaped: {id, primary_table_id, table_ids, label, seats, order_count, total}
    mergeMode: false,       // multi-select armed on the Select Table screen
    mergeSelection: [],     // table ids, in tap order — [0] is the primary
    mergeDialog: false,
    mergeBusy: false,
    mergeError: '',

    transferOrder: null,    // the order being moved (detail-panel object)
    transferBusy: false,
    transferError: '',

    _mergeBooted: false,

    // Booted from an x-init on the Select Table panel, so pos-core.js keeps no
    // knowledge of this module.
    initMerge() {
        if (this._mergeBooted) return;
        this._mergeBooted = true;
        this.loadMerges();
        // $watch is an Alpine magic on the component scope; guarded so a future
        // shell change that inits this outside a component cannot throw.
        if (typeof this.$watch === 'function') {
            this.$watch('activePanel', (v) => { if (v === 'tables') this.loadMerges(); });
        }
    },

    async loadMerges() {
        const url = (window.PosBoot.routes || {}).tablesMerges;
        if (!url) return;
        try {
            const r = await fetch(this.posUrl(url), { headers: { Accept: 'application/json' } });
            const j = r.ok ? await r.json().catch(() => null) : null;
            this.mergeEnabled = !!(j && j.enabled);
            this.mergeGroups = (j && j.groups) || [];
        } catch (e) {
            // A dead network must never blank an already-shown floor.
        }
    },

    /* ── lookups the tables screen renders from ─────────────────────────── */

    mergeGroupFor(t) {
        if (!t) return null;
        const id = Number(t.merge_group_id || 0);
        if (!id) return null;
        return this.mergeGroups.find((g) => Number(g.id) === id) || null;
    },
    isMergePrimary(t) { return !!(t && t.merge_is_primary); },
    isMergeSecondary(t) { return !!(t && t.merge_group_id && !t.merge_is_primary); },
    mergeSelected(t) { return t ? this.mergeSelection.indexOf(t.id) !== -1 : false; },
    // 1-based badge so the cashier can see WHICH table keeps the bill.
    mergeSelectionIndex(t) { return t ? this.mergeSelection.indexOf(t.id) + 1 : 0; },

    mergeTableName(id) {
        const t = (this.tablesData || []).find((x) => Number(x.id) === Number(id));
        return t ? t.name : '#' + id;
    },
    mergePrimaryName() {
        return this.mergeSelection.length ? this.mergeTableName(this.mergeSelection[0]) : '';
    },
    mergeSecondaryNames() {
        return this.mergeSelection.slice(1).map((id) => this.mergeTableName(id)).join(' · ');
    },

    /* ── merge mode ─────────────────────────────────────────────────────── */

    toggleMergeMode() {
        this.mergeMode = !this.mergeMode;
        this.mergeSelection = [];
        this.mergeError = '';
    },

    // Called from the tile click handler before the normal select/assign flow.
    // Returns true when merge mode consumed the tap.
    mergeTapped(t) {
        if (!this.mergeMode || !t) return false;
        const T = window.PosBoot.i18n || {};
        if (this.isMergeSecondary(t)) {
            this.showToast(T.mergeAlready || 'This table is already part of a merged party.', 'warn');
            return true;
        }
        const i = this.mergeSelection.indexOf(t.id);
        if (i === -1) this.mergeSelection.push(t.id);
        else this.mergeSelection.splice(i, 1);
        return true;
    },

    /**
     * Outside merge mode, tapping a LINKED secondary must open the party's bill
     * on the primary — its own orders moved there. Without this the tile would
     * read as vacant and the cashier could seat a walk-in on top of a live party.
     * Returns true when it consumed the tap.
     */
    mergeSecondaryTapped(t) {
        if (this.mergeMode || !this.isMergeSecondary(t)) return false;
        const T = window.PosBoot.i18n || {};
        const g = this.mergeGroupFor(t);
        const primary = g && (this.tablesData || []).find((x) => Number(x.id) === Number(g.primary_table_id));
        if (primary) {
            this.showToast((T.mergedBillOn || 'This table is merged — the bill is on') + ' ' + primary.name, 'info');
            this.selectTable(primary);
        } else {
            this.showToast(T.mergeAlready || 'This table is already part of a merged party.', 'warn');
        }
        return true;
    },

    openMergeDialog() {
        const T = window.PosBoot.i18n || {};
        if (this.mergeSelection.length < 2) {
            this.showToast(T.mergePickTwo || 'Pick at least two tables to merge.', 'warn');
            return;
        }
        this.mergeError = '';
        this.mergeDialog = true;
    },
    closeMergeDialog() { this.mergeDialog = false; this.mergeError = ''; },

    // Promote a chosen table to primary (it keeps the bill).
    makeMergePrimary(id) {
        const i = this.mergeSelection.indexOf(id);
        if (i <= 0) return;
        this.mergeSelection.splice(i, 1);
        this.mergeSelection.unshift(id);
    },

    async doMerge() {
        const R = window.PosBoot.routes || {};
        const T = window.PosBoot.i18n || {};
        if (this.mergeBusy || this.mergeSelection.length < 2) return;
        this.mergeBusy = true;
        this.mergeError = '';
        try {
            const r = await fetch(this.posUrl(R.tablesMerge), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf, Accept: 'application/json' },
                body: JSON.stringify({
                    primary_table_id: this.mergeSelection[0],
                    table_ids: this.mergeSelection.slice(1),
                }),
            });
            const j = await r.json().catch(() => ({}));
            if (!r.ok) {
                // The server owns every refusal reason (branch, split bill, …).
                this.mergeError = j.message || (T.networkError || 'Network error.');
                return;
            }
            this.mergeGroups = j.groups || [];
            this.mergeDialog = false;
            this.mergeMode = false;
            this.mergeSelection = [];
            this.showToast(T.tablesMerged || 'Tables merged.', 'info');
            await this.loadTables();
        } catch (e) {
            this.mergeError = T.networkError || 'Network error.';
        }
        this.mergeBusy = false;
    },

    async unmergeGroup(groupId) {
        const R = window.PosBoot.routes || {};
        const T = window.PosBoot.i18n || {};
        if (!groupId || this.mergeBusy) return;
        if (!window.confirm(T.unmergeConfirm || 'Release this merged party?')) return;
        this.mergeBusy = true;
        try {
            const r = await fetch(this.posUrl(R.tablesUnmergeBase + '/' + groupId), {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': this.csrf, Accept: 'application/json' },
            });
            const j = await r.json().catch(() => ({}));
            if (!r.ok) throw new Error('http');
            this.mergeGroups = j.groups || [];
            this.showToast(T.tablesUnmerged || 'Tables released.', 'info');
            await this.loadTables();
        } catch (e) {
            this.showToast(T.networkError || 'Network error.', 'error');
        }
        this.mergeBusy = false;
    },

    // Tapping a merged primary opens the party's bill, not one of its tickets.
    unmergeFromTile(t) {
        const g = this.mergeGroupFor(t);
        if (g) this.unmergeGroup(g.id);
    },

    /* ── transfer ───────────────────────────────────────────────────────── */

    openTransfer(order) {
        if (!order) return;
        this.transferOrder = order;
        this.transferError = '';
        // The picker renders from the table floor; load it if the cashier has
        // not opened the Tables panel yet this session.
        if (!this.tablesLoaded) this.loadTables();
    },
    closeTransfer() { this.transferOrder = null; this.transferError = ''; },

    transferTargets() {
        const current = this.transferOrder ? String(this.transferOrder.table_number || '') : '';
        return (this.tablesData || []).filter((t) => String(t.table_no) !== current);
    },

    async doTransfer(t) {
        const R = window.PosBoot.routes || {};
        const T = window.PosBoot.i18n || {};
        if (!this.transferOrder || !t || this.transferBusy) return;
        this.transferBusy = true;
        this.transferError = '';
        try {
            const r = await fetch(this.posUrl(R.ordersBase + '/' + this.transferOrder.id + '/transfer'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf, Accept: 'application/json' },
                body: JSON.stringify({ table_no: t.table_no }),
            });
            const j = await r.json().catch(() => ({}));
            if (!r.ok) {
                this.transferError = j.message || (T.networkError || 'Network error.');
                return;
            }
            // Keep the open detail panel honest about where the order now is.
            if (this.selectedOrder && this.selectedOrder.id === this.transferOrder.id) {
                this.selectedOrder = Object.assign({}, this.selectedOrder, { table_number: j.table_number, type: j.type });
            }
            this.transferOrder = null;
            this.showToast(T.orderMoved || 'Order moved.', 'info');
            await this.loadTables();
            await this.loadMerges();
        } catch (e) {
            this.transferError = T.networkError || 'Network error.';
        }
        this.transferBusy = false;
    },
};
