// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* ==========================================================================
   POS — Manage panel (Shift + Reports). Gives the orphaned P2/P6 endpoints
   (shifts open/close, Z-report, pos/sales) their first UI. Manager-gated:
   the rail item only renders for the owner / pos_shift_close staff.
   ========================================================================== */
window.PosModules = window.PosModules || {};

window.PosModules.manage = {
    manageTab: 'shift', // 'shift' | 'reports'
    // ── shift state ──
    shift: null,          // {shift_id, opened_at, opening_float} | null
    shiftLoading: false,
    floatStr: '',         // opening float input
    countedStr: '',       // counted cash input on close
    zreport: null,        // last Z-report (rendered after close, or on demand)
    shiftBusy: false,
    // ── D2: drawer handoff ──
    canDrawer: !!(window.PosBoot && window.PosBoot.canDrawer),
    staffList: (window.PosBoot && window.PosBoot.staffList) || [],
    handoffTo: 0,         // receiving cashier id (0 = none picked)
    // ── reports state ──
    sales: null,
    salesLoading: false,
    salesFrom: '',        // yyyy-mm-dd ('' = today)
    salesTo: '',
    // D9: branch filter — defaults to this register's own branch ('' = owner/all).
    salesBranch: (window.PosBoot && window.PosBoot.branch != null) ? String(window.PosBoot.branch) : '',

    initManage() {
        this.$watch('activePanel', (v) => {
            if (v === 'manage') { this.loadShift(); if (this.manageTab === 'reports') this.loadSales(); }
        });
    },
    setManageTab(t) {
        this.manageTab = t;
        if (t === 'reports' && !this.sales) this.loadSales();
    },

    async loadShift() {
        const url = (window.PosBoot.routes || {}).shiftCurrent;
        if (!url) return;
        this.shiftLoading = true;
        try {
            const r = await fetch(this.posUrl(url), { headers: { 'Accept': 'application/json' } });
            const d = r.ok ? (await r.json().catch(() => null)) : null;
            this.shift = d ? d.shift : null;
        } catch (e) { this.shift = null; }
        this.shiftLoading = false;
    },
    async openShiftNow() {
        if (this.shiftBusy) return;
        const routes = window.PosBoot.routes || {};
        this.shiftBusy = true;
        const r = await this.post(this.posUrl(routes.shiftOpen), { opening_float: parseFloat(this.floatStr) || 0, branch_id: this.branch || null });
        this.shiftBusy = false;
        if (r.ok) { this.zreport = null; this.floatStr = ''; await this.loadShift(); this.showToast((window.PosBoot.i18n || {}).shiftOpened || 'Shift opened.', 'success'); }
        else this.showToast((r.data && r.data.message) || 'Error', 'error');
    },
    async closeShiftNow() {
        if (this.shiftBusy || !this.shift) return;
        const routes = window.PosBoot.routes || {};
        const counted = parseFloat(this.countedStr);
        if (isNaN(counted)) { this.showToast((window.PosBoot.i18n || {}).countedRequired || 'Enter the counted cash.', 'warn'); return; }
        this.shiftBusy = true;
        const r = await this.post(this.posUrl(routes.shiftsBase + '/' + this.shift.shift_id + '/close'), { counted_cash: counted });
        this.shiftBusy = false;
        if (r.ok) { this.zreport = r.data; this.countedStr = ''; await this.loadShift(); }
        else this.showToast((r.data && r.data.message) || 'Error', 'error');
    },
    // D2: close this shift with the counted cash and open a fresh one for the
    // chosen cashier (that amount carries as the opening float). Idempotency key
    // absorbs a double-tap; uuid() lives in pos-core.
    async handoffDrawer() {
        if (this.shiftBusy || !this.shift) return;
        const i18n = window.PosBoot.i18n || {};
        const routes = window.PosBoot.routes || {};
        const to = +this.handoffTo;
        if (!to) { this.showToast(i18n.handoffPickStaff || 'Choose who takes the drawer.', 'warn'); return; }
        const counted = parseFloat(this.countedStr);
        if (isNaN(counted)) { this.showToast(i18n.countedRequired || 'Enter the counted cash.', 'warn'); return; }
        this.shiftBusy = true;
        const r = await this.post(this.posUrl(routes.shiftsBase + '/handoff'), {
            to_staff_id: to, counted_cash: counted,
            idempotency_key: (this.uuid && this.uuid()) || ('pos-' + Date.now()),
        });
        this.shiftBusy = false;
        if (r.ok) {
            this.zreport = r.data && r.data.closed ? r.data.closed : null;
            this.countedStr = ''; this.handoffTo = 0;
            await this.loadShift();
            this.showToast(i18n.handoffDone || 'Drawer handed over.', 'success');
        } else this.showToast((r.data && r.data.message) || 'Error', 'error');
    },
    async loadZReport() {
        if (!this.shift) return;
        const routes = window.PosBoot.routes || {};
        try {
            const r = await fetch(this.posUrl(routes.shiftsBase + '/' + this.shift.shift_id + '/zreport'), { headers: { 'Accept': 'application/json' } });
            if (r.ok) this.zreport = await r.json();
        } catch (e) {}
    },
    printZReport() { window.print(); },
    overShortClass() { return this.zreport && this.zreport.over_short < 0 ? 'is-short' : ''; },

    async loadSales() {
        const url = (window.PosBoot.routes || {}).sales;
        if (!url) return;
        this.salesLoading = true;
        try {
            const u = new URL(this.posUrl(url));
            if (this.salesFrom) u.searchParams.set('from', this.salesFrom);
            if (this.salesTo) u.searchParams.set('to', this.salesTo);
            // D9: send branch_id when a branch is chosen (0 = all); omit for owner default.
            if (this.salesBranch !== '' && this.salesBranch !== null && this.salesBranch !== undefined) {
                u.searchParams.set('branch_id', this.salesBranch);
            }
            const r = await fetch(u.toString(), { headers: { 'Accept': 'application/json' } });
            this.sales = r.ok ? (await r.json().catch(() => null)) : null;
        } catch (e) { this.sales = null; }
        this.salesLoading = false;
    },
    // Hour bars scale against the day's peak hour.
    hourBars() {
        if (!this.sales) return [];
        const hours = this.sales.sales_by_hour || [];
        const max = Math.max(1, ...hours);
        return hours.map((v, h) => ({ h, v, pct: Math.round((v / max) * 100) })).filter(b => b.v > 0);
    },
    methodRows() { return this.sales ? Object.entries(this.sales.sales_by_method || {}).map(([m, v]) => ({ m, v })) : []; },
};
