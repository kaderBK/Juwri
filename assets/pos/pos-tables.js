// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* ==========================================================================
   POS-V2 T3 — table floor (Select Table panel). T3 zone.
   Registers on window.PosModules; composed onto the register instance. Loads the
   T3-1 endpoint (pos/tables) when the Tables panel opens, filters by guest count
   and groups tiles by zone. Assign flow is T3-3.
   ========================================================================== */
window.PosModules = window.PosModules || {};

window.PosModules.tables = {
    tablesData: [],
    tablesLoading: false,
    tablesLoaded: false,
    seatFilter: 'all',  // capacity: 'all' | '1-2' | '4-6' | '6+'
    stateFilter: 'all', // status:   'all' | 'vacant' | 'occupied' | 'blocked'
    covers: null,       // guests on the current dine-in order (Gap 2)

    // Load lazily the first time (and on reopen) the Tables panel is shown.
    initTables() {
        this.$watch('activePanel', (v) => { if (v === 'tables') this.loadTables(); });
    },
    async loadTables() {
        const route = (window.PosBoot.routes || {}).tables;
        if (!route) return;
        this.tablesLoading = true;
        try {
            const r = await fetch(this.posUrl(route), { headers: { 'Accept': 'application/json' } });
            this.tablesData = r.ok ? (await r.json().catch(() => [])) : [];
        } catch (e) { this.tablesData = []; }
        this.tablesLoading = false;
        this.tablesLoaded = true;
    },

    setSeatFilter(f) { this.seatFilter = f; },
    seatMatch(t) {
        const s = Number(t.seats) || 0;
        switch (this.seatFilter) {
            case '1-2': return s >= 1 && s <= 2;
            case '4-6': return s >= 4 && s <= 6;
            case '6+':  return s > 6;
            default:    return true;
        }
    },
    // Gap 1: filter by table STATE (stored status parity with the Floor Map).
    setStateFilter(f) { this.stateFilter = f; },
    stateMatch(t) {
        switch (this.stateFilter) {
            case 'vacant':   return t.status === 'vacant';
            case 'reserved': return t.status === 'reserved';
            // PARITY-RUN T4: a table linked into a merged party is occupied in
            // every sense the cashier cares about — its guests are seated, its
            // bill just lives on the primary. `merged` also has its own chip.
            case 'occupied': return t.status === 'occupied' || t.status === 'served' || t.status === 'merged';
            case 'merged':   return !!t.merge_group_id;
            case 'blocked':  return t.status === 'blocked';
            default:         return true;
        }
    },
    filteredTables() { return this.tablesData.filter(t => this.seatMatch(t) && this.stateMatch(t)); },
    // [{zone, tables:[…]}] grouped, zone-less tables under a null bucket last.
    tableZones() {
        const groups = {};
        for (const t of this.filteredTables()) {
            const z = t.zone || '';
            (groups[z] = groups[z] || []).push(t);
        }
        return Object.keys(groups).sort().map(z => ({ zone: z, tables: groups[z] }));
    },
    tableCount() { return this.filteredTables().length; },

    // ── T3-3: assign / open-order ──
    table: null, // { no, name } — the assigned dine-in table (null = none)
    selectTable(t) {
        if (t.status === 'blocked') {
            this.showToast((window.PosBoot.i18n || {}).tableBlocked || 'Table unavailable (needs cleaning).', 'warn');
            return;
        }
        // Hotel: a guest ROOM takes a fresh room-service order every time — a stay
        // accrues many charges, so "open the one existing order" is wrong here.
        // Always start a new tab on the room; the settle sheet offers "Charge to room".
        if (t.is_room) { this.assignTable(t); return; }
        if (t.status === 'vacant' || t.status === 'reserved') { this.assignTable(t); return; }
        // Gap 3: occupied/served → open that table's live order (Proceed to Payment /
        // view). Editing its lines back into the cart is a larger seam (logged).
        this.openTableOrder(t);
    },
    assignTable(t) {
        this.type = 'on-table';
        this.table = {
            no: t.table_no,
            name: t.name,
            // Hotel: carry the room's charge-to-room eligibility onto the assigned
            // table so the settle sheet can offer the folio tender (see canChargeToRoom).
            isRoom: !!t.is_room,
            canChargeToRoom: !!t.can_charge_to_room,
            roomGuest: t.room_guest || null,
        };
        this.covers = null; // reset guests for the new dine-in order
        this.activePanel = 'home';
    },
    // Gap 3 (slice): pull up an occupied table's order in the detail panel, where
    // the cashier can Proceed to Payment (settle-existing) or Print.
    openTableOrder(t) {
        if (!t.order_id) { this.showToast((window.PosBoot.i18n || {}).tableOpen || 'This table has an open order.', 'warn'); return; }
        this.activePanel = 'orders';
        this.ordersTab = 'history';
        this.openOrderDetail({ id: t.order_id, total: t.order_total, table_number: t.table_no, is_paid: false });
    },
    // Gap 2: guests/covers picker (shown on the assigned-table chip in the cart).
    setCovers(n) { this.covers = (this.covers === n) ? null : n; },
    // Reservation clock (future time — ageChip clamps future to "now").
    bookedTime(iso) {
        if (!iso) return '';
        try { return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }); }
        catch (e) { return ''; }
    },
    changeTable() { this.activePanel = 'tables'; },      // tap chip → reopen picker
    releaseTable() { this.table = null; this.covers = null; this.type = 'takeaway'; },
};
