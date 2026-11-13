// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* ==========================================================================
   POS — the browser till's encrypted offline store (offline POS spec §6, OP-8).

   Everything a browser till keeps offline (the outbox of sales / mutations waiting for the server) is encrypted with
   AES-GCM-256 under one random data key (DEK) that never touches storage in the clear. The DEK is stored only WRAPPED:
     - by the till's escrow key, which the server hands to this registered till only online, to a signed-in staff session
       (so after a reload online the till opens its data without anyone typing a PIN);
     - by each staff member's PIN (PBKDF2-SHA256, 210,000 rounds, per-wrap random salt), written when that staff member
       signs in on this till while the store is open — so offline, a PIN opens the store (a wrong PIN fails the AES-GCM
       tag check: nothing is decrypted and nothing is learned).
   Outbox metadata (id, state, enqueue time, attempts) stays readable so the retention / lease rules work while locked;
   the content (url, body) is encrypted and bound to its id (AES-GCM additional data), so rows cannot be swapped.

   Outbox states:  queued → sending → done (deleted) | failed (kept, shown, retried) ; sending → queued on a network error.
   Nothing is ever deleted unless the server confirmed it (2xx, or 409 = the key was already used: replay can never
   succeed) or a wipe the server asked for runs after the queue is empty.

   The old unencrypted `pos-queue` IndexedDB is migrated: each item is written encrypted into the outbox FIRST, then
   removed from the old store; the old database is deleted only when it is empty.

   UMD: the browser gets window.TillStore; node tests get module.exports (with MemoryBackend).
   ========================================================================== */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) module.exports = factory();
    else root.TillStore = factory();
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';
    var subtle = (typeof crypto !== 'undefined' && crypto.subtle) ? crypto.subtle : null;
    var enc = new TextEncoder();
    var dec = new TextDecoder();
    var PIN_ROUNDS = 210000;

    function b64(buf) {
        var bytes = new Uint8Array(buf), s = '';
        for (var i = 0; i < bytes.length; i++) s += String.fromCharCode(bytes[i]);
        return btoa(s);
    }
    function unb64(str) {
        var s = atob(str), out = new Uint8Array(s.length);
        for (var i = 0; i < s.length; i++) out[i] = s.charCodeAt(i);
        return out;
    }
    function rnd(n) { var a = new Uint8Array(n); crypto.getRandomValues(a); return a; }

    // ── crypto ──────────────────────────────────────────────────────────────
    var TillCrypto = {
        newDek: function () { return subtle.generateKey({ name: 'AES-GCM', length: 256 }, true, ['encrypt', 'decrypt']); },
        importRaw: function (raw) { return subtle.importKey('raw', raw, { name: 'AES-GCM' }, false, ['encrypt', 'decrypt', 'wrapKey', 'unwrapKey']); },
        pinKek: async function (pin, salt, rounds) {
            var base = await subtle.importKey('raw', enc.encode(String(pin)), 'PBKDF2', false, ['deriveKey']);
            return subtle.deriveKey({ name: 'PBKDF2', salt: salt, iterations: rounds || PIN_ROUNDS, hash: 'SHA-256' }, base,
                { name: 'AES-GCM', length: 256 }, false, ['wrapKey', 'unwrapKey']);
        },
        wrap: async function (dek, kek, aad) {
            var iv = rnd(12);
            var w = await subtle.wrapKey('raw', dek, kek, { name: 'AES-GCM', iv: iv, additionalData: enc.encode(aad) });
            return { iv: b64(iv), wrapped: b64(w) };
        },
        unwrap: function (box, kek, aad) {
            return subtle.unwrapKey('raw', unb64(box.wrapped), kek, { name: 'AES-GCM', iv: unb64(box.iv), additionalData: enc.encode(aad) },
                { name: 'AES-GCM', length: 256 }, true, ['encrypt', 'decrypt']);
        },
        seal: async function (dek, obj, aad) {
            var iv = rnd(12);
            var ct = await subtle.encrypt({ name: 'AES-GCM', iv: iv, additionalData: enc.encode(aad) }, dek, enc.encode(JSON.stringify(obj)));
            return { iv: b64(iv), ct: b64(ct) };
        },
        open: async function (dek, box, aad) {
            var pt = await subtle.decrypt({ name: 'AES-GCM', iv: unb64(box.iv), additionalData: enc.encode(aad) }, dek, unb64(box.ct));
            return JSON.parse(dec.decode(pt));
        },
    };

    // ── outbox state machine ────────────────────────────────────────────────
    var TRANSITIONS = {
        queued: { send: 'sending' },
        sending: { ok: 'done', duplicate: 'done', conflict_key: 'done', network: 'queued', refused: 'failed' },
        failed: { retry: 'queued' },
    };
    function nextState(state, event) {
        var to = (TRANSITIONS[state] || {})[event];
        if (!to) throw new Error('outbox: ' + event + ' is not allowed from ' + state);
        return to;
    }

    // ── backends ────────────────────────────────────────────────────────────
    /** In-memory backend (tests). Stores: outbox, keys, meta. */
    function MemoryBackend() { this.s = { outbox: new Map(), keys: new Map(), meta: new Map() }; }
    MemoryBackend.prototype.get = async function (store, k) { return this.s[store].get(k); };
    MemoryBackend.prototype.put = async function (store, k, v) { this.s[store].set(k, JSON.parse(JSON.stringify(v))); };
    MemoryBackend.prototype.del = async function (store, k) { this.s[store].delete(k); };
    MemoryBackend.prototype.all = async function (store) { return Array.from(this.s[store].entries()).map(function (e) { return { k: e[0], v: e[1] }; }); };
    MemoryBackend.prototype.clear = async function (store) { this.s[store].clear(); };
    /** Several writes as ONE unit: all land or none (ops: [{store, k, v}]). */
    MemoryBackend.prototype.batch = async function (ops) {
        var self = this, before = ops.map(function (o) { return [o.store, o.k, self.s[o.store].has(o.k), self.s[o.store].get(o.k)]; });
        try { for (var i = 0; i < ops.length; i++) await self.put(ops[i].store, ops[i].k, ops[i].v); }
        catch (e) { before.reverse().forEach(function (b) { if (b[2]) self.s[b[0]].set(b[1], b[3]); else self.s[b[0]].delete(b[1]); }); throw e; }
    };
    MemoryBackend.prototype.destroy = async function () { var self = this; Object.keys(this.s).forEach(function (k) { self.s[k].clear(); }); };

    /** IndexedDB backend (browser): one database per store, `pos-till-<storeId>`. */
    function IdbBackend(name) { this.name = name; this.db = null; }
    IdbBackend.prototype.open = function () {
        var self = this;
        if (self.db) return Promise.resolve(self.db);
        return new Promise(function (res, rej) {
            var r = indexedDB.open(self.name, 1);
            r.onupgradeneeded = function () { ['outbox', 'keys', 'meta'].forEach(function (s) { r.result.createObjectStore(s); }); };
            r.onsuccess = function () { self.db = r.result; res(self.db); };
            r.onerror = function () { rej(r.error); };
        });
    };
    // Every transaction settles: complete → result; error / abort (e.g. QuotaExceededError when the disk is full) or a
    // throw while starting it → rejected; and a watchdog, so a sale can never wait forever on storage.
    IdbBackend.prototype._tx = async function (store, mode, fn) {
        var db = await this.open();
        return new Promise(function (res, rej) {
            var done = false, timer = setTimeout(function () { fail(new Error('storage did not answer')); }, 10000);
            function fail(err) { if (done) return; done = true; clearTimeout(timer); rej(err || new Error('storage error')); }
            var t, out;
            try {
                t = db.transaction(store, mode);
                out = fn(t.objectStore(store));
            } catch (e) { return fail(e); }
            t.oncomplete = function () { if (done) return; done = true; clearTimeout(timer); res(out && 'result' in out ? out.result : out && out.both ? { keys: out.both[0].result, vals: out.both[1].result } : undefined); };
            t.onerror = function () { fail(t.error); };
            t.onabort = function () { fail(t.error); };
        });
    };
    // one transaction over several stores: the browser itself guarantees all-or-nothing (and a full disk aborts it whole)
    IdbBackend.prototype.batch = async function (ops) {
        var db = await this.open();
        var names = Array.from(new Set(ops.map(function (o) { return o.store; })));
        return new Promise(function (res, rej) {
            var done = false, timer = setTimeout(function () { fail(new Error('storage did not answer')); }, 10000);
            function fail(err) { if (done) return; done = true; clearTimeout(timer); rej(err || new Error('storage error')); }
            var t;
            try {
                t = db.transaction(names, 'readwrite');
                ops.forEach(function (o) { t.objectStore(o.store).put(o.v, o.k); });
            } catch (e) { return fail(e); }
            t.oncomplete = function () { if (done) return; done = true; clearTimeout(timer); res(); };
            t.onerror = function () { fail(t.error); };
            t.onabort = function () { fail(t.error); };
        });
    };
    IdbBackend.prototype.get = function (store, k) { return this._tx(store, 'readonly', function (s) { return s.get(k); }); };
    IdbBackend.prototype.put = function (store, k, v) { return this._tx(store, 'readwrite', function (s) { s.put(v, k); }); };
    IdbBackend.prototype.del = function (store, k) { return this._tx(store, 'readwrite', function (s) { s.delete(k); }); };
    IdbBackend.prototype.clear = function (store) { return this._tx(store, 'readwrite', function (s) { s.clear(); }); };
    IdbBackend.prototype.all = async function (store) {
        // keys and values from ONE transaction: read in two, a write in between misaligned them (rows without a value)
        var r = await this._tx(store, 'readonly', function (s) { return { both: [s.getAllKeys(), s.getAll()] }; });
        var keys = (r && r.keys) || [], vals = (r && r.vals) || [];
        return keys.map(function (k, i) { return { k: k, v: vals[i] }; });
    };
    IdbBackend.prototype.destroy = function () {
        var self = this;
        if (self.db) { self.db.close(); self.db = null; }
        return new Promise(function (res) { var r = indexedDB.deleteDatabase(self.name); r.onsuccess = r.onerror = r.onblocked = function () { res(); }; });
    };

    // an outbox row with its value (a half-read or foreign entry is skipped, never a TypeError)
    function isRow(r) { return !!(r && r.v && typeof r.v.id === 'number'); }

    // ── the store ───────────────────────────────────────────────────────────
    function Store(backend, opts) {
        this.b = backend;
        this.opts = opts || {};
        this.dek = null;          // in memory only, while open
        this.pinRounds = this.opts.pinRounds || PIN_ROUNDS;
    }
    Store.prototype.isOpen = function () { return this.dek !== null; };
    Store.prototype.lock = function () { this.dek = null; };

    /** Online: open with the till's escrow key (base64 raw 32 bytes) — creating the DEK the first time. */
    Store.prototype.openWithEscrow = async function (escrowB64) {
        var kek = await TillCrypto.importRaw(unb64(escrowB64));
        var box = await this.b.get('keys', 'escrow');
        if (box) {
            this.dek = await TillCrypto.unwrap(box, kek, 'escrow'); // throws if the escrow key does not match
            return true;
        }
        var existing = await this.b.all('outbox');
        if (existing.length) throw new Error('till store: encrypted records exist but no escrow wrap — refusing to start a new key');
        this.dek = await TillCrypto.newDek();
        await this.b.put('keys', 'escrow', await TillCrypto.wrap(this.dek, kek, 'escrow'));
        return true;
    };

    /** Store this staff member's PIN wrap (needs the store open). */
    Store.prototype.rememberPin = async function (staffId, pin) {
        if (!this.dek) throw new Error('till store is locked');
        var salt = rnd(16);
        var kek = await TillCrypto.pinKek(pin, salt, this.pinRounds);
        var box = await TillCrypto.wrap(this.dek, kek, 'pin:' + staffId);
        await this.b.put('keys', 'pin:' + staffId, { salt: b64(salt), rounds: this.pinRounds, iv: box.iv, wrapped: box.wrapped });
    };

    /** Offline: open with a staff PIN. Returns the staff id it matched, or null (wrong PIN / no wrap). */
    Store.prototype.openWithPin = async function (pin, staffId) {
        var rows = (await this.b.all('keys')).filter(function (r) { return String(r.k).indexOf('pin:') === 0 && (staffId == null || r.k === 'pin:' + staffId); });
        for (var i = 0; i < rows.length; i++) {
            try {
                var kek = await TillCrypto.pinKek(pin, unb64(rows[i].v.salt), rows[i].v.rounds);
                this.dek = await TillCrypto.unwrap(rows[i].v, kek, rows[i].k);
                return Number(String(rows[i].k).slice(4));
            } catch (e) { /* not this one */ }
        }
        return null;
    };

    Store.prototype._nextId = async function () {
        var n = Number((await this.b.get('meta', 'seq')) || 0) + 1;
        await this.b.put('meta', 'seq', n);
        return n;
    };

    /** Queue a mutation {url, body} (or a typed record later). Encrypted; never stored in the clear. */
    Store.prototype.add = async function (item, ts) {
        if (!this.dek) throw new Error('till store is locked');
        var self = this;
        // the id counter is read and bumped inside the same writer chain as addRecords, so a raw add and a replay batch never share a row id
        var run = (this._writing || Promise.resolve()).then(async function () {
            var id = await self._nextId();
            var box = await TillCrypto.seal(self.dek, item, 'outbox:' + id);
            // the endpoint path only (no host, no query, no body) stays readable, so a locked till can still count its orders
            var path = ''; try { path = new URL(String(item.url || ''), 'http://x').pathname; } catch (e) { /* not a URL */ }
            await self.b.put('outbox', id, { id: id, state: 'queued', ts: ts || Date.now(), attempts: 0, path: path, rec: !!item.record, box: box });
            return id;
        });
        this._writing = run.catch(function () {});
        return run;
    };

    /** Metadata of every outbox row (readable while locked: id, state, ts, attempts, endpoint path). */
    Store.prototype.meta = async function () {
        return (await this.b.all('outbox')).filter(isRow).map(function (r) { return { id: r.v.id, state: r.v.state, ts: r.v.ts, attempts: r.v.attempts, path: r.v.path || '' }; })
            .sort(function (a, b) { return a.id - b.id; });
    };

    /** Decrypted rows (store must be open). */
    Store.prototype.items = async function () {
        if (!this.dek) throw new Error('till store is locked');
        var out = [], rows = (await this.b.all('outbox')).filter(isRow).sort(function (a, b) { return a.v.id - b.v.id; });
        for (var i = 0; i < rows.length; i++) {
            var v = rows[i].v;
            out.push(Object.assign({ id: v.id, state: v.state, ts: v.ts, attempts: v.attempts }, await TillCrypto.open(this.dek, v.box, 'outbox:' + v.id)));
        }
        return out;
    };

    Store.prototype.transition = async function (id, event) {
        var row = await this.b.get('outbox', id);
        if (!row) throw new Error('outbox: no row ' + id);
        var to = nextState(row.state, event);
        if (to === 'done') { await this.b.del('outbox', id); return 'done'; }
        row.state = to;
        if (event === 'send') row.attempts = (row.attempts || 0) + 1;
        await this.b.put('outbox', id, row);
        return to;
    };

    /** Remove one queued row on purpose (cashier discards an unsent order) — only rows not being sent. */
    Store.prototype.discard = async function (id) {
        var row = await this.b.get('outbox', id);
        if (row && row.state !== 'sending' && !row.rec) await this.b.del('outbox', id); // a typed record (sale / tender / void) is never dropped on its own
    };

    /**
     * Send what is queued, in order, with fetchFn(url, body) → {status} (throws on a network error).
     * 2xx / 409 → done (deleted); network error → back to queued and STOP (still offline); other status → failed (kept).
     */
    Store.prototype.flush = async function (fetchFn) {
        if (!this.dek) return { sent: 0, failed: 0, left: (await this.meta()).length, locked: true };
        var sent = 0, failed = 0, rows = await this.items();
        for (var i = 0; i < rows.length; i++) {
            var it = rows[i];
            if (it.record) continue; // typed replay records go to flushRecords(), never as a raw POST
            if (it.state === 'failed') await this.transition(it.id, 'retry');
            if (it.state === 'sending') await this.b.put('outbox', it.id, Object.assign(await this.b.get('outbox', it.id), { state: 'queued' })); // a crash mid-send
            await this.transition(it.id, 'send');
            var res;
            try { res = await fetchFn(it.url, it.body); } catch (e) { await this.transition(it.id, 'network'); break; }
            if (res.status >= 200 && res.status < 300) { await this.transition(it.id, 'ok'); sent++; }
            else if (res.status === 409) { await this.transition(it.id, 'conflict_key'); sent++; }
            else { await this.transition(it.id, 'refused'); failed++; }
        }
        return { sent: sent, failed: failed, left: (await this.meta()).length, locked: false };
    };

    // ── typed replay records (offline POS spec §3, OP-9) ─────────────────────────────────────────────────────────────
    /** The replay endpoint path every typed record is filed under (readable while locked, so orders can be counted). */
    var REPLAY_PATH = '/pos/offline/replay';
    var FINAL = { accepted: 1, duplicate: 1, conflict: 1, rejected: 1 };

    /** Next gap-free per-till sequence number (persisted). */
    Store.prototype._nextSeq = async function () {
        var n = Number((await this.b.get('meta', 'devseq')) || 0) + 1;
        await this.b.put('meta', 'devseq', n);
        return n;
    };

    /**
     * Queue typed records atomically ({key, type, staff_id, snapshot_version, created_at, payload}; seq is assigned here).
     * All of them are written or none: a failure part-way deletes what was written, so a sale never sits without its tender.
     * A key already in the outbox is refused (the UUIDv7 is created once per record and reused by retries).
     */
    Store.prototype.addRecords = async function (records, ts) {
        if (!this.dek) throw new Error('till store is locked');
        var self = this;
        // one writer at a time inside this tab (two taps can never read the same counter)
        var run = (this._writing || Promise.resolve()).then(async function () {
            var existing = (await self.items()).filter(function (r) { return r.record; }).map(function (r) { return r.body.key; });
            var id = Number((await self.b.get('meta', 'seq')) || 0), seq = Number((await self.b.get('meta', 'devseq')) || 0), ops = [], ids = [];
            for (var i = 0; i < records.length; i++) {
                var rec = Object.assign({}, records[i]);
                if (existing.indexOf(rec.key) !== -1) throw new Error('outbox: key ' + rec.key + ' is already queued');
                rec.seq = ++seq; id++;
                var item = { url: REPLAY_PATH, body: rec, record: true };
                var box = await TillCrypto.seal(self.dek, item, 'outbox:' + id);
                ops.push({ store: 'outbox', k: id, v: { id: id, state: 'queued', ts: ts || Date.now(), attempts: 0, path: REPLAY_PATH, rec: true, box: box } });
                ids.push(id);
            }
            // rows + both counters in ONE transaction: a sale never exists without its tender, and no sequence number is lost
            ops.push({ store: 'meta', k: 'seq', v: id }, { store: 'meta', k: 'devseq', v: seq });
            await self.b.batch(ops);
            return ids;
        });
        this._writing = run.catch(function () {});
        return run;
    };

    /** Decrypted typed records still in the outbox, in sequence order. */
    Store.prototype.records = async function () {
        return (await this.items()).filter(function (r) { return r.record; });
    };

    /**
     * Send queued typed records in order, up to `batchSize` per request, with sendBatch(records) →
     * {status, data:{results:[{key, status, reason}]}} (throws on a network error).
     * Per record: accepted / duplicate / conflict / rejected are the server's final answers (stored there, shown to the
     * manager in the offline exceptions screen) → the local copy is removed; conflict / rejected are also listed in
     * meta `review` so the register can say how many sales need a manager. waiting / error / unanswered → back to queued.
     * A request-level failure (network, 5xx, 401) → back to queued and stop; 422 (invalid batch) → kept as failed.
     */
    Store.prototype.flushRecords = async function (sendBatch, batchSize) {
        if (!this.dek) return { sent: 0, review: 0, left: (await this.meta()).length, locked: true };
        if (this._flushingRecords) return { sent: 0, review: 0, left: (await this.meta()).length, busy: true }; // one send at a time
        this._flushingRecords = true;
        try { return await this._flushRecords(sendBatch, batchSize || 50); } finally { this._flushingRecords = false; }
    };
    Store.prototype._flushRecords = async function (sendBatch, batchSize) {
        var rows = await this.records();
        var sent = 0, reviewAdded = 0, self = this;
        for (var i = 0; i < rows.length; i += batchSize) {
            var batch = rows.slice(i, i + batchSize);
            for (var k = 0; k < batch.length; k++) {
                var it = batch[k];
                if (it.state === 'failed') await this.transition(it.id, 'retry');
                else if (it.state === 'sending') await this.b.put('outbox', it.id, Object.assign(await this.b.get('outbox', it.id), { state: 'queued' })); // a crash mid-send
                await this.transition(it.id, 'send');
            }
            var res;
            try { res = await sendBatch(batch.map(function (r) { return r.body; })); }
            catch (e) { for (var a = 0; a < batch.length; a++) await this.transition(batch[a].id, 'network'); break; }
            if (!(res && res.status >= 200 && res.status < 300 && res.data && Array.isArray(res.data.results))) {
                var hard = res && res.status === 422;
                for (var c = 0; c < batch.length; c++) {
                    await this.transition(batch[c].id, hard ? 'refused' : 'network');
                    // a batch the server calls invalid never gets better by itself: tell the manager (once per record)
                    if (hard) await this._addReview({ key: batch[c].body.key, type: batch[c].body.type, status: 'failed', reason: 'invalid_batch', at: Date.now() }, true);
                }
                break;
            }
            var byKey = {};
            res.data.results.forEach(function (x) { if (x && x.key) byKey[x.key] = x; });
            var stop = false;
            for (var d = 0; d < batch.length; d++) {
                var ans = byKey[batch[d].body.key];
                if (ans && FINAL[ans.status]) {
                    await this.transition(batch[d].id, 'ok');
                    sent++;
                    if (ans.status === 'conflict' || ans.status === 'rejected') {
                        await this._addReview({ key: batch[d].body.key, type: batch[d].body.type, status: ans.status, reason: ans.reason || null, at: Date.now() });
                        reviewAdded++;
                    }
                } else { await this.transition(batch[d].id, 'network'); stop = true; } // waiting / error / no answer: send again later
            }
            if (stop) break;
        }
        return { sent: sent, review: reviewAdded, left: (await self.meta()).length, locked: false };
    };

    Store.prototype._addReview = async function (entry, once) {
        var list = (await this.b.get('meta', 'review')) || [];
        if (once && list.some(function (e) { return e.key === entry.key; })) return;
        list.push(entry);
        await this.b.put('meta', 'review', list.slice(-100));
    };
    /** Records the server refused or flagged (a manager looks at them in the offline exceptions screen). */
    Store.prototype.review = async function () { return (await this.b.get('meta', 'review')) || []; };
    Store.prototype.clearReview = async function () { await this.b.del('meta', 'review'); };

    // ── the catalogue snapshot (encrypted; the pin verifiers in it are per till) ───────────────────────────────────────
    /** Keep the snapshot document sealed under the data key. */
    Store.prototype.putSnapshot = async function (doc) {
        if (!this.dek) throw new Error('till store is locked');
        await this.b.put('meta', 'snapshot', await TillCrypto.seal(this.dek, doc, 'snapshot'));
    };
    /** The kept snapshot, or null when none / the store is locked. */
    Store.prototype.getSnapshot = async function () {
        if (!this.dek) return null;
        var box = await this.b.get('meta', 'snapshot');
        return box ? TillCrypto.open(this.dek, box, 'snapshot') : null;
    };

    /** Oldest unsynced enqueue time (ms) or null — for the 7-day retention block (works while locked). */
    Store.prototype.oldest = async function () {
        var m = await this.meta();
        return m.length ? m.reduce(function (a, r) { return Math.min(a, r.ts); }, Infinity) : null;
    };

    /**
     * Move the old unencrypted queue in: each legacy item is written encrypted FIRST, then removed from the old store.
     * legacy = {all(): [{id, url, body, ts}], del(id), destroy()}. Returns how many moved; the old database is
     * destroyed only when it is empty.
     */
    Store.prototype.migrateLegacy = async function (legacy) {
        if (!this.dek) return 0;
        var items = await legacy.all(), moved = 0;
        for (var i = 0; i < items.length; i++) {
            var it = items[i];
            await this.add({ url: it.url, body: it.body }, it.ts || Date.now());
            await legacy.del(it.id);
            moved++;
        }
        if ((await legacy.all()).length === 0) await legacy.destroy();
        return moved;
    };

    /** Lease over (3 days without contact): drop the PIN wraps (and the snapshot, held by meta), keep the outbox + escrow. */
    Store.prototype.wipePins = async function () {
        var keys = await this.b.all('keys');
        for (var i = 0; i < keys.length; i++) if (String(keys[i].k).indexOf('pin:') === 0) await this.b.del('keys', keys[i].k);
        await this.b.del('meta', 'snapshot');
        this.dek = null;
    };

    /** A wipe the server asked for: everything — but only when nothing is left to upload. Returns false otherwise. */
    Store.prototype.wipeAll = async function () {
        if ((await this.meta()).length) return false;
        this.dek = null;
        await this.b.destroy();
        return true;
    };

    return { REPLAY_PATH: REPLAY_PATH, Store: Store, TillCrypto: TillCrypto, nextState: nextState, MemoryBackend: MemoryBackend, IdbBackend: IdbBackend, PIN_ROUNDS: PIN_ROUNDS };
}));
