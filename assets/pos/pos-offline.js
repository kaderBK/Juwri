// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* ==========================================================================
   POS — the offline register (offline POS spec OP-9).

   Two halves in one file:
     PosOffline  the pure part: the catalogue snapshot -> menu, pricing a cart with the shared TillCalculator, building
                 the replay records (sale, tender, void) with their UUIDv7 keys, the offline receipt, and the list of what
                 cannot be done offline. No DOM, no network: tested in node (tests/js/pos/offline-register.test.mjs).
     module      the Alpine part (window.PosModules.offline): keeps the snapshot in the till's encrypted store, polls it
                 while online, serves the menu / staff / prices from it when the till is offline, queues sales and tenders
                 in the encrypted outbox (assets/pos/pos-store.js), prints receipts from local data, and says plainly
                 what is not available offline.

   Money is integer minor units everywhere in here; the register's old float "major" numbers are display only.
   ========================================================================== */
(function (root, factory) {
    var inNode = typeof module === 'object' && module.exports;
    var api = factory(inNode ? require('./till-calculator.js') : root.TillCalculator);
    if (inNode) { module.exports = api; return; }
    root.PosOffline = api;
    root.PosModules = root.PosModules || {};
    root.PosModules.offline = api.registerModule(root);
}(typeof self !== 'undefined' ? self : this, function (TillCalculator) {
    'use strict';

    // ── what cannot be done offline (spec section 9): action -> the label the register already shows ───────────────────
    var ONLINE_ONLY = {
        customers: 'Add customer', coupon: 'Coupon', wallet: 'Wallet', tables: 'Tables', manage: 'Manage',
        delivery: 'Delivery', card: 'Card', other_payment: 'Other modes', split: 'Split', hold: 'Hold',
        kitchen: 'Send to kitchen', settle_order: 'Settle', combos: 'Combos', orders_online: 'Orders',
    };

    function fail(reason, extra) { return Object.assign({ ok: false, reason: reason }, extra || {}); }

    // ── money ───────────────────────────────────────────────────────────────────────────────────────────────────────────
    /**
     * "12.5" -> 1250 (exp 2): the typed decimal STRING is parsed exactly and rounded half-up on the first dropped digit,
     * with integer/string maths only. `Number(x).toFixed(exp)` rounded 1.005 down to 1.00 (the binary float is
     * 1.00499…): reading the digits as written is the only way to agree with the cashier's typed amount.
     */
    function toMinor(major, exp) {
        exp = Number.isInteger(exp) && exp >= 0 ? exp : 0;
        var s = typeof major === 'string' ? major.trim() : String(major);
        if (s === '') return 0;
        if (/[eE]/.test(s)) { s = expandExponent(Number(s)); if (s === null) return 0; }
        if (!/^[0-9]*\.?[0-9]*$/.test(s) || s === '.') return 0; // reject negatives, signs, letters and stray dots
        var dot = s.indexOf('.');
        var intPart = dot === -1 ? s : s.slice(0, dot);
        var fracPart = dot === -1 ? '' : s.slice(dot + 1);
        if (intPart === '') intPart = '0';
        var keep = fracPart.slice(0, exp) + new Array(Math.max(0, exp - fracPart.length) + 1).join('0');
        var value = BigInt(intPart + keep);
        if (fracPart.length > exp && fracPart.charCodeAt(exp) >= 53) value += 1n; // 53 = '5': half-up on the first dropped digit
        return Number(value);
    }
    /** "1e-7" -> "0.0000001": exponent-form numbers (only ever produced by Number -> String) become a plain decimal. */
    function expandExponent(n) {
        if (!isFinite(n) || n < 0) return null;
        var m = /^(\d)(?:\.(\d+))?e([+-]?)(\d+)$/.exec(String(n));
        if (!m) return String(n);
        var digits = m[1] + (m[2] || '');
        var point = 1 + (m[3] === '-' ? -1 : 1) * Number(m[4]);
        if (point <= 0) return '0.' + new Array(-point + 1).join('0') + digits;
        if (point >= digits.length) return digits + new Array(point - digits.length + 1).join('0');
        return digits.slice(0, point) + '.' + digits.slice(point);
    }
    function toMajor(minor, exp) { return Number(minor) / Math.pow(10, exp); }
    function fmt(minor, exp) { return (Number(minor) / Math.pow(10, exp)).toFixed(exp); }
    /**
     * A percentage of an integer minor amount, rounded half-up, with integer maths only. `p` may carry up to two
     * decimals (5, 12.5); it is scaled to hundredths so the multiply never drifts the way `total * p / 100` does.
     */
    function tipMinorFor(totalMinor, p) {
        var scaled = Math.round(Number(p) * 100);
        if (!(scaled > 0) || !(totalMinor > 0)) return 0;
        return Math.floor((totalMinor * scaled + 5000) / 10000); // + half the denominator = half-up
    }

    // ── snapshot integrity ──────────────────────────────────────────────────────────────────────────────────────────────
    /** Canonical JSON, the same as the server's: keys sorted at every level; PHP also escapes U+2028 / U+2029. */
    function canonical(v) {
        function sort(x) {
            if (Array.isArray(x)) return x.map(sort);
            if (x && typeof x === 'object') { var o = {}; Object.keys(x).sort().forEach(function (k) { o[k] = sort(x[k]); }); return o; }
            return x;
        }
        return JSON.stringify(sort(v)).replace(/\u2028/g, '\\u2028').replace(/\u2029/g, '\\u2029');
    }
    async function sha256hex(text) {
        var buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(text));
        return Array.from(new Uint8Array(buf), function (b) { return b.toString(16).padStart(2, '0'); }).join('');
    }
    /** The device checks the download against its hash before it ever replaces a working snapshot (spec section 2). */
    async function verifyHash(doc) {
        return !!(doc && doc.parts && doc.hash && (await sha256hex(canonical(doc.parts))) === doc.hash);
    }

    /** 'none' (no snapshot) | 'expired' (past valid_until: no new sales) | 'ok'. */
    function snapshotState(doc, now) {
        if (!doc || !doc.parts) return 'none';
        var until = Date.parse(doc.valid_until || '');
        if (!isFinite(until) || until <= now) return 'expired';
        return 'ok';
    }
    /** Valid again from the last contact with the server (a 304 or a fresh download), for the store's offline hours. */
    function extendValidity(doc, now) {
        return Object.assign({}, doc, { valid_until: new Date(now + (Number(doc.offline_hours) || 12) * 3600000).toISOString() });
    }

    // ── the menu from the snapshot, in the shape the register already renders (prices in major units for display) ──────
    function menuFromSnapshot(doc, lang) {
        var parts = doc.parts, exp = parts.store.exponent;
        var name = function (o, fallback) {
            var tr = o.translations && lang && o.translations[lang];
            if (typeof tr === 'string' && tr) return tr;
            if (tr && typeof tr === 'object' && typeof tr.name === 'string' && tr.name) return tr.name;
            return fallback;
        };
        var cats = (parts.catalogue.categories || []).slice().sort(function (a, b) { return a.position - b.position || a.id - b.id; });
        return cats.map(function (c) {
            return {
                id: c.id, name: c.name,
                items: (parts.catalogue.items || []).filter(function (m) { return m.category_id === c.id; }).map(function (m) {
                    return {
                        id: m.id, name: name(m, m.name), description: null, plu: m.plu, price: toMajor(m.price_minor, exp), original_price: null,
                        schedule_label: null, available_now: true, no_promotions: !!m.no_promotions, image: null,
                        is_sold_out: !!m.sold_out,
                        stock_qty: m.stock && typeof m.stock.qty === 'number' ? m.stock.qty : null,
                        variants: (m.variants || []).map(function (v) { return { id: v.id, price: toMajor(v.price_minor, exp), options: v.options }; }),
                        extras: (m.extras || []).map(function (e) { return { id: e.id, title: e.title, price: toMajor(e.price_minor, exp) }; }),
                        variant_groups: (m.choice_groups || []).map(function (g) {
                            return {
                                id: g.id, title: g.title, required: !!g.required, min_select: g.min_select, max_select: g.max_select,
                                options: (g.options || []).map(function (o) { return Object.assign({ label: o.label, price: toMajor(o.price_minor, exp) }, o.id != null ? { id: o.id } : {}); }),
                            };
                        }),
                    };
                }),
            };
        });
    }

    /** What is still on the till from sales waiting to be sent: item id -> pieces (the local stock view). */
    function pendingQty(records) {
        var out = {};
        records.forEach(function (r) {
            if (r.type !== 'sale') return;
            ((r.payload && r.payload.lines) || []).forEach(function (l) { out[l.item_id] = (out[l.item_id] || 0) + (l.qty || 0); });
        });
        return out;
    }

    // ── pricing: the cart -> priced lines, with the SHARED calculator (same integers as the server) ────────────────────
    function taxInput(taxes) {
        var t = taxes || {};
        if (t.mode === 'percentage') return { mode: 'percentage', rate: String(t.rate) };
        if (t.mode === 'fixed') return { mode: 'fixed', amount_minor: Number(t.amount_minor) };
        return { mode: 'none' };
    }
    function discountInput(d, exp) {
        var v = Number(d && d.value);
        if (!isFinite(v) || v <= 0) return { mode: 'none' };
        if (d.mode === 'percent') {
            var rate = Math.min(100, v).toFixed(4).replace(/\.?0+$/, '');
            return { mode: 'percent', rate: rate === '' ? '0' : rate };
        }
        return { mode: 'amount', amount_minor: toMinor(v, exp) };
    }

    /**
     * Price a cart from the snapshot. Lines are looked up by id in the snapshot, so a price the screen shows can never
     * differ from the price the sale carries. Returns {ok, lines, input, calc} or {ok:false, reason, name}.
     */
    function priceCart(doc, cart, opts) {
        opts = opts || {};
        if (!doc || !doc.parts) return fail('no_snapshot');
        var parts = doc.parts, exp = parts.store.exponent;
        var byId = {};
        (parts.catalogue.items || []).forEach(function (m) { byId[m.id] = m; });
        var lines = [], calcLines = [];
        for (var i = 0; i < cart.length; i++) {
            var l = cart[i];
            if (l.combo_id) return fail('combo', { name: l.name });
            var m = byId[l.id];
            if (!m) return fail('unknown_item', { name: l.name });
            if (m.sold_out) return fail('sold_out', { name: l.name });
            var unit = m.price_minor, variantId = null;
            if (l.variation) {
                var v = (m.variants || []).find(function (x) { return x.id === l.variation; });
                if (!v) return fail('unknown_variant', { name: l.name });
                unit = v.price_minor; variantId = v.id;
            }
            var extras = [];
            for (var e = 0; e < (l.extras || []).length; e++) {
                var se = (m.extras || []).find(function (x) { return x.id === l.extras[e].id; });
                if (!se) return fail('unknown_extra', { name: l.name });
                extras.push({ id: se.id, price_minor: se.price_minor });
            }
            var options = [];
            for (var g = 0; g < (l.options || []).length; g++) {
                var sg = (m.choice_groups || []).find(function (x) { return x.id === l.options[g].group_id; });
                if (!sg) return fail('unknown_option', { name: l.name });
                for (var s = 0; s < l.options[g].selected.length; s++) {
                    var pick = l.options[g].selected[s], opts = sg.options || [], so;
                    if (pick.option_id != null) {
                        so = opts.find(function (x) { return x.id === pick.option_id; });
                        if (!so) return fail('unknown_option', { name: l.name }); // an id not in the snapshot is never guessed
                    } else if (opts.every(function (x) { return x.id == null; })) {
                        so = opts.find(function (x) { return x.label === pick.option; }); // a label-only snapshot (old behaviour)
                        if (!so) return fail('unknown_option', { name: l.name });
                    } else {
                        return fail('unknown_option', { name: l.name }); // the snapshot carries ids: a label-only pick is ambiguous
                    }
                    options.push({ group_id: sg.id, group: sg.title, option: so.label, price_minor: so.price_minor });
                }
            }
            lines.push({ item_id: m.id, variant_id: variantId, qty: l.qty, unit_minor: unit, extras: extras, options: options,
                note: l.note ? String(l.note).slice(0, 255) : null });
            calcLines.push({ unit_minor: unit, qty: l.qty, extras_minor: extras.map(function (x) { return x.price_minor; }),
                options_minor: options.map(function (x) { return x.price_minor; }) });
        }
        var input = { lines: calcLines, tax: taxInput(parts.taxes), discount: discountInput(opts.discount, exp),
            service_minor: 0, tip_minor: opts.tipMinor || 0 };
        var calc;
        try { calc = TillCalculator.price(input); } catch (err) { return fail('invalid', { message: String(err && err.message) }); }
        return { ok: true, lines: lines, input: input, calc: calc };
    }

    /** Display numbers (major units) for the cart footer, from the integer result. */
    function displayTotals(priced, exp) {
        var c = priced.calc;
        return { subtotal: toMajor(c.subtotal_minor, exp), discount: toMajor(c.discount_minor, exp), tax: toMajor(c.tax_minor, exp),
            tip: toMajor(c.tip_minor, exp), total: toMajor(c.total_minor, exp) };
    }

    // ── keys and records (replay format, spec section 3 / the server's OfflineReplay) ──────────────────────────────────
    /** RFC 9562 UUIDv7: 48-bit millisecond time + random. Created once per record, reused by every retry. */
    function uuid7(nowMs, rnd) {
        var b = rnd || crypto.getRandomValues(new Uint8Array(16));
        var t = nowMs === undefined ? Date.now() : nowMs;
        for (var i = 5; i >= 0; i--) { b[i] = t % 256; t = Math.floor(t / 256); }
        b[6] = (b[6] & 0x0f) | 0x70;
        b[8] = (b[8] & 0x3f) | 0x80;
        var h = Array.from(b, function (x) { return x.toString(16).padStart(2, '0'); }).join('');
        return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
    }

    function totalsOf(calc) {
        return { subtotal_minor: calc.subtotal_minor, discount_minor: calc.discount_minor, tax_minor: calc.tax_minor,
            service_minor: calc.service_minor, tip_minor: calc.tip_minor, total_minor: calc.total_minor };
    }

    /**
     * The sale and (for a cash sale) its tender, as replay records without a seq (the outbox assigns it). The sale carries
     * the totals the till computed: the server re-prices with the same snapshot version and calculator and must agree.
     */
    function saleRecords(ctx) {
        var priced = ctx.priced, now = ctx.now === undefined ? Date.now() : ctx.now;
        var iso = new Date(now).toISOString();
        var saleKey = ctx.saleKey || uuid7(now);
        var sale = {
            key: saleKey, type: 'sale', staff_id: ctx.staffId, snapshot_version: ctx.doc.version, created_at: iso,
            payload: {
                order_type: ctx.orderType === 'on-table' ? 'on-table' : 'takeaway',
                lines: priced.lines, discount: priced.input.discount, service_minor: priced.input.service_minor,
                tip_minor: priced.input.tip_minor, totals: totalsOf(priced.calc),
                note: ctx.note ? String(ctx.note).slice(0, 255) : null,
            },
        };
        var out = [sale];
        if (ctx.tenderedMinor !== undefined && ctx.tenderedMinor !== null) {
            out.push({
                key: ctx.tenderKey || uuid7(now), type: 'tender', staff_id: ctx.staffId, snapshot_version: ctx.doc.version, created_at: iso,
                payload: { sale_key: saleKey, method: 'cash', amount_minor: priced.calc.total_minor, tendered_minor: ctx.tenderedMinor,
                    change_minor: ctx.tenderedMinor - priced.calc.total_minor },
            });
        }
        return out;
    }

    function voidRecord(ctx) {
        var now = ctx.now === undefined ? Date.now() : ctx.now;
        return { key: ctx.key || uuid7(now), type: 'void', staff_id: ctx.staffId, snapshot_version: ctx.doc.version, created_at: new Date(now).toISOString(),
            payload: { sale_key: ctx.saleKey, line: null, reason: String(ctx.reason).trim().slice(0, 255) } };
    }

    // ── staff from the snapshot ───────────────────────────────────────────────────────────────────────────────────────
    function staffOf(doc, id) {
        return doc && doc.parts && id != null ? (doc.parts.staff || []).find(function (s) { return s.id === Number(id); }) || null : null;
    }
    function can(doc, id, permission) {
        var s = staffOf(doc, id);
        return !!(s && (s.permissions || []).indexOf(permission) !== -1);
    }

    // ── receipts from local data ──────────────────────────────────────────────────────────────────────────────────────
    /** The receipt data for a queued sale (and its tender), built only from the records and the snapshot. */
    function receiptFor(doc, sale, tender, opts) {
        opts = opts || {};
        var parts = doc.parts, exp = parts.store.exponent;
        var byId = {};
        (parts.catalogue.items || []).forEach(function (m) { byId[m.id] = m; });
        var p = sale.payload;
        var calcInput = {
            lines: p.lines.map(function (l) { return { unit_minor: l.unit_minor, qty: l.qty, extras_minor: l.extras.map(function (x) { return x.price_minor; }),
                options_minor: l.options.map(function (x) { return x.price_minor; }) }; }),
            tax: taxInput(parts.taxes), discount: p.discount, service_minor: p.service_minor, tip_minor: p.tip_minor,
        };
        var calc = TillCalculator.price(calcInput); // only for each line's own total; the money lines below are what was recorded
        var totals = p.totals;
        var staff = staffOf(doc, sale.staff_id);
        return {
            store: parts.store.title, currency: parts.store.currency, exp: exp, no: String(sale.seq || ''), key: sale.key, time: sale.created_at,
            cashier: staff ? staff.name : '', snapshot_version: sale.snapshot_version, orderType: p.order_type, voided: !!opts.voided,
            lines: p.lines.map(function (l, i) {
                var m = byId[l.item_id] || {};
                var v = l.variant_id ? (m.variants || []).find(function (x) { return x.id === l.variant_id; }) : null;
                var extras = l.extras.map(function (x) { var se = (m.extras || []).find(function (y) { return y.id === x.id; }); return se ? se.title : ''; }).filter(Boolean);
                var opt = l.options.map(function (o) { return o.option; });
                var variant = v && v.options ? (Array.isArray(v.options) ? v.options : Object.keys(v.options).map(function (k) { return v.options[k]; })).join(' / ') : '';
                return { name: m.name || '', qty: l.qty, variant: variant, extras: extras.concat(opt), unit: fmt(l.unit_minor, exp), total: fmt(calc.lines[i].line_minor, exp) };
            }),
            subtotal: fmt(totals.subtotal_minor, exp), discount: totals.discount_minor > 0 ? fmt(totals.discount_minor, exp) : null,
            tax: totals.tax_minor > 0 ? fmt(totals.tax_minor, exp) : null, tip: totals.tip_minor > 0 ? fmt(totals.tip_minor, exp) : null,
            total: fmt(totals.total_minor, exp),
            tendered: tender ? fmt(tender.payload.tendered_minor, exp) : null,
            change: tender ? fmt(Math.max(0, tender.payload.tendered_minor - tender.payload.amount_minor), exp) : null,
        };
    }

    /** Group queued records into sales for the Offline tab: a sale, its tender, whether it was voided. */
    function salesFromRecords(records) {
        var sales = [];
        var by = {};
        records.filter(function (r) { return r.type === 'sale'; }).forEach(function (r) {
            var row = { id: r.id, key: r.key, seq: r.seq, time: r.created_at, sale: r, tender: null, voided: false };
            by[r.key] = row; sales.push(row);
        });
        records.forEach(function (r) {
            var row = r.payload && by[r.payload.sale_key];
            if (!row) return;
            if (r.type === 'tender') row.tender = r;
            if (r.type === 'void') row.voided = true;
        });
        return sales;
    }

    // ── the Alpine part ─────────────────────────────────────────────────────────────────────────────────────────────────
    function registerModule(root) {
        var B = function () { return root.PosBoot || {}; };
        var T = function () { return B().i18n || {}; };
        var memo = { key: null, val: null }; // plain object: a cache written while rendering must not be reactive state

        return {
            snap: null,              // the decoded snapshot document (in memory; kept sealed in the till store)
            pendingSnap: null,       // a newer download waiting for the cart to empty (swap only between tickets)
            menuSource: 'live',      // 'live' | 'snapshot'
            offlineReview: 0,        // sales the server refused or flagged on replay (a manager looks at them)
            offlineStaffId: null,    // the staff member whose PIN opened this till offline
            nowTick: 0,
            offlinePending: {},      // item id -> pieces in sales not sent yet (local stock view)
            offlineReceipt: null, offlineReceiptOpen: false,
            _snapTimer: null,

            offlineNow() { return !!this.offline || !navigator.onLine; },
            // nowTick moves every 30 s so the "time is up" state appears on its own, without any other change on the screen
            snapState() { return snapshotState(this.snap, Math.max(this.nowTick || 0, Date.now())); },
            offlineSaleAvailable() { return !!(this.tillStore && this.tillStore.isOpen() && this.snapState() === 'ok'); },
            offlineActorId() { return this.offlineStaffId || B().staffId || null; },

            /** The banner: {tone, text} or null. States: offline ready / no menu / time is up / syncing / needs a manager. */
            offlineBanner() {
                const t = T();
                if (this.offlineNow()) {
                    const waiting = this.queued ? ' (' + this.queued + ')' : '';
                    const st = this.snapState();
                    if (st === 'none') return { tone: 'bad', text: t.offlineNoMenu };
                    if (st === 'expired') return { tone: 'bad', text: t.offlineExpired + waiting };
                    return { tone: 'warn', text: t.offlineReady + waiting };
                }
                if (this.queued > 0) return { tone: 'warn', text: t.offlineSyncing + ' ' + this.queued + '…' };
                if (this.offlineReview > 0) return { tone: 'bad', text: String(t.offlineReview || '').replace(':count', this.offlineReview) };
                return null;
            },

            /** "<action> is not available offline" — one honest message for everything in spec section 9. */
            onlineOnlyNotice(kind) {
                const t = T();
                const labels = t.offlineLabels || {};
                const label = labels[kind] || ONLINE_ONLY[kind] || kind;
                this.showToast(String(t.offlineOnly || ':action is not available offline. Connect to use it.').replace(':action', label), 'warn');
                return false;
            },

            // ── snapshot: kept in the encrypted store, refreshed while online, swapped only between tickets ──────────────
            async initOffline() {
                await this.loadSnapshotFromStore();
                this.wrapOnlineOnly();
                this.wrapSettleMoney();
                if (this.$watch) this.$watch('cart', (c) => { if (!c.length) this.applyBetweenTickets(); });
                // A failed request flips this.offline without any browser 'offline' event (captive portal, server gone):
                // drop the online-only credits then too, so the cart's Payable never shows less than the charge.
                if (this.$watch) this.$watch('offline', (v) => { if (v) this.dropOnlineCredits(); });
                window.addEventListener('offline', () => { this.useSnapshotMenu(); });
                window.addEventListener('online', () => { this.afterOnline(); });
                if (this.offlineNow()) this.useSnapshotMenu();
                else this.refreshSnapshot();
                this._snapTimer = setInterval(() => { this.refreshSnapshot(); }, 60000);
                setInterval(() => { this.nowTick = Date.now(); }, 30000);
                this.refreshOffline();
            },
            async loadSnapshotFromStore() {
                if (!this.tillStore || !this.tillStore.isOpen()) return;
                try { this.snap = await this.tillStore.getSnapshot(); } catch (e) { this.snap = null; }
            },
            /** Called after a PIN opened the store offline: the snapshot (and the staff) become readable. */
            async offlineAfterUnlock(staffId) {
                if (staffId) this.offlineStaffId = staffId;
                await this.loadSnapshotFromStore();
                if (this.offlineNow()) this.useSnapshotMenu();
                this.refreshOffline();
            },
            async refreshSnapshot() {
                if (!navigator.onLine || !this.tillStore || !this.tillStore.isOpen()) return;
                const R = B().routes || {};
                if (!R.offlineSnapshot) return;
                const d = this._device ? this._device() : null;
                const url = new URL(this.posUrl(R.offlineSnapshot));
                if (this.branch) url.searchParams.set('branch', this.branch);
                const headers = d && this._deviceHeaders ? this._deviceHeaders(d) : { 'Accept': 'application/json' };
                if (this.snap) headers['If-None-Match'] = '"' + this.snap.version + '"';
                let r;
                try { r = await fetch(url.toString(), { headers }); } catch (e) { return; }
                try {
                    if (r.status === 304 && this.snap) {
                        this.snap = extendValidity(this.snap, Date.now());
                        await this.tillStore.putSnapshot(this.snap);
                        return;
                    }
                    if (!r.ok) return;
                    const doc = await r.json();
                    if (!(await verifyHash(doc))) { this.showToast(T().offlineSnapshotBad || 'The menu download failed its check.', 'warn'); return; }
                    if (this.snap && doc.version === this.snap.version) { this.snap = Object.assign({}, this.snap, { valid_until: doc.valid_until, issued_at: doc.issued_at, device: doc.device }); await this.tillStore.putSnapshot(this.snap); return; }
                    if (this.cart.length) { this.pendingSnap = doc; return; }
                    await this.swapSnapshot(doc);
                } catch (e) { /* the old snapshot stays */ }
            },
            async swapSnapshot(doc) {
                await this.tillStore.putSnapshot(doc);
                this.snap = doc; this.pendingSnap = null;
                if (this.offlineNow()) this.useSnapshotMenu();
            },
            async applyBetweenTickets() {
                if (this.pendingSnap) { try { await this.swapSnapshot(this.pendingSnap); } catch (e) { /* retried on the next poll */ } }
                if (this.menuSource === 'snapshot' && !this.offlineNow()) { this.menuSource = 'live'; this.load(); }
            },
            useSnapshotMenu() {
                if (!this.snap || !this.snap.parts) return;
                if (this.offlineNow()) this.dropOnlineCredits();
                this.categories = menuFromSnapshot(this.snap, (B().lang || '').slice(0, 2));
                this.menuSource = 'snapshot'; this.menuError = false; this.menuLoading = false; this.loaded = true;
                this.repriceCartFromSnapshot();
            },
            afterOnline() {
                this.refreshSnapshot();
                if (this.menuSource === 'snapshot' && !this.cart.length) { this.menuSource = 'live'; this.load(); }
            },
            /** Lines already in the cart show the snapshot's price (what the sale will carry). */
            repriceCartFromSnapshot() {
                if (!this.snap) return;
                const items = {};
                this.allItems().forEach((i) => { items[i.id] = i; });
                this.cart.forEach((l) => {
                    const it = items[l.id];
                    if (!it || l.combo_id) return;
                    l.unitPrice = this.unitPriceFor(it, l.variation, (l.extras || []).map((e) => e.id)) + this.optionsPrice(l.options || []);
                });
            },

            // ── prices: the shared calculator, memoised per cart ──────────────────────────────────────────────────────────
            /** Display totals from the snapshot + TillCalculator when the till is offline; null when online or not priceable. */
            offlineCalc() {
                if (!this.offlineNow() || !this.snap || !this.snap.parts) return null;
                const sig = JSON.stringify([this.cart.map((l) => [l.id, l.variation, (l.extras || []).map((e) => e.id), (l.options || []).map((g) => [g.group_id, g.selected.map((s) => (s.option_id != null ? s.option_id : s.option))]), l.qty, l.combo_id || null]), this.discount, this.tipAmount]);
                if (sig !== memo.key) {
                    const exp = this.snap.parts.store.exponent;
                    const priced = priceCart(this.snap, this.cart, { discount: this.discount, tipMinor: toMinor(this.tipAmount || 0, exp) });
                    memo.key = sig;
                    memo.val = priced.ok ? displayTotals(priced, exp) : null;
                }
                return memo.val;
            },

            // ── the local stock view ──────────────────────────────────────────────────────────────────────────────────────
            stockLeft(it) {
                if (this.menuSource !== 'snapshot' || it.stock_qty === null || it.stock_qty === undefined) return null;
                return it.stock_qty - (this.offlinePending[it.id] || 0);
            },

            // ── selling ───────────────────────────────────────────────────────────────────────────────────────────────────
            /** Pay-and-close with cash while offline: queue the sale and its tender together, then show the receipt. */
            async offlineSubmitPaid(method) {
                const t = T();
                if (method !== 'cash') return this.onlineOnlyNotice('card');
                this.dropOnlineCredits();
                if (this.salesBlocked) return alert(t.offlineBlocked);
                if (this.snapState() === 'none') return alert(t.offlineNoMenu);
                if (this.snapState() === 'expired') return alert(t.offlineExpired);
                if (!this.tillStore || !this.tillStore.isOpen()) { this.locked = true; return; }
                const staffId = this.offlineActorId();
                if (!staffId) return alert(t.offlineNoStaff);
                if (!can(this.snap, staffId, 'pos')) return alert(t.offlineNotAllowed);
                const exp = this.snap.parts.store.exponent;
                const tipMinor = toMinor(this.tipAmount || 0, exp);
                const priced = priceCart(this.snap, this.cart, { discount: this.discount, tipMinor });
                if (!priced.ok) return priced.reason === 'combo' ? this.onlineOnlyNotice('combos') : alert(t.offlineMenuChanged);
                const tenderedMinor = toMinor(this.tendered || 0, exp);
                if (tenderedMinor < priced.calc.total_minor) return alert(t.offlineCashShort);
                const recs = saleRecords({ doc: this.snap, priced, staffId, orderType: this.type, note: this.orderNote, tenderedMinor });
                this.sending = true;
                try {
                    try { await this.tillStore.addRecords(recs); }
                    catch (err) {
                        if (!this.tillStore.isOpen()) { this.locked = true; return; }
                        return alert(t.storageFull); // nothing was written: the cart stays
                    }
                    const saved = (await this.tillStore.records()).filter((r) => r.body.key === recs[0].key || (r.body.payload && r.body.payload.sale_key === recs[0].key)).map((r) => r.body);
                    const sale = saved.find((r) => r.type === 'sale'), tender = saved.find((r) => r.type === 'tender');
                    this.offlineReceipt = receiptFor(this.snap, sale, tender);
                    this.openOfflineReceipt();
                    this.cashOpen = false;
                    this.reset();
                    this.showToast(t.offlineSaved, 'info');
                    this.refreshOffline();
                } finally { this.sending = false; }
            },
            /** Send-to-kitchen and Hold need the server (other screens, held orders): say so, keep the cart. */
            offlineSubmit(held) { return this.onlineOnlyNotice(held ? 'hold' : 'kitchen'); },

            // ── offline sales list: reprint and void (a sale made on this till, before it is sent) ──────────────────────
            async refreshOffline() {
                if (!this.tillStore) return;
                try {
                    this.offlineReview = (await this.tillStore.review()).length;
                    if (this.tillStore.isOpen()) this.offlinePending = pendingQty((await this.tillStore.records()).map((r) => r.body));
                } catch (e) { /* locked or unreadable: counts stay */ }
            },
            async offlineSales() {
                if (!this.tillStore || !this.tillStore.isOpen()) return [];
                const recs = (await this.tillStore.records()).map((r) => Object.assign({ id: r.id }, r.body));
                const exp = this.snap ? this.snap.parts.store.exponent : 2;
                return salesFromRecords(recs).map((s) => ({ kind: 'sale', id: s.id, key: s.key, time: s.time, voided: s.voided, paid: !!s.tender,
                    total: fmt(s.sale.payload.totals.total_minor, exp), item_count: s.sale.payload.lines.reduce((n, l) => n + l.qty, 0),
                    body: { type: s.sale.payload.order_type }, _sale: s.sale, _tender: s.tender }));
            },
            async reprintOfflineSale(row) {
                if (!this.snap) return;
                this.offlineReceipt = receiptFor(this.snap, row._sale, row._tender, { voided: row.voided });
                this.openOfflineReceipt();
            },
            openOfflineReceipt() {
                this.offlineReceiptOpen = true;
                if (this.$nextTick) this.$nextTick(() => { const b = document.querySelector('.pos-offline-receipt .pos-mini-apply'); if (b) b.focus(); });
            },
            async voidOfflineSale(row) {
                const t = T();
                if (this.sending) return;
                if (row.voided) return alert(t.offlineAlreadyVoided);
                const staffId = this.offlineActorId();
                if (!staffId || !can(this.snap, staffId, 'pos_refund')) return alert(t.offlineNotAllowed);
                const reason = window.prompt(t.offlineVoidReason || 'Reason for voiding this sale');
                if (!reason || !reason.trim()) return;
                this.sending = true; // a double tap can never queue two voids
                try {
                    // the list on screen may be old: look at the outbox itself
                    const now = (await this.tillStore.records()).map((r) => r.body);
                    if (now.some((r) => r.type === 'void' && r.payload.sale_key === row.key)) return alert(t.offlineAlreadyVoided);
                    try { await this.tillStore.addRecords([voidRecord({ doc: this.snap, staffId, saleKey: row.key, reason })]); }
                    catch (e) { return alert(t.storageFull); }
                    this.showToast(t.offlineVoided, 'info');
                    if (this.loadOffline) await this.loadOffline();
                    this.refreshQueued(); this.refreshOffline();
                } finally { this.sending = false; }
            },
            printOfflineReceipt() {
                const done = () => { document.body.classList.remove('is-printing-receipt'); window.removeEventListener('afterprint', done); };
                document.body.classList.add('is-printing-receipt');
                window.addEventListener('afterprint', done);
                try { window.print(); } finally { setTimeout(done, 1500); } // some embedded browsers never fire afterprint
            },
            closeOfflineReceipt() { this.offlineReceiptOpen = false; },

            // ── navigation and online-only guards ─────────────────────────────────────────────────────────────────────────
            goPanel(p) {
                if (this.offlineNow()) {
                    if (p === 'tables') return this.onlineOnlyNotice('tables');
                    if (p === 'manage') return this.onlineOnlyNotice('manage');
                    if (p === 'orders') { this.activePanel = 'orders'; this.ordersTab = 'offline'; if (this.loadOffline) this.loadOffline(); return; }
                }
                this.activePanel = p;
            },
            setOrderType(ty) {
                if (this.offlineNow() && ty === 'delivery') return this.onlineOnlyNotice('delivery');
                this.type = ty;
            },
            /** Wrap the entry points of everything section 9 lists: offline they say so instead of failing quietly. */
            wrapOnlineOnly() {
                const self = this;
                const wrap = (name, kind, when) => {
                    const orig = self[name];
                    if (typeof orig !== 'function') return;
                    self[name] = function (...args) {
                        if (self.offlineNow() && (!when || when(...args))) return self.onlineOnlyNotice(kind);
                        return orig.apply(self, args);
                    };
                };
                wrap('openCustomers', 'customers');
                wrap('checkCoupon', 'coupon');
                wrap('toggleWallet', 'wallet');
                wrap('openExtSettle', 'settle_order');
                wrap('startSplit', 'split');
                wrap('openHold', 'hold');
                wrap('openAdd', 'coupon', (kind) => kind === 'coupon');
                wrap('openSettle', 'card', (mode) => mode === 'card');
                wrap('openSettle', 'other_payment', (mode) => mode === 'other');
                wrap('setSettleTab', 'other_payment', (tab) => tab !== 'cash');
                wrap('confirmSettle', 'card', (method) => method !== 'cash');
                wrap('openCombo', 'combos');
                wrap('setOrdersTab', 'orders_online', (tab) => tab !== 'offline');
            },

            /**
             * OP-9: offline the charged total comes from the snapshot, not from the float path (which still knows the
             * coupon / wallet). These are pos-payments (module) methods wrapped on the composed register after init —
             * exactly like wrapOnlineOnly — so the module's own settleTotal / setTipPct / setTipAmount / presetTender /
             * openSettle are replaced once. Online every call falls through to the original, byte-identical.
             */
            wrapSettleMoney() {
                const self = this;
                if (self._settleMoneyWrapped) return;
                self._settleMoneyWrapped = true;
                const offline = () => !!(self.offlineNow && self.offlineNow());
                const exp = () => (self.snap && self.snap.parts ? self.snap.parts.store.exponent : 2);

                const origSettle = self.settleTotal;
                if (typeof origSettle === 'function') {
                    self.settleTotal = function (...args) {
                        if (offline()) { const o = self.offlineCalc && self.offlineCalc(); if (o) return o.total; }
                        return origSettle.apply(self, args);
                    };
                }
                const origTipPct = self.setTipPct;
                if (typeof origTipPct === 'function') {
                    self.setTipPct = function (p) {
                        if (offline() && self.snap && self.snap.parts) {
                            self.tipPct = p;
                            if (!p) { self.tipAmount = 0; return; }
                            const priced = priceCart(self.snap, self.cart, { discount: self.discount, tipMinor: 0 });
                            const totalMinor = priced && priced.ok ? priced.calc.total_minor : 0;
                            self.tipAmount = toMajor(tipMinorFor(totalMinor, p), exp());
                            return;
                        }
                        return origTipPct.apply(self, arguments);
                    };
                }
                const origTipAmount = self.setTipAmount;
                if (typeof origTipAmount === 'function') {
                    self.setTipAmount = function (v) {
                        if (offline() && self.snap && self.snap.parts) {
                            self.tipPct = null;
                            self.tipAmount = toMajor(toMinor(Math.max(0, Number(v) || 0), exp()), exp());
                            return;
                        }
                        return origTipAmount.apply(self, arguments);
                    };
                }
                const origPreset = self.presetTender;
                if (typeof origPreset === 'function') {
                    self.presetTender = function (v) {
                        if (offline() && v === 'exact' && self.snap && self.snap.parts) {
                            self.tenderStr = fmt(toMinor(self.settleTotal(), exp()), exp());
                            if (typeof self._syncTender === 'function') self._syncTender();
                            return;
                        }
                        return origPreset.apply(self, arguments);
                    };
                }
                const origOpen = self.openSettle;
                if (typeof origOpen === 'function') {
                    self.openSettle = function (mode) {
                        // Only a cash checkout reaches the offline snapshot: offline card / other are refused by
                        // wrapOnlineOnly before a credit is dropped, so their "not available offline" is the only message.
                        if (offline() && (mode === undefined || mode === 'cash')) self.dropOnlineCredits();
                        return origOpen.apply(self, arguments);
                    };
                }
            },

            /**
             * A coupon or store credit redeemed while online cannot survive a drop to offline: the offline price does
             * not carry them, so the sale would charge more than the screen offered. Drop them with a message the
             * cashier can see. Never touches anything online.
             */
            dropOnlineCredits() {
                if (this.offlineNow && !this.offlineNow()) return false;
                let dropped = false;
                if (this.coupon && this.coupon.applied) {
                    this.coupon.applied = null;
                    this.coupon.code = '';
                    this.coupon.error = '';
                    dropped = true;
                }
                if (this.useWallet || (typeof this.walletApplied === 'function' && this.walletApplied() > 0)) {
                    this.useWallet = false;
                    this.walletAmt = null;
                    dropped = true;
                }
                if (dropped) {
                    const t = T();
                    this.showToast(t.offlineCreditsDropped || 'Coupon / store credit removed: they need a connection.', 'warn');
                }
                return dropped;
            },
        };
    }

    return {
        ONLINE_ONLY: ONLINE_ONLY, toMinor: toMinor, toMajor: toMajor, fmt: fmt, canonical: canonical, verifyHash: verifyHash,
        snapshotState: snapshotState, extendValidity: extendValidity, menuFromSnapshot: menuFromSnapshot, pendingQty: pendingQty,
        priceCart: priceCart, displayTotals: displayTotals, uuid7: uuid7, saleRecords: saleRecords, voidRecord: voidRecord,
        staffOf: staffOf, can: can, receiptFor: receiptFor, salesFromRecords: salesFromRecords, registerModule: registerModule,
    };
}));
