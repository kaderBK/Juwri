// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* ==========================================================================
   POS — D8: terminal registry. This device registers itself once (persisted in
   localStorage so the same till reuses its row), then heartbeats last_seen while
   the register is open. The owner renames / pins a printer / retires it from POS
   settings. Same module contract as the other pos-*.js files (a PosModules entry
   composed over the core factory).
   ========================================================================== */
window.PosModules = window.PosModules || {};

window.PosModules.terminal = {
    terminalId: null,
    _terminalHb: null,

    async initTerminal() {
        const routes = window.PosBoot.routes || {};
        if (!routes.terminalsBase) return;
        const key = 'pos_terminal_' + window.PosBoot.storeId;

        this.terminalId = parseInt(localStorage.getItem(key) || '0', 10) || null;

        if (!this.terminalId) {
            // Auto-adopt with a device-derived name; the owner can rename it later.
            const plat = (navigator.userAgentData && navigator.userAgentData.platform) || 'Register';
            const name = (plat + ' ' + new Date().toLocaleDateString()).slice(0, 80);
            try {
                const r = await this.post(this.posUrl(routes.terminalsBase), { name });
                if (r.ok && r.data && r.data.id) {
                    this.terminalId = r.data.id;
                    localStorage.setItem(key, String(this.terminalId));
                }
            } catch (e) {}
        }

        this.heartbeatTerminal();
        // Heartbeat every 60s so last_seen tracks a live register.
        this._terminalHb = setInterval(() => this.heartbeatTerminal(), 60000);
    },

    async heartbeatTerminal() {
        const base = (window.PosBoot.routes || {}).terminalsBase;
        if (!base || !this.terminalId) return;
        try {
            await this.post(this.posUrl(base + '/' + this.terminalId + '/heartbeat'), {}, { queue: false });
        } catch (e) {}
    },

    // D8: the terminal id to tag print-config resolution with (printer override).
    terminalQuery() {
        return this.terminalId ? ('&terminal_id=' + this.terminalId) : '';
    },
};
