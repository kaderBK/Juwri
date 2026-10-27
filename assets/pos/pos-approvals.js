// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* ==========================================================================
   PARITY-RUN T3 — POS staff-approval queue. T3 zone.

   Registers on window.PosModules; the register factory in pos-core.js composes
   it over the core state. Boots itself from an x-init on the Orders rail item
   (pos-core.js is not a T3 zone, so it gains no new init call), then polls the
   queue so the rail badge stays honest while the panel is closed.

   The queue only exists while the store runs `staff_approve` table verification.
   The endpoint reports the effective mode, so the tab hides itself the moment
   the owner switches modes — no page reload required.
   ========================================================================== */
window.PosModules = window.PosModules || {};

window.PosModules.approvals = {
    approvals: [],
    approvalsMode: 'off',
    approvalsLoading: false,
    _approvalsTimer: null,

    initApprovals() {
        if (this._approvalsTimer) return;   // x-init can fire more than once
        this.loadApprovals();
        this._approvalsTimer = setInterval(() => this.loadApprovals(), 30000);
    },

    async loadApprovals() {
        const url = (window.PosBoot.routes || {}).ordersApprovals;
        if (!url) return;
        this.approvalsLoading = true;
        try {
            const r = await fetch(this.posUrl(url), { headers: { Accept: 'application/json' } });
            const j = r.ok ? await r.json().catch(() => null) : null;
            this.approvals = (j && j.orders) || [];
            this.approvalsMode = (j && j.mode) || 'off';
        } catch (e) {
            // A dead network must never blank an already-shown queue.
        }
        this.approvalsLoading = false;
    },

    approveOrder(o) { return this._decide(o, 'approve'); },

    rejectOrder(o) {
        const T = window.PosBoot.i18n || {};
        if (!window.confirm(T.rejectConfirm || 'Reject this order?')) return;
        return this._decide(o, 'reject');
    },

    async _decide(o, action) {
        const base = (window.PosBoot.routes || {}).ordersBase;
        if (!base || !o) return;
        const T = window.PosBoot.i18n || {};
        try {
            const r = await fetch(this.posUrl(base + '/' + o.id + '/' + action), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': this.csrf,
                    Accept: 'application/json',
                },
            });
            if (!r.ok) throw new Error('http');
            // Drop it locally first so the badge reacts instantly, then re-sync.
            this.approvals = (this.approvals || []).filter((x) => x.id !== o.id);
            if (this.showToast) {
                this.showToast(action === 'approve' ? (T.orderApproved || 'Order approved.') : (T.orderRejected || 'Order rejected.'), 'info');
            }
        } catch (e) {
            if (this.showToast) this.showToast(T.networkError || 'Network error.', 'error');
        }
        this.loadApprovals();
    },
};
