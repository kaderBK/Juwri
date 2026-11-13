// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* ==========================================================================
   POS-V2 T4 — orders hub (On hold / History / Offline). T4 zone.
   Registers on window.PosModules; composed onto the register instance. Loads when
   the Orders panel opens. Held + history come from endpoints; the offline queue is
   read straight from IndexedDB via the core idbAll().
   ========================================================================== */
window.PosModules = window.PosModules || {};

window.PosModules.hold = {
    ordersTab: 'held', // 'held' | 'incoming' | 'history' | 'offline'
    hubQuery: '',      // search across id / type / table / note / source
    heldOrders: [],
    incomingOrders: [],
    historyOrders: [],
    offlineOrders: [],
    ordersLoading: false,
    selectedOrder: null,
    addToOrderId: null, // when set, the cart's new lines append to this open order

    initHold() {
        this.$watch('activePanel', (v) => { if (v === 'orders') this.setOrdersTab(this.ordersTab); });
    },
    setOrdersTab(t) {
        this.ordersTab = t;
        this.selectedOrder = null;
        if (t === 'held') this.loadHeld();
        else if (t === 'incoming') this.loadIncoming();
        else if (t === 'history') this.loadHistory();
        else this.loadOffline();
        // Opening the Online-orders tab acknowledges the incoming-order alarm.
        if (t === 'incoming' && window.NotifySounds) NotifySounds.stopRepeat('order_new');
    },
    async _get(route, target) {
        const url = (window.PosBoot.routes || {})[route];
        if (!url) return;
        this.ordersLoading = true;
        try {
            const r = await fetch(this.posUrl(url), { headers: { 'Accept': 'application/json' } });
            this[target] = r.ok ? (await r.json().catch(() => [])) : [];
        } catch (e) { this[target] = []; }
        this.ordersLoading = false;
    },
    loadHeld() { return this._get('held', 'heldOrders'); },
    loadIncoming() { return this._get('ordersIncoming', 'incomingOrders'); },

    // ── Incoming-order watch: ring the register when an online/aggregator order
    // arrives. Polls the incoming list; a new max id → toast + owner-configured
    // sound (repeat-until-acknowledged). Opening the Online-orders tab = ack. ──
    _lastIncomingId: null,
    _incomingTimer: null,
    initIncomingWatch() {
        const url = (window.PosBoot.routes || {}).ordersIncoming;
        if (!url) return;
        const poll = async () => {
            try {
                const r = await fetch(this.posUrl(url), { headers: { 'Accept': 'application/json' } });
                if (!r.ok) return;
                const rows = await r.json().catch(() => []);
                const maxId = rows.length ? Math.max(...rows.map(o => o.id)) : 0;
                if (this._lastIncomingId !== null && maxId > this._lastIncomingId) {
                    this.showToast((window.PosBoot.i18n || {}).incomingOrder || 'New online order!', 'info');
                    if (window.NotifySounds) NotifySounds.startRepeat('order_new');
                }
                this._lastIncomingId = maxId;
            } catch (e) { /* offline — skip this tick */ }
        };
        poll(); // set the baseline immediately (no sound on first load)
        clearInterval(this._incomingTimer);
        this._incomingTimer = setInterval(poll, 30000);
        // Live updates (Reverb, only when switched on): an order placed/changed in this store re-runs the same check
        // and refreshes the open hub list at once. The 30s poll above stays as the fallback if the socket is off or drops.
        if (window.JuwriLiveWhenReady && window.PosBoot.storeId) {
            JuwriLiveWhenReady(() => JuwriLive.listen(window.PosBoot.storeId, 'pos', 'pos.changed', () => {
                poll();
                if (this.activePanel === 'orders') {
                    if (this.ordersTab === 'incoming') this.loadIncoming();
                    else if (this.ordersTab === 'held') this.loadHeld();
                }
            }, window.PosBoot.liveBranchId));
        }
    },
    loadHistory() { return this._get('ordersRecent', 'historyOrders'); },
    async loadOffline() {
        // The offline queue is client-side (IndexedDB); each row is a queued POST.
        this.ordersLoading = true;
        const items = (await this.idbAll().catch(() => [])).filter(it => !it.record); // typed records are listed by offlineSales()
        const sales = this.offlineSales ? await this.offlineSales().catch(() => []) : []; // OP-9: sales made on this till, newest first
        this.offlineOrders = sales.reverse().concat(items.map(it => ({
            id: it.id,
            body: it.body || {},
            time: it.ts ? new Date(it.ts).toISOString() : null, // T4-4: enqueue time
            item_count: ((it.body && it.body.items) || []).reduce((s, l) => s + (l.quantity || 0), 0),
        })));
        this.ordersLoading = false;
    },
    // T4-4: retry the whole queue (flush) then refresh the offline list + badge.
    async retryQueue() {
        await this.flush();
        await this.loadOffline();
        this.refreshQueued();
    },
    // T4-4: discard one queued mutation (confirmed) — it will never be sent.
    async discardQueued(o) {
        const msg = (window.PosBoot.i18n || {}).discardConfirm || 'Discard this queued order?';
        if (!window.confirm(msg)) return;
        await this.idbDel(o.id);
        await this.loadOffline();
        this.refreshQueued();
    },
    currentOrders() {
        let list;
        if (this.ordersTab === 'held') list = this.heldOrders;
        else if (this.ordersTab === 'incoming') list = this.incomingOrders;
        else if (this.ordersTab === 'history') list = this.historyOrders;
        else list = this.offlineOrders;
        const q = (this.hubQuery || '').trim().toLowerCase();
        if (!q) return list;
        // Client-side filter (lists are ≤30 rows): id / type / table / note / source.
        return list.filter(o =>
            String(o.id).includes(q) ||
            (o.type || '').toLowerCase().includes(q) ||
            String(o.table_number || '').toLowerCase().includes(q) ||
            (o.note || '').toLowerCase().includes(q) ||
            (o.source || '').toLowerCase().includes(q));
    },
    // T4-3: open detail — show the slim row immediately, then fetch full lines.
    async openOrderDetail(o) {
        this.selectedOrder = { ...o, lines: null };
        const base = (window.PosBoot.routes || {}).ordersBase;
        if (!base) return;
        try {
            const r = await fetch(this.posUrl(base + '/' + o.id + '/detail'), { headers: { 'Accept': 'application/json' } });
            if (r.ok && this.selectedOrder && this.selectedOrder.id === o.id) {
                this.selectedOrder = await r.json();
            }
        } catch (e) { /* keep the slim view */ }
    },
    closeOrderDetail() { this.selectedOrder = null; },

    // "Add products" to an existing open order: enter add-mode with an empty cart,
    // then the cart action row submits new lines to pos.orders.addItems.
    startAddToOrder(o) {
        if (!o || !o.id) return;
        this.addToOrderId = o.id;
        this.reset();                 // fresh cart for the added lines only
        this.selectedOrder = null;
        this.activePanel = 'home';
        this.showToast((window.PosBoot.i18n || {}).addingToOrder || 'Adding products to the order.', 'info');
    },
    cancelAddToOrder() { this.addToOrderId = null; this.reset(); },

    // "Edit order": load an existing order's lines into the cart, edit, then replace.
    editOrderId: null,
    async startEditOrder(o) {
        if (!o || !o.id) return;
        const base = (window.PosBoot.routes || {}).ordersBase;
        try {
            const r = await fetch(this.posUrl(base + '/' + o.id + '/cart'), { headers: { 'Accept': 'application/json' } });
            if (!r.ok) { this.showToast('Error', 'error'); return; }
            const d = await r.json();
            this.reset();
            this.cart = d.lines || [];
            this.editOrderId = o.id;
            if (d.table_number) { this.type = 'on-table'; this.table = { no: d.table_number, name: '' + d.table_number }; }
            this.selectedOrder = null;
            this.activePanel = 'home';
            this.showToast((window.PosBoot.i18n || {}).editingOrder || ('Editing order #' + o.id), 'info');
        } catch (e) { this.showToast('Error', 'error'); }
    },
    cancelEditOrder() { this.editOrderId = null; this.reset(); },
    async submitEditItems() {
        if (!this.editOrderId || !this.cart.length || this.sending) return;
        const base = (window.PosBoot.routes || {}).ordersBase;
        this.sending = true;
        const r = await this.post(this.posUrl(base + '/' + this.editOrderId + '/replace'), { items: this.payload(false).items });
        this.sending = false;
        if (r.ok) {
            const id = this.editOrderId;
            this.editOrderId = null;
            this.reset();
            this.showToast((window.PosBoot.i18n || {}).orderUpdated || ('Order #' + id + ' updated'), 'success');
        } else {
            this.showToast((r.data && r.data.message) || 'Error', 'error');
        }
    },
    async submitAddItems() {
        if (!this.addToOrderId || !this.cart.length || this.sending) return;
        const base = (window.PosBoot.routes || {}).ordersBase;
        this.sending = true;
        const body = { items: this.payload(false).items };
        const r = await this.post(this.posUrl(base + '/' + this.addToOrderId + '/items'), body);
        this.sending = false;
        if (r.ok) {
            const id = this.addToOrderId;
            this.addToOrderId = null;
            this.reset();
            this.showToast((window.PosBoot.i18n || {}).addedToOrder || ('Added to order #' + id), 'success');
        } else {
            this.showToast((r.data && r.data.message) || 'Error', 'error');
        }
    },
    // T4-3: open the printable receipt/invoice (auto-prints on load).
    printInvoice(o) {
        const base = (window.PosBoot.routes || {}).ordersBase;
        if (base) window.open(this.posUrl(base + '/' + o.id + '/receipt') + '&print=1', '_blank');
    },
    // Settling a held order = resume it, then settle on the register (T5 builds the
    // in-hub settle screen). For now direct the cashier.
    settleHeld(o) { this.showToast((window.PosBoot.i18n || {}).settleHeld || 'Resume the order, then settle on the register.', 'info'); },

    // T4-2: age chip ("just now" / "5m" / "2h") from an ISO timestamp.
    ageChip(iso) {
        if (!iso) return '';
        const mins = Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 60000));
        if (mins < 1) return (window.PosBoot.i18n || {}).justNow || 'now';
        if (mins < 60) return mins + 'm';
        return Math.floor(mins / 60) + 'h';
    },
    // T4-2: resume a held order (un-hold + fire to kitchen) via the existing endpoint.
    async resumeOrder(o) {
        const base = (window.PosBoot.routes || {}).ordersBase;
        if (!base) return;
        const r = await this.post(this.posUrl(base + '/' + o.id + '/resume'), {});
        if (r.ok) { this.selectedOrder = null; this.loadHeld(); this.showToast((window.PosBoot.i18n || {}).resumed || 'Order resumed.', 'success'); }
        else this.showToast((r.data && r.data.message) || 'Error', 'error');
    },

    // D5: flag / unflag this ticket as RUSH (jumps the kitchen queue, red chip).
    async toggleRush(o) {
        const i18n = window.PosBoot.i18n || {};
        const base = (window.PosBoot.routes || {}).ordersBase;
        if (!base || !o) return;
        const r = await this.post(this.posUrl(base + '/' + o.id + '/rush'), { is_rush: !o.is_rush });
        if (r.ok) {
            o.is_rush = !!(r.data && r.data.is_rush);
            this.showToast(o.is_rush ? (i18n.rushOn || 'Marked rush.') : (i18n.rushOff || 'Rush cleared.'), 'success');
            this.loadHistory && this.loadHistory();
        } else {
            this.showToast((r.data && r.data.message) || 'Error', 'error');
        }
    },

    // ── STF-013 / D1: void / refund from the order detail panel ───────────────
    canRefund: !!(window.PosBoot && window.PosBoot.canRefund),
    adjPane: null,     // null | 'void' | 'refund'
    adjReason: '',
    adjAmount: null,   // refund only
    adjMethod: 'cash', // refund only
    adjMode: 'amount', // D1: 'amount' | 'items' (refund only)
    adjLines: [],      // D1: per-item refund picker rows
    adjKey: null,      // D1: per-open idempotency key (absorbs a double-submit)
    adjSending: false,

    openAdjust(kind) {
        this.adjPane = kind;
        this.adjReason = '';
        this.adjMethod = 'cash';
        this.adjMode = 'amount';
        // D1: a fresh idempotency key per modal open (uuid() lives in pos-core).
        this.adjKey = (this.uuid && this.uuid()) || ('pos-' + Date.now());
        // Default the refund form to the remaining refundable amount.
        const o = this.selectedOrder || {};
        this.adjAmount = kind === 'refund'
            ? Math.max(0, Math.round(((+o.total || 0) - (+o.refunded_total || 0)) * 100) / 100)
            : null;
        // D1: build the item picker from the order's lines (remaining qty per line).
        this.adjLines = (kind === 'refund' ? (o.lines || []) : [])
            .filter(l => l.id) // combo/detail lines always carry an order_item_id
            .map(l => {
                const remaining = Math.max(0, (+l.qty || 0) - (+l.refunded_qty || 0));
                return {
                    order_item_id: l.id,
                    name: l.name,
                    remaining,
                    qty: 0,
                    value: +l.line_value || 0,
                };
            });
    },

    // D1: default each line's refund amount to unit value × chosen qty (editable).
    adjLineAmount(l) {
        return Math.round((+l.value || 0) * (+l.qty || 0) * 100) / 100;
    },

    async submitAdjust() {
        const i18n = window.PosBoot.i18n || {};
        const base = (window.PosBoot.routes || {}).ordersBase;
        const o = this.selectedOrder;
        if (!base || !o || this.adjSending) return;
        if (!this.adjReason.trim()) { this.showToast(i18n.reasonRequired || 'A reason is required.', 'error'); return; }

        let url, body;
        if (this.adjPane === 'void') {
            url = base + '/' + o.id + '/void';
            body = { reason: this.adjReason.trim() };
        } else if (this.adjMode === 'items') {
            // D1: itemised refund — one line per picked item (qty > 0).
            const lines = this.adjLines
                .filter(l => (+l.qty || 0) > 0)
                .map(l => ({ order_item_id: l.order_item_id, qty: +l.qty, amount: this.adjLineAmount(l) }));
            if (!lines.length) { this.showToast(i18n.refundNoItems || 'Select at least one item to refund.', 'error'); return; }
            url = base + '/' + o.id + '/refund-items';
            body = { lines, method: this.adjMethod, reason: this.adjReason.trim(), idempotency_key: this.adjKey };
        } else {
            const amt = +this.adjAmount;
            if (!amt || amt <= 0) { this.showToast(i18n.refundAmountInvalid || 'Enter a valid refund amount.', 'error'); return; }
            const remaining = Math.round(((+o.total || 0) - (+o.refunded_total || 0)) * 100) / 100;
            if (remaining <= 0) { this.showToast(i18n.nothingLeftToRefund || 'Nothing left to refund on this order.', 'error'); return; }
            url = base + '/' + o.id + '/refund';
            // Carry the same per-open idempotency key the item-refund path sends, so a
            // slow-network retry or the offline-queue flush can't record the refund twice.
            body = { amount: amt, method: this.adjMethod, reason: this.adjReason.trim(), idempotency_key: this.adjKey };
        }

        this.adjSending = true;
        const r = await this.post(this.posUrl(url), body);
        this.adjSending = false;

        if (r.ok) {
            this.showToast(this.adjPane === 'void' ? (i18n.voided || 'Order voided.') : (i18n.refunded || 'Refund recorded.'), 'success');
            this.adjPane = null;
            // Refresh: refunded_total / status changed server-side, and the row
            // lists (held/history) may have lost or restated this order.
            this.openOrderDetail(o);
            this.loadHistory && this.loadHistory();
            this.loadHeld && this.loadHeld();
        } else {
            // 422 carries either a message or a Laravel errors bag (amount/reason).
            const msg = (r.data && (r.data.message
                || (r.data.errors && Object.values(r.data.errors).flat()[0]))) || 'Error';
            this.showToast(msg, 'error');
        }
    },
};
