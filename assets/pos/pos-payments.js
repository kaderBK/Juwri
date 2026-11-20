// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* ==========================================================================
   POS-V2 T5 — settle screen (payments). T5 zone.
   Registers on window.PosModules; composed onto the register instance. Replaces
   the old cash prompt with a split settle sheet: LEFT read region (lines +
   customer + totals), RIGHT actives (payable + Cash | Other tabs). Reuses the
   core submitPaid() to place + settle. Keypad/tip/split/wallet/coupon land in
   T5-2…T5-6.
   ========================================================================== */
window.PosModules = window.PosModules || {};

// Pure helpers (node-tested: tests/js/pos/payments-exact.test.mjs).
window.PosPayments = {
    decimals(d) { const n = Number(d); return Number.isInteger(n) && n >= 0 && n <= 4 ? n : 2; },
    /** payable + tip, summed in minor units of the currency (no float drift), as a major number. */
    exactTender(payable, tip, decimals) {
        const f = Math.pow(10, decimals);
        const minor = Math.round((Number(payable) || 0) * f) + Math.round((Number(tip) || 0) * f);
        return minor / f;
    },
};

window.PosModules.payments = {
    settleOpen: false,
    settleTab: 'cash', // 'cash' | 'other'
    settleMethod: 'card', // selected method on the Other tab
    tenderStr: '',        // T5-2: keypad buffer for the cash tender
    settleRef: '',        // T5-2: optional reference on the Other tab
    tipAmount: 0,         // T5-3: tip added to the settle
    tipPct: null,         // selected % chip (null = custom/none)
    useWallet: false,     // T5-5: redeem store credit
    walletAmt: null,      // optional partial amount (null = full available)

    // Entry points: the cart Cash/Card buttons.
    openSettle(mode) {
        if (!this.cart.length) return;
        this.settleTab = (mode === 'card' || mode === 'other') ? 'other' : 'cash';
        this.settleMethod = mode === 'card' ? 'card' : 'card';
        this.tendered = 0;
        this.tenderStr = '';
        this.settleRef = '';
        this.tipAmount = 0;
        this.tipPct = null;
        this.useWallet = false;
        this.walletAmt = null;
        this.splitParts = 2;
        this.splitShares = [];
        this.splitOrderId = null;
        this.settleOpen = true;
    },
    // T5-5: toggle wallet redemption (only meaningful with an attached customer).
    toggleWallet() { this.useWallet = !this.useWallet; if (!this.useWallet) this.walletAmt = null; },
    // ── T5-3: tip ──
    setTipPct(p) { this.tipPct = p; this.tipAmount = p ? Math.round(this.payable() * p / 100 * 100) / 100 : 0; },
    setTipAmount(v) { this.tipPct = null; this.tipAmount = Math.max(0, Number(v) || 0); },
    clearTip() { this.tipPct = null; this.tipAmount = 0; },
    // Amount to collect = order payable + tip.
    settleTotal() { return this.payable() + (Number(this.tipAmount) || 0); },
    closeSettle() { this.settleOpen = false; },
    setSettleTab(t) { this.settleTab = t; },

    // ── T5-2: cash keypad + presets ──
    _syncTender() { this.tendered = parseFloat(this.tenderStr) || 0; },
    keypad(k) {
        if (k === 'del') { this.tenderStr = this.tenderStr.slice(0, -1); }
        else if (k === '.') { if (!this.tenderStr.includes('.')) this.tenderStr += (this.tenderStr ? '.' : '0.'); }
        else { this.tenderStr = (this.tenderStr === '0' ? '' : this.tenderStr) + k; }
        this._syncTender();
    },
    // "Exact" is what the cashier collects: the payable PLUS the tip (settleTotal), at the store currency's decimals
    // (0 / 2 / 3). It ignored the tip, so a tipped exact tender came up short.
    presetTender(v) {
        const d = window.PosPayments.decimals((window.PosBoot || {}).currencyDecimals);
        const amt = (v === 'exact') ? window.PosPayments.exactTender(this.payable(), this.tipAmount, d) : Number(v);
        this.tenderStr = amt.toFixed(d);
        this._syncTender();
    },

    // Change due on a cash tender (settleTotal = payable + tip).
    changeDue() { return Math.max(0, (Number(this.tendered) || 0) - this.settleTotal()); },
    // Outstanding balance when the tender is short (shown in danger).
    balanceDue() { return Math.max(0, this.settleTotal() - (Number(this.tendered) || 0)); },
    // Cash tender must cover the payable (+ tip) before Confirm is allowed.
    cashCovers() { return (Number(this.tendered) || 0) >= this.settleTotal() - 0.001; },

    // Confirm → place + settle via the existing core path, then close.
    confirmSettle(method) {
        this.settleOpen = false;
        this.submitPaid(method);
    },

    // Hotel: the assigned table is a guest room with a checked-in stay, so the
    // tab can be settled onto the folio instead of collecting at the register.
    // (this.table.canChargeToRoom is set from the tables payload in assignTable.)
    canChargeToRoom() { return !!(this.table && this.table.canChargeToRoom); },
    chargeToRoomGuest() { return (this.table && this.table.roomGuest) || ''; },

    // ── Settle an EXISTING server-side order (external/online or held detail) ──
    // The cart-based settle screen places a NEW order; these orders already exist,
    // so this compact modal POSTs straight to pos.orders.settle for the given id.
    extSettle: { open: false, id: null, total: 0, label: '', method: 'cash', tenderStr: '', tip: 0, busy: false, coupon: '', couponBusy: false, couponErr: '', couponApplied: false },
    openExtSettle(o) {
        if (!o || !o.id) return;
        this.extSettle = {
            open: true, id: o.id, total: Number(o.total) || 0,
            label: '#' + o.id, method: 'cash', tenderStr: '', tip: 0, busy: false,
            coupon: '', couponBusy: false, couponErr: '', couponApplied: false,
        };
    },
    // Apply a coupon to THIS existing order; the returned total already reflects it.
    async applyExtCoupon() {
        if (!this.extSettle.id || !this.extSettle.coupon || this.extSettle.couponBusy) return;
        const base = (window.PosBoot.routes || {}).ordersBase;
        this.extSettle.couponBusy = true; this.extSettle.couponErr = '';
        const r = await this.post(this.posUrl(base + '/' + this.extSettle.id + '/coupon'), { coupon_code: this.extSettle.coupon.trim() });
        this.extSettle.couponBusy = false;
        if (r.ok && r.data && r.data.total != null) {
            this.extSettle.total = Number(r.data.total) || 0;   // payable drops by the discount
            this.extSettle.coupon = '';
            this.extSettle.couponApplied = true;   // Coupons C6: a 0 total shows "Free with coupon"
            this.showToast((window.PosBoot.i18n || {}).couponApplied || 'Coupon applied.', 'success');
        } else {
            this.extSettle.couponErr = (r.data && r.data.message) || (window.PosBoot.i18n || {}).couponInvalid || 'Invalid coupon.';
        }
    },
    closeExtSettle() { this.extSettle.open = false; },
    setExtMethod(m) { this.extSettle.method = m; if (m !== 'cash') this.extSettle.tenderStr = ''; },
    extTotal() { return (Number(this.extSettle.total) || 0) + (Number(this.extSettle.tip) || 0); },
    extTendered() { return parseFloat(this.extSettle.tenderStr) || 0; },
    extChange() { return Math.max(0, this.extTendered() - this.extTotal()); },
    extBalance() { return Math.max(0, this.extTotal() - this.extTendered()); },
    // Cash must cover the total; card/online/other settle without a tender.
    extCovers() { return this.extSettle.method !== 'cash' || this.extTendered() >= this.extTotal() - 0.001; },
    extKeypad(k) {
        let s = this.extSettle.tenderStr;
        if (k === 'del') s = s.slice(0, -1);
        else if (k === '.') { if (!s.includes('.')) s += (s ? '.' : '0.'); }
        else s = (s === '0' ? '' : s) + k;
        this.extSettle.tenderStr = s;
    },
    extPreset(v) { this.extSettle.tenderStr = (v === 'exact' ? this.extTotal() : Number(v)).toFixed(2); },
    async confirmExtSettle() {
        if (this.extSettle.busy || !this.extCovers()) return;
        const routes = window.PosBoot.routes || {};
        this.extSettle.busy = true;
        const body = { method: this.extSettle.method, tip: Number(this.extSettle.tip) || 0 };
        if (this.extSettle.method === 'cash') body.tendered = this.extTendered();
        const r = await this.post(this.posUrl(routes.ordersBase + '/' + this.extSettle.id + '/settle'), body);
        this.extSettle.busy = false;
        if (r.queued) { this.extSettle.open = false; this.showToast((window.PosBoot.i18n || {}).offlineQueued || 'Offline — queued.', 'info'); return; }
        if (!r.ok) { this.showToast((r.data && r.data.message) || 'Error', 'error'); return; }
        this.extSettle.open = false;
        this.selectedOrder = null;
        this.autoPrint(r.data && r.data.print);
        this.showToast((window.PosBoot.i18n || {}).paid || 'Paid.', 'success');
        // Refresh whichever hub list is open so the settled order updates.
        if (this.ordersTab === 'incoming') this.loadIncoming();
        else if (this.ordersTab === 'held') this.loadHeld();
        else if (this.ordersTab === 'history') this.loadHistory();
    },

    // ── T5-6: split payment (reuses the shared SplitBillService seam) ──
    splitParts: 2,
    splitShares: [],
    splitOrderId: null,
    splitBusy: false,
    // Place the current cart as an unpaid order, then create an even N-way split.
    async startSplit() {
        if (!this.cart.length) return;
        const routes = window.PosBoot.routes || {};
        this.splitBusy = true;
        const create = await this.post(this.posUrl(routes.ordersStore), this.payload(false));
        if (!create.ok || !create.data || !create.data.id) {
            this.splitBusy = false;
            this.showToast((create.data && create.data.message) || 'Error', 'error');
            return;
        }
        this.splitOrderId = create.data.id;
        const r = await this.post(this.posUrl(routes.ordersBase + '/' + this.splitOrderId + '/split'), { parts: this.splitParts });
        this.splitBusy = false;
        if (r.ok && r.data) this.splitShares = r.data.shares || [];
    },
    // Pay one share (method chosen per share). Last share → order settles.
    async paySplitShare(share) {
        const routes = window.PosBoot.routes || {};
        this.splitBusy = true;
        const r = await this.post(this.posUrl(routes.ordersBase + '/' + this.splitOrderId + '/split-pay'),
            { share_id: share.id, method: share.method || 'cash' });
        this.splitBusy = false;
        if (r.ok && r.data) {
            this.splitShares = r.data.shares || this.splitShares;
            if (r.data.is_paid) {
                this.settleOpen = false;
                this.reset();
                this.showToast((window.PosBoot.i18n || {}).paid || 'Paid.', 'success');
            }
        } else {
            this.showToast((r.data && r.data.message) || 'Error', 'error');
        }
    },
    splitPaidCount() { return this.splitShares.filter(s => s.status === 'paid').length; },
};
