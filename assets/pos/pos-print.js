// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* ==========================================================================
   POS — direct printing (PARITY-RUN T1).

   The server renders ESC/POS bytes and parks them as jobs; this module is the
   half that can actually reach a printer, because the register is the page that
   holds the device handle:

     webserial / webusb — a Chrome/Edge device handle, paired once by the
                          operator (a user gesture is mandatory — the browser
                          will not hand out a port from a timer);
     rawbt              — an Android intent URL, fired via an anchor click;
     bridge             — HTTP to the desktop shell on 127.0.0.1:8543, exactly
                          per rev/parity/contracts/print-bridge.md;
     browser            — the pre-existing print-dialog fallback.

   Contract rules honoured here: /health is feature-detection, never an error
   (a missing bridge just means fewer targets); retries reuse the SAME jobId so
   the bridge de-dupes instead of printing a second kitchen ticket; failures are
   reported back with a reason from the closed vocabulary.
   ========================================================================== */
window.PosModules = window.PosModules || {};

window.PosModules.print = function () {
    const B = window.PosBoot || {};
    const R = B.routes || {};
    const T = B.i18n || {};

    return {
        // ── state ────────────────────────────────────────────────────────────
        printEnabled: false,
        printProfiles: [],
        printToken: null,
        printMenu: false,          // printer dropdown open
        printBusy: false,
        printPollTimer: null,
        printStatus: 'idle',       // idle | ok | error
        printLastError: '',
        bridgeHealth: {},          // bridgeUrl -> { ok, at, printers }
        serialPorts: {},           // profileId -> SerialPort
        usbDevices: {},            // profileId -> USBDevice

        // ── boot ─────────────────────────────────────────────────────────────
        async initPrint() {
            if (!R.printConfig) return;
            try {
                const res = await fetch(this.posUrl(R.printConfig), { headers: { 'Accept': 'application/json' } });
                if (!res.ok) return;
                const cfg = await res.json();
                this.printEnabled = !!cfg.enabled;
                this.printProfiles = cfg.profiles || [];
                this.printToken = cfg.token || null;
            } catch (e) { return; }

            if (!this.printEnabled) return;

            // Probe every configured bridge once at boot so the status chip is
            // honest before the first ticket rather than after it.
            for (const p of this.printProfiles) {
                if (p.transport === 'bridge') this.bridgeProbe(p.bridge_url);
            }

            this.printPoll();
            this.printPollTimer = setInterval(() => this.printPoll(), 5000);
        },

        // ── queue ────────────────────────────────────────────────────────────
        /** Collect the jobs the server has rendered for this register and run them. */
        async printPoll() {
            if (!this.printEnabled || this.printBusy || this.offline) return;
            this.printBusy = true;
            try {
                const res = await fetch(this.posUrl(R.printJobs), { headers: { 'Accept': 'application/json' } });
                if (!res.ok) return;
                const { jobs } = await res.json();
                for (const job of (jobs || [])) await this.printRun(job);
            } catch (e) {
                // A poll that fails is not a print that failed — stay quiet.
            } finally {
                this.printBusy = false;
            }
        },

        /** Deliver one job, then tell the server what happened. */
        async printRun(job) {
            const profile = this.printProfiles.find(p => p.id === job.profile_id) || {};
            const copies = Math.max(1, job.copies || 1);
            let result = { ok: false, error: 'internal' };

            try {
                for (let i = 0; i < copies; i++) {
                    result = await this.printDeliver(job, profile);
                    if (!result.ok) break;
                }
            } catch (e) {
                result = { ok: false, error: 'internal' };
            }

            this.printStatus = result.ok ? 'ok' : 'error';
            this.printLastError = result.ok ? '' : (result.error || 'internal');
            await this.printAck(job.id, result);
        },

        async printAck(jobId, result) {
            try {
                await this.post(this.posUrl(R.printAckBase + '/' + jobId + '/ack'), {
                    ok: !!result.ok, error: result.error || null,
                });
            } catch (e) { /* the server ages the claim out on its own */ }
        },

        /** Transport switch. Returns { ok, error }. */
        async printDeliver(job, profile) {
            switch (job.transport) {
                case 'bridge':    return this.printViaBridge(job, profile);
                case 'rawbt':     return this.printViaRawBt(job);
                case 'webserial': return this.printViaSerial(job, profile);
                case 'webusb':    return this.printViaUsb(job, profile);
                case 'browser':   return this.printViaBrowser(job);
                default:          return { ok: false, error: 'unknown_target' };
            }
        },

        // ── bridge (print-bridge.md) ─────────────────────────────────────────
        bridgeBase(url) { return (url || 'http://127.0.0.1:8543').replace(/\/$/, ''); },

        /** GET /health — feature detection. A missing bridge is never an error. */
        async bridgeProbe(url) {
            const base = this.bridgeBase(url);
            const cached = this.bridgeHealth[base];
            if (cached && (Date.now() - cached.at) < 30000) return cached;

            let health = { ok: false, at: Date.now(), printers: [] };
            try {
                const ctl = new AbortController();
                const t = setTimeout(() => ctl.abort(), 3000);
                const res = await fetch(base + '/health', {
                    headers: this.printToken ? { 'Authorization': 'Bearer ' + this.printToken } : {},
                    signal: ctl.signal,
                });
                clearTimeout(t);
                if (res.ok) {
                    const body = await res.json();
                    health = { ok: !!body.ok, at: Date.now(), printers: body.printers || [], version: body.version };
                }
            } catch (e) { /* no desktop app on this machine — expected */ }

            this.bridgeHealth[base] = health;
            return health;
        },

        /**
         * POST /print. 15 s timeout, then two retries with the SAME jobId (the
         * bridge replays the original result instead of printing again).
         */
        async printViaBridge(job, profile) {
            const base = this.bridgeBase(profile.bridge_url || job.bridge_url);
            const health = await this.bridgeProbe(base);
            if (!health.ok) return { ok: false, error: 'econnrefused' };

            const body = {
                jobId: job.job_key,
                target: job.target,
                widthMm: job.width_mm === 57 ? 57 : 80,
                dataB64: job.payload,
            };
            const backoff = [0, 500, 2000];

            for (let attempt = 0; attempt < backoff.length; attempt++) {
                if (backoff[attempt]) await new Promise(r => setTimeout(r, backoff[attempt]));
                try {
                    const ctl = new AbortController();
                    const t = setTimeout(() => ctl.abort(), 15000);
                    const res = await fetch(base + '/print', {
                        method: 'POST',
                        headers: Object.assign({ 'Content-Type': 'application/json' },
                            this.printToken ? { 'Authorization': 'Bearer ' + this.printToken } : {}),
                        body: JSON.stringify(body),
                        signal: ctl.signal,
                    });
                    clearTimeout(t);

                    // Status codes are protocol problems and are terminal.
                    if (res.status === 401) return { ok: false, error: 'unauthorized' };
                    if (res.status === 409) return { ok: false, error: 'unpaired' };
                    if (res.status === 400) return { ok: false, error: 'bad_request' };
                    if (res.status === 413) return { ok: false, error: 'payload_too_large' };

                    const out = await res.json();
                    if (out && out.ok) return { ok: true };
                    const err = (out && out.error) || 'internal';
                    if (err !== 'etimedout' && err !== 'device_busy') return { ok: false, error: err };
                } catch (e) { /* timeout / refused — retry with the same jobId */ }
            }

            // Re-probe after a failure so the chip stops claiming the bridge is up.
            delete this.bridgeHealth[base];
            return { ok: false, error: 'etimedout' };
        },

        // ── RawBT (Android) ──────────────────────────────────────────────────
        /** Fires the intent URL. RawBT cannot report back, so this is optimistic. */
        printViaRawBt(job) {
            try {
                const a = document.createElement('a');
                a.href = 'intent:base64,' + job.payload
                    + '#Intent;scheme=rawbt;package=ru.a402d.rawbtprinter;end;';
                a.style.display = 'none';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                return { ok: true };
            } catch (e) {
                return { ok: false, error: 'internal' };
            }
        },

        // ── Web Serial ───────────────────────────────────────────────────────
        /** Pair a serial printer. MUST be called from a click — browsers require a gesture. */
        async pairSerial(profileId) {
            if (!('serial' in navigator)) { this.toastMsg(T.printNoSerial); return false; }
            try {
                const port = await navigator.serial.requestPort();
                await port.open({ baudRate: 9600 });
                this.serialPorts[profileId] = port;
                this.toastMsg(T.printerPaired);
                return true;
            } catch (e) { return false; }
        },

        async printViaSerial(job, profile) {
            if (!('serial' in navigator)) return { ok: false, error: 'unknown_target' };
            let port = this.serialPorts[job.profile_id];

            // A previously granted port can be reopened without a gesture.
            if (!port) {
                try {
                    const ports = await navigator.serial.getPorts();
                    port = ports[0];
                    if (port) { await port.open({ baudRate: 9600 }); this.serialPorts[job.profile_id] = port; }
                } catch (e) { port = null; }
            }
            if (!port) return { ok: false, error: 'unknown_target' };

            try {
                const writer = port.writable.getWriter();
                await writer.write(this.b64ToBytes(job.payload));
                writer.releaseLock();
                return { ok: true };
            } catch (e) {
                return { ok: false, error: 'device_busy' };
            }
        },

        // ── WebUSB ───────────────────────────────────────────────────────────
        async pairUsb(profileId) {
            if (!('usb' in navigator)) { this.toastMsg(T.printNoUsb); return false; }
            try {
                // Class 7 = printer.
                const device = await navigator.usb.requestDevice({ filters: [{ classCode: 7 }] });
                await device.open();
                if (device.configuration === null) await device.selectConfiguration(1);
                await device.claimInterface(this.usbInterface(device));
                this.usbDevices[profileId] = device;
                this.toastMsg(T.printerPaired);
                return true;
            } catch (e) { return false; }
        },

        usbInterface(device) {
            const ifaces = (device.configuration && device.configuration.interfaces) || [];
            for (const i of ifaces) {
                if (i.alternate && i.alternate.interfaceClass === 7) return i.interfaceNumber;
            }
            return 0;
        },

        async printViaUsb(job) {
            const device = this.usbDevices[job.profile_id];
            if (!device) return { ok: false, error: 'unknown_target' };
            try {
                const ep = (device.configuration.interfaces[0].alternate.endpoints || [])
                    .find(e => e.direction === 'out');
                await device.transferOut(ep ? ep.endpointNumber : 1, this.b64ToBytes(job.payload));
                return { ok: true };
            } catch (e) {
                return { ok: false, error: 'device_busy' };
            }
        },

        // ── browser dialog ───────────────────────────────────────────────────
        /** No device handle — fall back to the printable receipt page. */
        printViaBrowser(job) {
            if (!job.order_id) return { ok: true }; // nothing sensible to open (test page)
            const q = '?store=' + B.storeId + '&print=1&lang=' + (B.lang || '')
                + (job.event === 'kot' ? '&format=kitchen' : '');
            window.open(this.posUrl(R.ordersBase + '/' + job.order_id + '/receipt') + q, '_blank');
            return { ok: true };
        },

        // ── operator actions ─────────────────────────────────────────────────
        /** Test page for one profile (also the moment to pair a device handle). */
        async testPrinter(profile) {
            if (profile.transport === 'webserial' && !this.serialPorts[profile.id]) await this.pairSerial(profile.id);
            if (profile.transport === 'webusb' && !this.usbDevices[profile.id]) await this.pairUsb(profile.id);
            try {
                await this.post(this.posUrl(R.printTestBase + '/' + profile.id), {});
                this.printPoll();
            } catch (e) { /* the chip shows the failure once the job runs */ }
        },

        /** Manual print of the last order to a chosen printer. */
        async printLastTo(profile, event) {
            if (!this.lastOrderId) return;
            try {
                await this.post(this.posUrl(R.printOrderBase + '/' + this.lastOrderId), {
                    profile_id: profile.id, event: event || 'new_order',
                });
                this.printMenu = false;
                this.printPoll();
            } catch (e) { /* reported through the status chip */ }
        },

        /**
         * Human label for the status chip. Deliberately "<n> Printers" rather than a
         * singular/plural pair: ___() slugs case-insensitively, so a lowercase
         * 'printers' would collide with the settings page's "Printers" heading and
         * the two would share one translation.
         */
        printChipLabel() {
            if (!this.printEnabled) return '';
            if (this.printStatus === 'error') return (T.printFailed || 'Print failed');
            return this.printProfiles.length + ' ' + (T.printersLabel || 'Printers');
        },

        // ── utils ────────────────────────────────────────────────────────────
        b64ToBytes(b64) {
            const bin = atob(b64 || '');
            const out = new Uint8Array(bin.length);
            for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
            return out;
        },

        toastMsg(msg) {
            if (!msg) return;
            this.toast = { show: true, msg: msg, type: 'info' };
            setTimeout(() => { this.toast.show = false; }, 2500);
        },
    };
};
