// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* ==========================================================================
   POS — offline till identity (offline POS spec §6; ops: docs/ops/offline-pos.md). This device registers once as an
   offline till of the store (device id + token in localStorage, per store) and decides on every start what to keep:

     server says wipe (revoked, or the owner asked) → upload the unsynced queue FIRST, then wipe everything
                                                      (queue, offline caches, PIN data, the token) and report it;
     no server contact for 3 days (lease over)     → wipe the catalogue snapshot and the PIN data, block new sales,
                                                      KEEP the encrypted unsynced queue until the till reaches the
                                                      server again (owner Q5: never deleted silently);
     an unsynced record older than 7 days          → block new sales until it syncs (nothing is deleted).

   The decision is the pure function TillPolicy.onStart (tested in tests/js/pos); the module below carries it out.
   ========================================================================== */
(function (root) {
    'use strict';
    var DAY = 86400000;
    var TillPolicy = {
        /**
         * @param {{online: boolean, serverWipe: boolean, leaseUntil: (string|null), oldestQueuedAt: (number|null), retentionDays: number, now: number}} s
         * @returns {{upload: boolean, wipeQueue: boolean, wipeSnapshot: boolean, wipePins: boolean, blockSales: (string|null), report: boolean}}
         */
        onStart: function (s) {
            var out = { upload: false, wipeQueue: false, wipeSnapshot: false, wipePins: false, blockSales: null, report: false };
            if (s.online && s.serverWipe) {
                // upload first; only what was uploaded may go (the module wipes the queue only after a full flush)
                out.upload = true; out.wipeQueue = true; out.wipeSnapshot = true; out.wipePins = true; out.report = true;
                out.blockSales = 'wiped';
                return out;
            }
            if (s.online) {
                out.upload = true;
            } else if (s.leaseUntil && Date.parse(s.leaseUntil) < s.now) {
                out.wipeSnapshot = true; out.wipePins = true; out.blockSales = 'lease';
            }
            if (!out.blockSales && s.oldestQueuedAt !== null && s.now - s.oldestQueuedAt > (s.retentionDays || 7) * DAY) {
                out.blockSales = 'retention';
            }
            return out;
        },
    };
    if (typeof module === 'object' && module.exports) { module.exports = TillPolicy; return; }
    root.TillPolicy = TillPolicy;

    root.PosModules = root.PosModules || {};
    root.PosModules.device = {
        salesBlocked: null, // 'lease' | 'retention' | 'wiped' | null — the register refuses new sales while set
        _deviceKey() { return 'pos_offline_device_' + root.PosBoot.storeId; },
        _device() { try { return JSON.parse(localStorage.getItem(this._deviceKey()) || 'null'); } catch (e) { return null; } },
        _saveDevice(d) { localStorage.setItem(this._deviceKey(), JSON.stringify(d)); },
        _deviceHeaders(d) { return { 'X-Pos-Device': d.id, 'X-Pos-Device-Token': d.token, 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf }; },

        /** Register this browser as a till of the store (online, once). Returns the device or null. */
        async ensureDevice() {
            const routes = root.PosBoot.routes || {};
            const known = this._device();
            if (known || !routes.offlineDevices || !navigator.onLine) return known;
            const id = 'till-' + (crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + Math.random().toString(16).slice(2));
            const r = await this.post(this.posUrl(routes.offlineDevices), { device_id: id.slice(0, 64), platform: 'web' }, { queue: false });
            if (!(r.ok && r.data && r.data.token)) return null;
            const d = { id: r.data.device_id, token: r.data.token, leaseUntil: r.data.lease_until, retentionDays: r.data.retention_days };
            this._saveDevice(d);
            return d;
        },

        async initDevice() {
            const routes = root.PosBoot.routes || {};
            if (!routes.offlineDevices) return;
            const d = this._device() || await this.ensureDevice();
            if (!d) return;
            let online = false, serverWipe = false;
            try {
                const res = await fetch(this.posUrl(routes.offlineDevicesStatus), { headers: this._deviceHeaders(d) });
                if (res.status === 401) { localStorage.removeItem(this._deviceKey()); return; } // unknown: register again next start
                const st = await res.json();
                online = true; serverWipe = !!st.wipe;
                if (!serverWipe) { d.leaseUntil = st.lease_until; d.retentionDays = st.retention_days; this._saveDevice(d); }
            } catch (e) { /* offline */ }
            const queued = await this.idbAll().catch(() => []);
            const oldest = queued.reduce((m, it) => (it.ts && (m === null || it.ts < m) ? it.ts : m), null);
            const plan = TillPolicy.onStart({ online, serverWipe, leaseUntil: d.leaseUntil || null, oldestQueuedAt: oldest, retentionDays: d.retentionDays || 7, now: Date.now() });
            await this.applyTillPlan(d, plan);
        },

        async applyTillPlan(d, plan) {
            if (plan.upload) { try { await this.flush(); } catch (e) {} }
            if (plan.wipeSnapshot || plan.wipePins) {
                try { for (const k of await caches.keys()) { if (k.startsWith('pos-')) await caches.delete(k); } } catch (e) {} // catalogue + register shells
                try { localStorage.removeItem('pos_offline_snapshot_' + root.PosBoot.storeId); } catch (e) {} // snapshot incl. PIN verifiers
                if (this.tillStore) { try { await this.tillStore.wipePins(); } catch (e) {} } // PIN wraps; the encrypted queue stays
            }
            if (plan.wipeQueue) {
                const left = await this.idbAll().catch(() => []);
                if (left.length) { this.salesBlocked = 'wiped'; return; } // upload not finished: never drop unsynced records; retried next start
                if (this.tillStore) { try { await this.tillStore.wipeAll(); } catch (e) {} }
                try { indexedDB.deleteDatabase('pos-queue'); } catch (e) {}
                this.queued = 0;
            }
            if (plan.report) {
                try { await fetch(this.posUrl((root.PosBoot.routes || {}).offlineDevicesWiped), { method: 'POST', headers: this._deviceHeaders(d) }); } catch (e) {}
                localStorage.removeItem(this._deviceKey());
            }
            this.salesBlocked = plan.blockSales;
        },
    };
}(typeof self !== 'undefined' ? self : this));
