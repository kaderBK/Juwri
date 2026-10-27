// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* ==========================================================================
   POS-V2 — register core script (extracted from register.blade.php, P0-1).
   Behaviour-identical to the former inline <script>. All Blade-derived values
   (routes, ids, flags, translated strings) are injected by the inline
   `window.PosBoot = {...}` bootstrap the shell renders before this file loads.

   Module registry (UI-KIT §5): feature tracks attach themselves to
   `window.PosModules.<name>` and the factory below composes them over the core
   state, so parallel tracks never edit this file. P0-1 ships zero feature
   modules — the composition is a no-op passthrough of `coreState`.
   ========================================================================== */
if (typeof window !== 'undefined') window.PosModules = window.PosModules || {};

/* P3 accessibility: while the register is locked, Tab/Shift+Tab must never land on
   anything outside the .lock-overlay. `lockTrap(doc, overlay, opts)` is the pure,
   DOM-only core (unit-tested with plain objects in core/tests/js/pos/lock-trap.test.mjs):
   lock() marks every sibling of the overlay and of each ancestor up to <body> inert
   (only the ones it actually set, and never one already inert), pulls focus into the
   overlay — preferring an `input.pin` — wraps Tab/Shift+Tab inside it, and keeps pulling
   focus back on every focusin while locked. Because x-show may not have displayed the
   overlay yet, the first focus attempt is followed by a bounded, cancellable retry chain;
   the timer is injectable (`opts.defer`, default setTimeout) so tests drive it
   deterministically. unlock() clears the retries, removes exactly those inert marks and
   listeners, restores the previously focused element, and nothing else. */
function lockTrap(doc, overlay, opts) {
    const options = opts || {};
    const defer = typeof options.defer === 'function' ? options.defer : ((fn, ms) => setTimeout(fn, ms));
    const cancel = typeof options.cancel === 'function' ? options.cancel : ((h) => clearTimeout(h));
    const RETRY_DELAYS = [0, 50, 100, 200, 200, 200, 200, 200]; // bounded: max 8 tries

    const FOCUSABLE = ['INPUT', 'SELECT', 'TEXTAREA', 'BUTTON', 'A'];
    const hasInert = (el) => !!(el && el.hasAttribute && el.hasAttribute('inert'));
    const classList = (el) => (el.getAttribute ? (el.getAttribute('class') || '') : (el.className || '')).split(/\s+/);
    const hasClass = (el, name) => !!el && classList(el).indexOf(name) !== -1;
    // Focusable only when the rendering APIs (where the browser exposes them) say it is
    // laid out. The plain-object test DOM has neither API; an absent API means visible.
    const isVisible = (el) => {
        if (el.offsetParent !== undefined) {
            if (el.offsetParent !== null) return true;
            return typeof el.getClientRects === 'function' ? el.getClientRects().length > 0 : false;
        }
        if (typeof el.getClientRects === 'function') return el.getClientRects().length > 0;
        return true;
    };
    const isFocusable = (el) => {
        if (!el || !el.tagName || el.disabled || el.hidden || hasInert(el)) return false;
        const native = FOCUSABLE.indexOf(el.tagName) !== -1 && (el.tagName !== 'A' || (el.hasAttribute && el.hasAttribute('href'))); // an <a> without href is not tabbable
        const tabbed = !!(el.hasAttribute && el.hasAttribute('tabindex') && el.getAttribute('tabindex') !== '-1');
        if (!native && !tabbed) return false;
        return isVisible(el);
    };
    const focusables = () => {
        const out = [];
        const walk = (el) => {
            for (const child of (el.children || [])) {
                if (isFocusable(child)) out.push(child);
                walk(child);
            }
        };
        walk(overlay);
        return out;
    };
    const focusTarget = () => {
        const list = focusables();
        return list.find((el) => hasClass(el, 'pin')) || list[0] || null;
    };
    const inside = (el) => !!el && (el === overlay || (overlay.contains ? overlay.contains(el) : false));

    let inerted = [];
    let handler = null;
    let keyHandler = null;
    let previous = null;
    let engaged = false;
    let retryTimer = null;

    const clearRetry = () => {
        if (retryTimer !== null) {
            try { cancel(retryTimer); } catch (e) { /* ignore */ }
            retryTimer = null;
        }
    };
    const attemptFocus = () => {
        const target = focusTarget() || overlay;
        if (target && target.focus) target.focus();
    };
    const scheduleRetry = (i) => {
        if (!engaged || i >= RETRY_DELAYS.length) return;
        retryTimer = defer(() => {
            retryTimer = null;
            if (!engaged) return; // unlock() cancelled the chain
            if (!inside(doc.activeElement)) attemptFocus();
            if (!inside(doc.activeElement)) scheduleRetry(i + 1);
        }, RETRY_DELAYS[i]);
    };
    const pullFocus = () => {
        if (!engaged || inside(doc.activeElement)) { clearRetry(); return; }
        attemptFocus();
        clearRetry();
        if (!inside(doc.activeElement)) scheduleRetry(0); // overlay not shown yet
    };

    // Keep Tab/Shift+Tab cycling inside the overlay; wrap at either end.
    const onKeydown = (e) => {
        if (!engaged || !e || e.key !== 'Tab') return;
        const list = focusables();
        if (list.length === 0) {
            if (e.preventDefault) e.preventDefault();
            if (overlay.focus) overlay.focus();
            return;
        }
        const first = list[0];
        const last = list[list.length - 1];
        const active = doc.activeElement;
        if (!inside(active) || list.indexOf(active) === -1) { // outside, or on a non-tabbable node inside (tabindex -1): re-enter at an end
            if (e.preventDefault) e.preventDefault();
            (e.shiftKey ? last : first).focus();
        } else if (!e.shiftKey && active === last) {
            if (e.preventDefault) e.preventDefault();
            first.focus();
        } else if (e.shiftKey && active === first) {
            if (e.preventDefault) e.preventDefault();
            last.focus();
        }
    };

    return {
        lock() {
            if (engaged) return; // already engaged
            engaged = true;
            previous = doc.activeElement || null;
            let node = overlay;
            while (node && node.parentElement) {
                const parent = node.parentElement;
                for (const sibling of (parent.children || [])) {
                    if (sibling === node || !sibling.setAttribute || hasInert(sibling)) continue;
                    sibling.setAttribute('inert', '');
                    inerted.push(sibling);
                }
                if (parent === doc.body) break;
                node = parent;
            }
            handler = () => pullFocus();
            doc.addEventListener('focusin', handler, true);
            keyHandler = (e) => onKeydown(e);
            doc.addEventListener('keydown', keyHandler, true);
            pullFocus();
        },
        unlock() {
            for (const el of inerted) { if (el.removeAttribute) el.removeAttribute('inert'); }
            inerted = [];
            engaged = false;
            clearRetry();
            if (handler) { doc.removeEventListener('focusin', handler, true); handler = null; }
            if (keyHandler) { doc.removeEventListener('keydown', keyHandler, true); keyHandler = null; }
            const back = previous;
            previous = null;
            if (back && doc.contains && doc.contains(back) && back.focus) back.focus();
        },
    };
}

function posRegister() {
    const B = window.PosBoot || {};
    const R = B.routes || {};
    const T = B.i18n || {};

    const coreState = {
        categories: [], activeCat: 'all', cart: [], type: 'takeaway', search: '',
        mCart: false, // mobile: cart drawer open (ignored on desktop where cart is docked)
        activePanel: 'home', // P0-4: rail/Select-Table switch — 'home' | 'tables' | 'orders'
        // T1-3: per-order discount + coupon add-row state.
        addSheet: null, // null | 'discount' | 'coupon' | 'hold'
        holdNote: '',   // T4-2: optional note attached to a held order
        orderNote: '',  // order-level note on send (orders.message)
        discount: { mode: 'amount', value: 0 }, // mode: 'amount' | 'percent'
        coupon: { code: '', applied: null, checking: false, error: '' },
        sheet: { open: false, item: null, variation: null, extras: [], note: '', qty: 1 },
        storeId: B.storeId,
        branch: B.branch, loaded: false,
        cashOpen: false, tendered: 0, offline: !navigator.onLine, queued: 0, offlineReady: false,
        csrf: document.querySelector('meta[name=csrf-token]').content,
        // P3: quick-switch lock
        locked: false, isOwner: !!B.isOwner,
        autolockMinutes: B.autolockMinutes,
        pinInput: '', pwInput: '', lockErr: '', idleTimer: null,
        money(v) { return Number(v || 0).toFixed(2); },
        // Every POS call must carry the active restaurant so the owner's chosen
        // restaurant (not their default) is used — the page URL isn't sent on fetch.
        posUrl(u) { const url = new URL(u, location.origin); url.searchParams.set('restaurant', this.storeId); return url.toString(); },
        displayChannel: null, // P2: customer-facing display
        async boot() {
            await this.load();
            if ('serviceWorker' in navigator) {
                // Keep THIS store's register and menu for offline use: both loaded before the worker controlled the page
                // (cold start). The worker answers once they are kept (offlineReady), so the first online visit is enough.
                // Talk to THIS registration's worker: serviceWorker.ready can resolve to the owner dashboard's root-scope
                // worker, which never answers.
                navigator.serviceWorker.register(R.sw, { scope: '/pos' })
                    .then((reg) => new Promise((done) => {
                        const w = reg.active || reg.waiting || reg.installing;
                        if (!w) return done(null);
                        if (w.state === 'activated') return done(w);
                        w.addEventListener('statechange', () => { if (w.state === 'activated') done(w); else if (w.state === 'redundant') done(reg.active); });
                    }))
                    .then((w) => new Promise((done) => {
                        if (!w) return done(false);
                        const ch = new MessageChannel();
                        ch.port1.onmessage = (e) => done(!!(e.data && e.data.ok));
                        w.postMessage({ type: 'pos-shell', url: location.href, warm: [this.posUrl(R.menu), ...[...document.querySelectorAll('script[src], link[rel=stylesheet][href]')].map((el) => el.src || el.href)] }, [ch.port2]);
                        setTimeout(() => done(false), 30000);
                    }))
                    .then((ok) => { this.offlineReady = ok; }).catch(() => {});
            }
            // Back online: a till restarted offline is still locked unless a PIN opened it; the escrow opens it now so
            // its outbox can upload (before, it stayed locked and never drained).
            window.addEventListener('online', async () => {
                this.offline = false;
                if (this.tillStore && !this.tillStore.isOpen()) { try { await this.initTillStore(); } catch (e) {} }
                this.flush();
            });
            window.addEventListener('offline', () => { this.offline = true; });
            await this.initTillStore(); // the encrypted offline store (opens online with the till's escrow)
            // Restarted offline: only a staff PIN that signed in on this till opens the store, so start on the lock screen.
            if (this.tillStore && !this.tillStore.isOpen()) this.locked = true;
            if (this.initOffline) await this.initOffline(); // OP-9: snapshot, offline menu + prices, online-only guards
            this.refreshQueued();
            this.flush(); // replay anything left from a previous session
            this.initDisplay();
            this.initLock();
            this.initSearchShortcuts();
            this.initScanner();
            if (this.initShortcuts) this.initShortcuts(); // T1-7: composed from PosModules.shortcuts
            if (this.initTables) this.initTables();       // T3-2: load tables when the panel opens
            if (this.initHold) this.initHold();           // T4-1: load orders hub when the panel opens
            if (this.initManage) this.initManage();       // Manage: shift + reports when the panel opens
            if (this.initPrint) this.initPrint();         // T1: direct printing (device handles + job queue)
            if (this.initCombos) this.initCombos();       // T6: load combos for the combo tiles
            if (this.initTerminal) this.initTerminal();   // D8: register + heartbeat this device
            if (this.initDevice) this.initDevice();       // offline till identity: revoke → upload + wipe; lease
            if (window.NotifySounds) NotifySounds.init(B.notifSounds); // owner-configured chimes
            if (this.initIncomingWatch) this.initIncomingWatch();      // ring on new online orders
        },
        // T1-5: '/' or F2 focuses the topbar search (unless already typing in a
        // field); the search input itself clears on Esc.
        initSearchShortcuts() {
            window.addEventListener('keydown', (e) => {
                if (this.locked) return;
                const t = e.target;
                const typing = t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable);
                if ((e.key === '/' && !typing) || e.key === 'F2') {
                    e.preventDefault();
                    this.focusSearch();
                }
            });
        },
        // ── P3: quick-switch lock ──
        initLock() {
            ['click', 'keydown', 'touchstart', 'mousemove'].forEach(ev =>
                window.addEventListener(ev, () => { if (!this.locked) this.armIdle(); }, { passive: true }));
            this.armIdle();
            // P3 a11y: Tab/Shift+Tab must stay inside the lock overlay while locked.
            const overlay = document.querySelector('.lock-overlay');
            if (overlay) {
                const trap = lockTrap(document, overlay);
                if (this.locked) trap.lock();
                this.$watch('locked', (v) => { if (v) this.$nextTick(() => { if (this.locked) trap.lock(); }); else trap.unlock(); }); // after x-show shows the overlay
            }
        },
        armIdle() {
            if (!this.autolockMinutes) return; // 0 = no auto-lock
            clearTimeout(this.idleTimer);
            this.idleTimer = setTimeout(() => this.lock(), this.autolockMinutes * 60000);
        },
        lock() { this.offlineStaffId = null; this.locked = true; this.lockErr = ''; this.pinInput = ''; this.pwInput = ''; clearTimeout(this.idleTimer); },
        async unlock() {
            const usePin = !(this.isOwner && !this.pinInput);
            const body = usePin ? { pin: this.pinInput } : { password: this.pwInput };
            let r;
            try { r = await fetch(this.posUrl(R.unlock), { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' }, body: JSON.stringify(body) }); }
            catch (e) {
                // Offline: a PIN that opens this till's encrypted store (its own wrap) is the right PIN (AES-GCM tag check).
                const pinStaff = usePin && this.tillStore ? await this.tillStore.openWithPin(this.pinInput).catch(() => null) : null;
                if (pinStaff !== null) {
                    this.locked = false; this.pinInput = ''; this.armIdle(); this.migrateLegacyQueue(); this.refreshQueued();
                    if (this.offlineAfterUnlock) this.offlineAfterUnlock(pinStaff); // OP-9: the snapshot + staff become readable
                    return;
                }
                this.lockErr = T.networkError; return;
            }
            if (r.ok) {
                // Remember this staff member's PIN on this till so it can open the offline store without the server.
                const data = await r.clone().json().catch(() => ({}));
                if (usePin && this.tillStore && this.tillStore.isOpen() && data.actor && data.actor.id) {
                    await this.tillStore.rememberPin(data.actor.id, this.pinInput).catch(() => {});
                }
                location.reload(); return; // context() re-resolves the (possibly new) actor; held orders persist
            }
            if (r.status === 429) { this.lockErr = T.tooManyAttempts; }
            else { this.lockErr = usePin ? T.wrongPin : T.wrongPassword; }
            this.pinInput = ''; this.pwInput = '';
        },
        // ── P2: mirror the cart to the customer display over BroadcastChannel ──
        initDisplay() {
            try { this.displayChannel = new BroadcastChannel('pos-cart-' + this.storeId); } catch (e) { this.displayChannel = null; }
            if (this.displayChannel) {
                // A display opening mid-order asks us to resend its current cart.
                this.displayChannel.onmessage = (e) => { if (e.data && e.data.hello) this.broadcastCart(); };
            }
            // Deep-watch the cart + order type + discount/coupon/customer/wallet so the
            // display mirrors every change (T7-3), not just item add/remove.
            this.$watch('cart', () => this.broadcastCart());
            this.$watch('type', () => this.broadcastCart());
            this.$watch('discount', () => this.broadcastCart());
            this.$watch('coupon', () => this.broadcastCart());
            this.$watch('customer', () => this.broadcastCart());
            if (this.useWallet !== undefined) this.$watch('useWallet', () => this.broadcastCart());
            this.broadcastCart();
        },
        broadcastCart() {
            if (!this.displayChannel) return;
            const applied = this.coupon && this.coupon.applied;
            this.displayChannel.postMessage({
                active: this.cart.length > 0,
                type: this.type,
                // T7-3: customer first name for a personal touch on the display.
                customer: this.customer ? ((this.customer.name || '').trim().split(' ')[0] || null) : null,
                lines: this.cart.map(l => ({
                    name: l.name, qty: l.qty, price: l.unitPrice, line_total: l.unitPrice * l.qty,
                    // T6-4: choice-group selections + T7-3 extras mirrored to the display.
                    options: (l.options || []).map(g => g.title + ': ' + g.selected.map(s => s.option).join(', ')),
                    extras: (l.extras || []).map(e => e.title),
                })),
                // T7-3: full breakdown so the diner sees discount/coupon/wallet/tax.
                subtotal: this.subtotal(),
                discount: this.discountAmount ? this.discountAmount() : 0,
                coupon: applied ? { code: this.coupon.applied.code, value: this.couponValue() } : null,
                wallet: this.walletApplied ? this.walletApplied() : 0,
                tax: this.taxPreview ? this.taxPreview() : 0,
                total: this.payable(),
                at: Date.now(),
            });
        },
        openDisplay() {
            // Carry the register's language so the customer display matches it even
            // on a second screen without the language cookie.
            const u = new URL(this.posUrl(R.display));
            const lang = document.documentElement.lang || '';
            if (lang) u.searchParams.set('lang', lang);
            window.open(u.toString(), 'pos-display-' + this.storeId);
        },
        uuid() { return 'pos-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10); },
        // J27-A2: RFC 9562 UUIDv7 (48-bit ms time + random) — the order key the server dedupes on.
        uuid7() {
            const b = new Uint8Array(16);
            crypto.getRandomValues(b);
            let t = Date.now();
            for (let i = 5; i >= 0; i--) { b[i] = t % 256; t = Math.floor(t / 256); }
            b[6] = (b[6] & 0x0f) | 0x70;
            b[8] = (b[8] & 0x3f) | 0x80;
            const h = Array.from(b, x => x.toString(16).padStart(2, '0')).join('');
            return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
        },
        // J27-A2: ONE key per checkout attempt. A retry of the same cart (lost answer, double tap, offline replay)
        // resends the same key, so the server hands back the order it already made; a changed cart, a finished
        // sale (reset) or a 409 starts a new key.
        orderKey: null, orderKeySig: null,
        clientOrderId(body) {
            const sig = JSON.stringify(body);
            if (!this.orderKey || this.orderKeySig !== sig) { this.orderKey = this.uuid7(); this.orderKeySig = sig; }
            return this.orderKey;
        },
        // T7-5: loading + error states for the menu fetch (retry from the grid).
        menuLoading: false, menuError: false,
        async load() {
            this.menuLoading = true; this.menuError = false;
            try {
                const r = await fetch(this.posUrl(R.menu), { headers: { 'Accept': 'application/json' } });
                if (!r.ok) throw new Error('http ' + r.status);
                const d = await r.json();
                this.categories = d.categories || [];
            } catch (e) {
                this.menuError = true;
            }
            this.menuLoading = false;
            this.loaded = true;
        },
        allItems() { return this.categories.flatMap(c => (c.items || []).map(i => ({ ...i, cat: c.id }))); },
        // T1-5: diacritics-insensitive normalisation. Strips Latin combining marks
        // (café→cafe) and folds common Arabic variants (أإآ→ا, ة→ه, ى→ي, tatweel,
        // harakat) so search matches regardless of how the diner typed it.
        norm(s) {
            return (s || '').toString().toLowerCase()
                .normalize('NFD').replace(/[̀-ͯ]/g, '')      // Latin diacritics
                .replace(/[ـ]/g, '')                              // Arabic tatweel
                .replace(/[ً-ْ]/g, '')                       // Arabic harakat
                .replace(/[آأإٱ]/g, 'ا')      // alef variants → ا
                .replace(/ة/g, 'ه')                          // ة → ه
                .replace(/ى/g, 'ي')                          // ى → ي
                .trim();
        },
        visibleItems() {
            let a = this.allItems();
            if (this.activeCat !== 'all') a = a.filter(i => i.cat === this.activeCat);
            const q = this.norm(this.search);
            if (q) a = a.filter(i => this.norm(i.name).includes(q) || this.norm(i.description).includes(q));
            return a;
        },
        // T1-5: topbar search focus/clear (also driven by the / and F2 shortcuts).
        focusSearch() { const el = document.getElementById('pos-search'); if (el) { el.removeAttribute('readonly'); el.focus(); el.select(); } },
        clearSearch() { this.search = ''; },
        // ── T1-6: barcode / PLU wedge ──
        // Resolve an item by PLU and quick-add it (opens the sheet if it has mods).
        // Returns true on a hit; warns via toast on an unknown code.
        addByPlu(code) {
            const c = this.norm(code);
            if (!c) return false;
            const it = this.allItems().find(i => i.plu && this.norm(i.plu) === c);
            if (it) { this.add(it); return true; }
            this.showToast(T.unknownCode ? (T.unknownCode + ' ' + code) : ('Unknown code: ' + code), 'warn');
            return false;
        },
        // Manual path: exact PLU typed into the search + Enter adds and clears.
        searchEnter() {
            const q = (this.search || '').trim();
            if (q && this.allItems().some(i => i.plu && this.norm(i.plu) === this.norm(q))) {
                if (this.addByPlu(q)) this.clearSearch();
            }
        },
        // Scanner path: a wedge emits digits <50ms apart ending in Enter. A burst of
        // ≥4 fast digits is treated as a scan (slow human typing never triggers it).
        _scan: { buf: '', last: 0 },
        initScanner() {
            window.addEventListener('keydown', (e) => {
                if (this.locked) return;
                const t = e.target;
                const editable = t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable);
                const now = performance.now();
                if (/^[0-9]$/.test(e.key)) {
                    if (now - this._scan.last > 50) this._scan.buf = ''; // gap → new sequence
                    this._scan.buf += e.key;
                    this._scan.last = now;
                    return;
                }
                if (e.key === 'Enter') {
                    const fast = (now - this._scan.last) <= 50;
                    if (fast && this._scan.buf.length >= 4 && !editable) {
                        e.preventDefault();
                        this.addByPlu(this._scan.buf);
                    }
                    this._scan.buf = '';
                    return;
                }
                if (e.key.length === 1) this._scan.buf = ''; // any other char breaks the burst
            });
        },
        // ── lightweight toast (UI-KIT §4) ──
        toast: { msg: '', type: '', show: false, timer: null },
        showToast(msg, type = '') {
            this.toast.msg = msg; this.toast.type = type; this.toast.show = true;
            clearTimeout(this.toast.timer);
            this.toast.timer = setTimeout(() => { this.toast.show = false; }, 2600);
        },
        // Modifiers present → needs the customize sheet before adding.
        hasMods(it) { return (it.variants && it.variants.length) || (it.extras && it.extras.length) || (it.variant_groups && it.variant_groups.length); },
        // ── T6-3: choice-group (variant-option) selection in the sheet ──
        optGroups(it) { return (it && it.variant_groups) ? it.variant_groups : []; },
        optSingle(g) { return g.max_select === 1; },
        optSelected(g, label) { return (this.sheet.options[g.id] || []).includes(label); },
        toggleOption(g, label) {
            const cur = (this.sheet.options[g.id] || []).slice();
            if (this.optSingle(g)) { this.sheet.options[g.id] = [label]; return; }
            const i = cur.indexOf(label);
            if (i === -1) {
                if (g.max_select > 0 && cur.length >= g.max_select) return; // cap
                cur.push(label);
            } else { cur.splice(i, 1); }
            this.sheet.options[g.id] = cur;
        },
        optGroupMin(g) { return g.required ? Math.max(1, g.min_select || 0) : (g.min_select || 0); },
        optGroupValid(g) {
            const n = (this.sheet.options[g.id] || []).length;
            if (n < this.optGroupMin(g)) return false;
            if (g.max_select > 0 && n > g.max_select) return false;
            return true;
        },
        sheetOptionsValid() { return this.optGroups(this.sheet.item).every(g => this.optGroupValid(g)); },
        // Per-group helper text ("Required", "Pick 1", "Pick 2–3", "Pick up to 3").
        optGroupHelper(g) {
            const min = this.optGroupMin(g), max = g.max_select || 0;
            if (min === 0 && max === 0) return '';
            if (min === max) return (T.pick || 'Pick') + ' ' + min;
            if (min > 0 && max > 0) return (T.pick || 'Pick') + ' ' + min + '–' + max;
            if (min > 0) return (T.atLeast || 'Pick at least') + ' ' + min;
            return (T.upTo || 'Pick up to') + ' ' + max;
        },
        // Build the snapshot [{group_id,title,selected:[{option,price}]}] from a
        // {group_id:[labels]} state, plus its unit price.
        optionsSnapshot(it, state) {
            const snap = [];
            for (const g of this.optGroups(it)) {
                const sel = state[g.id] || [];
                if (!sel.length) continue;
                const selSnap = sel.map(label => {
                    const o = (g.options || []).find(o => o.label === label);
                    const picked = { option: label, price: o ? o.price : 0 };
                    if (o && o.id != null) picked.option_id = o.id; // OP-9: price by id when the snapshot option has one
                    return picked;
                });
                snap.push({ group_id: g.id, title: g.title, selected: selSnap });
            }
            return snap;
        },
        optionsPrice(snap) { return snap.reduce((s, g) => s + g.selected.reduce((a, o) => a + (o.price || 0), 0), 0); },
        // Lowest orderable price for the card ("from …" when variants are cheaper/dearer).
        fromPrice(it) {
            let p = it.price;
            if (it.variants && it.variants.length) p = Math.min(p, ...it.variants.map(v => v.price));
            return p;
        },
        variantLabel(v) {
            if (!v || !v.options) return '';
            if (Array.isArray(v.options)) return v.options.join(' · ');
            if (typeof v.options === 'object') return Object.values(v.options).join(' · ');
            return String(v.options);
        },
        // Tap an item: quick-add when it has no modifiers, else open the sheet.
        add(it) {
            if (it.is_sold_out) return; // OP-9: the saved menu keeps sold-out items visible but not sellable
            if (!this.hasMods(it)) { this.pushLine(it, null, [], '', 1); return; }
            this.sheet = { open: true, item: it, variation: null, extras: [], note: '', qty: 1, editIndex: null, options: {} };
        },
        // T1-2: tap a cart line's name to reopen the sheet pre-filled for editing;
        // confirm REPLACES that line (qty preserved) instead of stacking a new one.
        editLine(i) {
            const l = this.cart[i];
            const it = this.allItems().find(x => x.id === l.id);
            if (!it) return; // item no longer on the menu (branch/availability change)
            // Rebuild the {group_id:[labels]} sheet state from the line snapshot.
            const options = {};
            for (const g of (l.options || [])) { options[g.group_id] = g.selected.map(s => s.option); }
            this.sheet = {
                open: true, item: it,
                variation: l.variation || null,
                extras: l.extras.map(e => e.id),
                note: l.note || '', qty: l.qty, editIndex: i, options,
            };
        },
        removeLine(i) { this.cart.splice(i, 1); },
        closeSheet() { this.sheet.open = false; },
        toggleExtra(id) {
            const i = this.sheet.extras.indexOf(id);
            i === -1 ? this.sheet.extras.push(id) : this.sheet.extras.splice(i, 1);
        },
        // Mirrors Order::getTotalAttribute — variant price replaces base; extras add.
        unitPriceFor(it, variationId, extraIds) {
            let p = it.price;
            if (variationId) { const v = (it.variants || []).find(v => v.id === variationId); if (v) p = v.price; }
            for (const id of extraIds) { const e = (it.extras || []).find(e => e.id === id); if (e) p += e.price; }
            return p;
        },
        sheetUnitPrice() {
            if (!this.sheet.item) return 0;
            const base = this.unitPriceFor(this.sheet.item, this.sheet.variation, this.sheet.extras);
            return base + this.optionsPrice(this.optionsSnapshot(this.sheet.item, this.sheet.options || {}));
        },
        confirmSheet() {
            const s = this.sheet;
            if (!this.sheetOptionsValid()) return; // Add is disabled, but guard anyway
            const opts = s.options || {};
            if (s.editIndex !== null && s.editIndex !== undefined && this.cart[s.editIndex]) {
                // T1-2: replace the edited line in place (no merge), preserving position.
                this.cart.splice(s.editIndex, 1, this.makeLine(s.item, s.variation, s.extras.slice(), s.note.trim(), s.qty, opts));
            } else {
                this.pushLine(s.item, s.variation, s.extras.slice(), s.note.trim(), s.qty, opts);
            }
            this.sheet.open = false;
        },
        // Build a normalised cart line (variant label + resolved extras + options snapshot + signature).
        makeLine(it, variationId, extraIds, note, qty, optionsState) {
            const v = variationId ? (it.variants || []).find(v => v.id === variationId) : null;
            const extras = extraIds.map(id => (it.extras || []).find(e => e.id === id)).filter(Boolean)
                .map(e => ({ id: e.id, title: e.title, price: e.price }));
            const options = this.optionsSnapshot(it, optionsState || {}); // T6-3
            const optSig = options.map(g => g.group_id + ':' + g.selected.map(s => (s.option_id != null ? s.option_id : s.option)).sort().join('+')).join(';');
            const sig = it.id + '|' + (variationId || '') + '|' + extraIds.slice().sort().join(',') + '|' + note + '|' + optSig;
            return {
                sig, id: it.id, name: it.name,
                noPromotions: !!it.no_promotions, // P1-23: a medicine where the market blocks promotions
                variation: variationId || null,
                variationLabel: v ? this.variantLabel(v) : '',
                extras, note, options,
                unitPrice: this.unitPriceFor(it, variationId, extraIds) + this.optionsPrice(options),
                qty,
            };
        },
        // Add or stack a cart line. Identical (item+variation+extras+note+options) lines merge.
        pushLine(it, variationId, extraIds, note, qty, optionsState) {
            const line = this.makeLine(it, variationId, extraIds, note, qty, optionsState);
            const existing = this.cart.find(l => l.sig === line.sig);
            if (existing) { existing.qty += qty; return; }
            this.cart.push(line);
        },
        dec(i) { this.cart[i].qty--; if (this.cart[i].qty <= 0) this.cart.splice(i, 1); },
        // OP-9: offline, every money figure comes from the snapshot through the shared TillCalculator (offlineCalc), so
        // the screen shows the integers the sale will carry; online it is the old float path, unchanged.
        subtotal() { const o = this.offlineCalc && this.offlineCalc(); return o ? o.subtotal : this.cart.reduce((s, l) => s + l.unitPrice * l.qty, 0); },
        // ── T1-3: per-order discount + coupon ──
        openAdd(kind) { this.addSheet = kind; if (kind === 'coupon') this.coupon.error = ''; },
        closeAdd() { this.addSheet = null; },
        // Manual discount resolved to an amount (percent is of the live subtotal),
        // capped at the subtotal. The server field is `discount_amount`.
        discountAmount() {
            const o = this.offlineCalc && this.offlineCalc();
            if (o) return o.discount;
            const sub = this.subtotal();
            let amt = this.discount.mode === 'percent'
                ? (sub * (Number(this.discount.value) || 0)) / 100
                : (Number(this.discount.value) || 0);
            return Math.min(Math.max(0, Math.round(amt * 100) / 100), sub);
        },
        clearDiscount() { this.discount = { mode: 'amount', value: 0 }; },
        // Coupon preview value (server re-resolves authoritatively on store).
        couponValue() { return this.coupon.applied ? (Number(this.coupon.applied.value) || 0) : 0; },
        async checkCoupon() {
            const code = (this.coupon.code || '').trim();
            if (!code) { this.coupon.error = ''; return; }
            this.coupon.checking = true; this.coupon.error = '';
            const r = await this.post(this.posUrl(R.couponCheck), { code, subtotal: this.subtotal() });
            this.coupon.checking = false;
            if (r.ok && r.data && r.data.valid) {
                this.coupon.applied = { code: r.data.code, value: r.data.value };
                this.addSheet = null;
            } else {
                this.coupon.applied = null;
                this.coupon.error = (r.data && r.data.message) || T.couponInvalid || 'Invalid coupon';
            }
        },
        clearCoupon() { this.coupon = { code: '', applied: null, checking: false, error: '' }; },
        // ── T1-4: totals v2 — client mirror of Order::computeTax + delivery ──
        // P6-271: tax is computed on the subtotal AFTER the discount and coupon
        // (never below 0), exactly like the server. `percentage` (default) or `fixed`.
        taxPreview() {
            const o = this.offlineCalc && this.offlineCalc();
            if (o) return o.tax;
            const cfg = B.tax || {};
            const charge = Number(cfg.charge) || 0;
            const base = Math.max(0, this.subtotal() - this.discountAmount() - this.couponValue());
            if (charge <= 0 || base <= 0) return 0;
            // the store currency's minor units (0 / 2 / 3), as the server rounds
            const f = Math.pow(10, Number.isInteger(B.currencyDecimals) ? B.currencyDecimals : 2);
            if (cfg.type === 'fixed') return Math.round(charge * f) / f;
            // Compute in integer minor units: (base in minor) × percentage / 100.
            // Dividing first exposed IEEE-754 drift (14.50 × 15% became 2.174999…
            // and displayed 2.17) while PHP's authoritative round() persisted 2.18.
            return Math.round(Math.round(base * f) * charge / 100) / f;
        },
        // Delivery charge applies to delivery orders only (mirrors Order::total).
        deliveryPreview() {
            if (this.offlineCalc && this.offlineCalc()) return 0; // delivery is not sold offline
            const cfg = B.tax || {};
            return this.type === 'delivery' ? (Number(cfg.delivery) || 0) : 0;
        },
        // ── T5-5: store-credit (wallet) redemption ──
        // Available balance on the attached customer (0 for walk-ins).
        walletAvailable() { return (this.customer && Number(this.customer.wallet)) ? Number(this.customer.wallet) : 0; },
        // Applied credit — capped at the balance, the pre-wallet payable, and the
        // optional partial amount. Server re-gates on customer_id + recomputes.
        walletApplied() {
            if (!this.useWallet || !this.customer) return 0;
            const cap = this.payableBeforeWallet();
            const avail = this.walletAvailable();
            const req = (this.walletAmt !== null && this.walletAmt !== undefined && this.walletAmt !== '')
                ? Number(this.walletAmt) : avail;
            return Math.max(0, Math.round(Math.min(avail, req, cap) * 100) / 100);
        },
        // T2-4: loyalty earn preview mirroring the server seam — stamps → +1/order,
        // points → floor(payable * earn_rate / 100). Read-only; no redemption here.
        loyaltyActive() { return !!(B.loyalty && B.loyalty.active); },
        loyaltyEarnPreview() {
            const cfg = B.loyalty || {};
            if (!cfg.active || !this.cart.length) return 0;
            // P1-23: lines promotions may not touch (medicines where the market blocks them) earn nothing —
            // mirrors LoyaltyAccrual::earnForOrder (points on the other lines' share; a stamp needs one such line).
            const all = this.subtotal();
            const eligible = this.cart.reduce((s, l) => s + (l.noPromotions ? 0 : l.unitPrice * l.qty), 0);
            if (cfg.type === 'stamps') return eligible > 0 || all <= 0 ? 1 : 0;
            const share = all > 0 ? eligible / all : 1;
            return Math.floor(this.payable() * share * (Number(cfg.earn_rate) || 0) / 100);
        },
        // Order value to collect BEFORE wallet credit (a tender, not a discount).
        payableBeforeWallet() {
            const gross = this.subtotal() + this.taxPreview() + this.deliveryPreview();
            return Math.max(0, gross - this.discountAmount() - this.couponValue());
        },
        // Live payable preview. Server stays authoritative on the placed order:
        //   subtotal + tax + delivery − discount − coupon − wallet.
        payable() { return Math.max(0, this.payableBeforeWallet() - this.walletApplied()); },
        openCash() { this.tendered = 0; this.cashOpen = true; },
        // T4-2: hold-with-note — open a small card, confirm parks the cart with a note.
        openHold() { this.holdNote = ''; this.addSheet = 'hold'; },
        confirmHold() { this.addSheet = null; this.submit(true); },
        payload(held) {
            const body = {
                type: this.type, held, branch_id: this.branch || null,
                // T3-3: dine-in table (only when the order is on-table). Coerced to a
                // string — table_no comes back numeric from the tables payload, but the
                // create endpoint validates table_number as a string (nullable|string).
                table_number: (this.type === 'on-table' && this.table && this.table.no != null) ? String(this.table.no) : null,
                // Gap 2: guests/covers on a dine-in order (null on takeaway/delivery).
                covers: (this.type === 'on-table' && this.covers) ? this.covers : null,
                // T4-2: hold note on hold; otherwise the order-level note (orders.message).
                note: (held && this.holdNote) ? this.holdNote.trim() : (this.orderNote ? this.orderNote.trim() : null),
                // T2-2: attached customer (null = walk-in). Server validates tenancy.
                customer_id: this.customer ? this.customer.id : null,
                // T5-5: wallet redemption (server gates on customer_id + balance).
                use_wallet: !!(this.useWallet && this.customer),
                wallet_amount: (this.useWallet && this.walletAmt !== null && this.walletAmt !== '') ? Number(this.walletAmt) : null,
                // T1-3: manual discount (amount) + coupon code stack server-side.
                discount_amount: this.discountAmount() || null,
                coupon_code: this.coupon.applied ? this.coupon.applied.code : null,
                items: this.cart.filter(l => !l.combo_id).map(l => ({
                    item_id: l.id, quantity: l.qty,
                    variation: l.variation || null,
                    extras: l.extras.map(e => e.id),
                    note: l.note || null,
                    // T6-3: choice-group selections → {group_id, selected:[labels]}.
                    options: (l.options || []).map(g => ({ group_id: g.group_id, selected: g.selected.map(s => s.option) })),
                })),
                // T6: combo lines — the server (ComboService::resolve) re-derives the
                // price from combo_id + picks; nothing priced is trusted from here.
                combos: this.cart.filter(l => l.combo_id).map(l => ({
                    combo_id: l.combo_id, quantity: l.qty, note: l.note || null, picks: l.picks,
                })),
            };
            body.client_order_id = this.clientOrderId(body);
            return body;
        },
        // Branch price/availability differ, so a branch change refetches the menu.
        reloadForBranch() { this.cart = []; this.load(); },
        // Persist the menu language (cookie the storefront + Menu accessors read) and reload.
        switchLang(code) {
            document.cookie = 'Quick_user_lang_code=' + encodeURIComponent(code) + ';path=/;max-age=' + (60 * 60 * 24 * 365);
            location.reload();
        },
        async post(url, body, { queue = true } = {}) {
            try {
                const r = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
                    body: JSON.stringify(body),
                });
                if (r.status === 409) this.orderKey = null; // J27-A2: key already used for another cart
                return { ok: r.ok, status: r.status, data: await r.json().catch(() => ({})) };
            } catch (e) {
                // queue:false callers (e.g. device heartbeats) must never pile up in the
                // offline queue or flip the register offline — just report the failure.
                if (!queue) return { ok: false, queued: false, data: {} };
                // Network down — queue the mutation (client_uuid makes replay idempotent).
                // T4-4: stamp the enqueue time for the offline tab's row.
                try { await this.idbAdd({ url, body, ts: Date.now() }); }
                catch (err) {
                    if (this.tillStore && !this.tillStore.isOpen()) throw err; // locked: refused, never written anywhere
                    // storage full / storage error: the sale was NOT saved — say so; the caller keeps the cart
                    this.offline = true;
                    return { ok: false, queued: false, storageFull: true, data: { message: T.storageFull || 'Storage is full: this sale was NOT saved.' } };
                }
                this.offline = true;
                this.refreshQueued();
                return { ok: false, queued: true, data: {} };
            }
        },
        // ── Offline queue: the till's ENCRYPTED store (pos-store.js) ──
        // Rows are AES-GCM encrypted under a data key that is only in memory while the store is open (escrow online,
        // a staff PIN offline). The old unencrypted `pos-queue` is moved in on the first open, never dropped.
        tillStore: null,
        storageWarning: false, // browser storage may be evicted: shown on browser tills (cash-only offline)
        async initTillStore() {
            if (!window.TillStore || !window.crypto || !crypto.subtle) return;
            this.tillStore = new TillStore.Store(new TillStore.IdbBackend('pos-till-' + this.storeId));
            try { if (navigator.storage && navigator.storage.persist) this.storageWarning = !(await navigator.storage.persist()); } catch (e) { this.storageWarning = true; }
            const d = this.ensureDevice ? await this.ensureDevice() : null;
            if (d && navigator.onLine && R.offlineDevicesEscrow) {
                try {
                    const res = await fetch(this.posUrl(R.offlineDevicesEscrow), { headers: { 'X-Pos-Device': d.id, 'X-Pos-Device-Token': d.token, 'Accept': 'application/json' } });
                    if (res.ok) await this.tillStore.openWithEscrow((await res.json()).escrow);
                } catch (e) { /* stays locked: a PIN opens it */ }
            }
            await this.migrateLegacyQueue();
            this.refreshQueued();
        },
        // The old plaintext queue: each item goes into the encrypted store first, then leaves the old one.
        async migrateLegacyQueue() {
            if (!this.tillStore || !this.tillStore.isOpen()) return;
            const legacy = {
                all: () => this.legacyAll(),
                del: (id) => this.legacyDel(id),
                destroy: () => new Promise((res) => { const r = indexedDB.deleteDatabase('pos-queue'); r.onsuccess = r.onerror = r.onblocked = () => res(); }),
            };
            try { await this.tillStore.migrateLegacy(legacy); } catch (e) { /* kept in the old store; retried next open */ }
        },
        legacyIdb() {
            return new Promise((res, rej) => {
                const r = indexedDB.open('pos-queue', 1);
                r.onupgradeneeded = () => r.result.createObjectStore('q', { keyPath: 'id', autoIncrement: true });
                r.onsuccess = () => res(r.result);
                r.onerror = () => rej(r.error);
            });
        },
        async legacyAll() { const db = await this.legacyIdb(); return new Promise((res) => { const t = db.transaction('q', 'readonly'); const rq = t.objectStore('q').getAll(); rq.onsuccess = () => { res(rq.result || []); db.close(); }; }); },
        async legacyDel(id) { const db = await this.legacyIdb(); return new Promise((res) => { const t = db.transaction('q', 'readwrite'); t.objectStore('q').delete(id); t.oncomplete = () => { res(); db.close(); }; }); },
        // No sale is ever written unencrypted: while the store is locked (restarted offline, no PIN typed yet) the
        // register stays on the PIN lock screen and this code path refuses too, whatever called it.
        assertStoreOpen() {
            if (this.tillStore && !this.tillStore.isOpen()) { this.locked = true; throw new Error('till store locked: enter a staff PIN'); }
        },
        async idbAdd(item) {
            if (!this.tillStore || !this.tillStore.isOpen()) { this.locked = !!this.tillStore; throw new Error('till store locked: nothing queued'); }
            return this.tillStore.add({ url: item.url, body: item.body }, item.ts);
        },
        async idbAll() {
            if (!this.tillStore) return this.legacyAll();
            const own = this.tillStore.isOpen() ? await this.tillStore.items() : (await this.tillStore.meta()).map((m) => ({ ...m, url: m.path, body: null }));
            return own.concat(await this.legacyAll().catch(() => []));
        },
        async idbDel(id) { if (this.tillStore) await this.tillStore.discard(id); },
        // OP-9: the typed records (sale, tender, void) go to the one replay endpoint, in sequence order, up to 50 a request.
        async flushRecords() {
            if (!this.tillStore || !this.tillStore.isOpen() || !navigator.onLine) return;
            const d = this._device ? this._device() : null;
            if (!d || !R.offlineReplay) return;
            await this.tillStore.flushRecords(async (records) => {
                const body = { device_id: d.id, device_clock: new Date().toISOString(), records };
                if (this.branch) body.branch_id = this.branch;
                const r = await fetch(this.posUrl(R.offlineReplay), { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, this._deviceHeaders(d)), body: JSON.stringify(body) });
                return { status: r.status, data: await r.json().catch(() => ({})) };
            }).catch(() => {});
            if (this.refreshOffline) this.refreshOffline();
        },
        // A sale and its tender are one waiting sale: count the sales (and the old raw requests), not every record.
        async refreshQueued() { this.queued = (await this.idbAll().catch(() => [])).filter((it) => !(it.record && it.body && it.body.type && it.body.type !== 'sale')).length; },
        async flush() {
            if (!this.tillStore) return;
            await this.migrateLegacyQueue();
            await this.flushRecords();
            await this.tillStore.flush(async (url, body) => {
                const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' }, body: JSON.stringify(body) });
                return { status: r.status };
            }).catch(() => {});
            this.refreshQueued();
        },
        // T7-5: single in-flight guard so a double-tap can't place two orders.
        sending: false,
        // The just-created order (for the "Print order" action after a send).
        lastOrderId: null,
        async submit(held) {
            if (this.sending) return;
            if (this.salesBlocked) return alert(T.offlineBlocked || 'Connect to continue.'); // offline till rules (pos-device.js)
            this.assertStoreOpen();
            // OP-9: offline with a usable snapshot, a sale is priced and queued locally (kitchen / hold need the server)
            if (this.offlineNow && this.offlineNow() && this.offlineSaleAvailable()) return this.offlineSubmit(held);
            this.sending = true;
            try {
                const res = await this.post(this.posUrl(R.ordersStore), this.payload(held));
                if (res.ok) {
                    if (!held && res.data && res.data.id) {
                        this.lastOrderId = res.data.id;   // enable the manual Print button
                        this.autoPrint(res.data.print);   // + auto-print per settings (no-op if off)
                    }
                    this.reset();
                    alert(held ? T.orderHeld : T.sentToKitchen);
                }
                else if (res.queued) { this.reset(); alert(T.offlineQueued); }
                else alert(res.data.message || 'Error');
            } finally { this.sending = false; }
        },
        // Manual print of the last created order — opens the receipt regardless of
        // the auto-print setting. `?format=kitchen` prints the kitchen ticket instead.
        printOrder(format) {
            if (!this.lastOrderId) return;
            const q = '?store=' + B.storeId + '&print=1&lang=' + (B.lang || '')
                + (format === 'kitchen' ? '&format=kitchen' : '');
            window.open(this.posUrl(R.ordersBase + '/' + this.lastOrderId + '/receipt') + q, '_blank');
        },
        async submitPaid(method) {
            if (this.sending) return;
            if (this.salesBlocked) return alert(T.offlineBlocked || 'Connect to continue.');
            this.assertStoreOpen();
            // OP-9: a till that KNOWS it is offline sells from the snapshot. A request that fails mid-flight is not retried as a
            // second, differently keyed sale (the server may have created the first one): it is queued with its own key, as before.
            if (this.offlineNow && this.offlineNow() && this.offlineSaleAvailable()) return this.offlineSubmitPaid(method);
            this.sending = true;
            try {
                const res = await this.post(this.posUrl(R.ordersStore), this.payload(false));
                if (res.queued) { this.cashOpen = false; this.reset(); return alert(T.offlineQueuedSettle); }
                if (!res.ok) return alert(res.data.message || 'Error');
                const settleUrl = this.posUrl(R.ordersBase + '/' + res.data.id + '/settle');
                const body = method === 'cash' ? { method, tendered: this.tendered } : { method };
                if (this.tipAmount > 0) body.tip = this.tipAmount; // T5-3: tip on settle
                const s = await this.post(settleUrl, body);
                this.cashOpen = false;
                if (s.ok) { this.reset(); this.autoPrint(s.data.print); alert(T.paid + ' ' + (s.data.change_due ? T.change + ': ' + this.money(s.data.change_due) : '')); }
                else alert(s.data.message || 'Error');
            } finally { this.sending = false; }
        },
        // P1: open the configured receipt/kitchen route(s) on settle; the receipt
        // page auto-calls window.print(). The OS dialog still picks the printer.
        //
        // T1: when the store has configured real printers, the server has already
        // rendered and parked ESC/POS jobs for this order — collect them now
        // instead of waiting for the 5 s poll, and skip the browser dialog so the
        // cashier does not get a popup AND a ticket.
        autoPrint(p) {
            if (this.printEnabled && this.printProfiles.length) { this.printPoll(); return; }
            if (!p || p.auto === 'off') return;
            const urls = [p.receipt_url, p.kitchen_url].filter(Boolean);
            for (const u of urls) {
                for (let i = 0; i < (p.copies || 1); i++) { window.open(u, '_blank'); }
            }
        },
        reset() { this.orderKey = null; this.cart = []; this.tendered = 0; this.holdNote = ''; this.orderNote = ''; this.clearDiscount(); this.clearCoupon(); this.addSheet = null; if (this.detachCustomer) this.detachCustomer(); if (this.releaseTable) this.releaseTable(); },
    };

    // Compose feature modules (none yet at P0-1) over the core state. A module is
    // either a plain object or a factory function returning one.
    const modules = Object.values(window.PosModules).map(m => (typeof m === 'function' ? m() : m));
    return Object.assign({}, ...modules, coreState);
}

// Node tests load this file with createRequire (lock-trap.test.mjs); the browser gets
// the global posRegister()/lockTrap declarations above.
if (typeof module === 'object' && module.exports) module.exports = { lockTrap: lockTrap };
