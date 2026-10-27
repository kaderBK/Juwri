// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* ==========================================================================
   POS-V2 T2 — customer attach (search panel + attached chip). T2 zone.
   Registers on window.PosModules; the core factory composes it onto the register
   instance. Uses the T2-1 endpoint (pos/customers). Attaching sets `customer`,
   which pos-core.js threads onto the order payload as customer_id.
   ========================================================================== */
window.PosModules = window.PosModules || {};

window.PosModules.customers = {
    custOpen: false,
    custQuery: '',
    custResults: [],
    custLoading: false,
    custLoaded: false,
    customer: null, // the attached customer (null = walk-in)

    openCustomers() {
        this.custOpen = true;
        if (!this.custLoaded) this.searchCustomers(); // preload recent on first open
    },
    closeCustomers() { this.custOpen = false; },

    async searchCustomers() {
        const route = (window.PosBoot.routes || {}).customers;
        if (!route) return;
        this.custLoading = true;
        try {
            const url = new URL(this.posUrl(route));
            url.searchParams.set('q', (this.custQuery || '').trim());
            const r = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
            this.custResults = r.ok ? (await r.json().catch(() => [])) : [];
        } catch (e) {
            this.custResults = [];
        }
        this.custLoading = false;
        this.custLoaded = true;
    },

    // Debounced-ish live search on input (called from the field).
    onCustQuery() { this.searchCustomers(); },

    attachCustomer(c) {
        this.customer = c;
        this.custOpen = false;
        this.newOpen = false;
    },
    detachCustomer() { this.customer = null; },

    // ── T2-3: inline create ──
    newOpen: false,
    newCust: { name: '', phone: '' },
    newErr: '',
    newSaving: false,
    openNew() { this.newOpen = true; this.newCust = { name: '', phone: '' }; this.newErr = ''; },
    closeNew() { this.newOpen = false; },
    async createCustomer() {
        const i18n = (window.PosBoot || {}).i18n || {};
        const name = (this.newCust.name || '').trim();
        if (!name) { this.newErr = i18n.nameRequired || 'Name is required'; return; }
        const route = (window.PosBoot.routes || {}).customersStore;
        if (!route) return;
        this.newSaving = true; this.newErr = '';
        const r = await this.post(this.posUrl(route), { name, phone: (this.newCust.phone || '').trim() || null });
        this.newSaving = false;
        if (r.ok && r.data && r.data.id) {
            this.attachCustomer(r.data); // create + attach in one flow
        } else {
            // Surface the first validation message (e.g. phone already used).
            const errs = r.data && r.data.errors;
            this.newErr = errs ? Object.values(errs)[0][0] : (r.data && r.data.message) || (i18n.saveFailed || 'Could not save');
        }
    },

    // MEMBER pill when the customer carries loyalty points.
    isMember(c) { return !!(c && Number(c.loyalty_points) > 0); },
    custInitial(c) { return ((c && c.name) ? c.name.trim().charAt(0) : '?').toUpperCase(); },

    // ── Customer detail: edit + order history ──
    custDetail: null,          // customer being viewed/edited (copy)
    custEdit: { name: '', phone: '', email: '' },
    custHistory: [],
    custHistLoading: false,
    editErr: '',
    editSaving: false,
    openCustDetail(c) {
        this.custDetail = c;
        this.custEdit = { name: c.name || '', phone: c.phone || '', email: c.email || '' };
        this.editErr = '';
        this.loadCustHistory(c.id);
    },
    closeCustDetail() { this.custDetail = null; this.custHistory = []; },
    async loadCustHistory(id) {
        const base = (window.PosBoot.routes || {}).customers;
        if (!base) return;
        this.custHistLoading = true;
        try {
            const r = await fetch(this.posUrl(base + '/' + id + '/orders'), { headers: { 'Accept': 'application/json' } });
            this.custHistory = r.ok ? (await r.json().catch(() => [])) : [];
        } catch (e) { this.custHistory = []; }
        this.custHistLoading = false;
    },
    async saveCustomer() {
        const i18n = (window.PosBoot || {}).i18n || {};
        const name = (this.custEdit.name || '').trim();
        if (!name) { this.editErr = i18n.nameRequired || 'Name is required'; return; }
        const base = (window.PosBoot.routes || {}).customers;
        if (!base || !this.custDetail) return;
        this.editSaving = true; this.editErr = '';
        const r = await this.post(this.posUrl(base + '/' + this.custDetail.id), {
            name, phone: (this.custEdit.phone || '').trim() || null, email: (this.custEdit.email || '').trim() || null,
        });
        this.editSaving = false;
        if (r.ok && r.data && r.data.id) {
            // Refresh the row in results + the attached chip if it's the same customer.
            this.custResults = this.custResults.map(c => (c.id === r.data.id ? r.data : c));
            if (this.customer && this.customer.id === r.data.id) this.customer = r.data;
            this.custDetail = r.data;
            this.showToast(i18n.saved || 'Saved.', 'success');
        } else {
            const errs = r.data && r.data.errors;
            this.editErr = errs ? Object.values(errs)[0][0] : (r.data && r.data.message) || (i18n.saveFailed || 'Could not save');
        }
    },
};
