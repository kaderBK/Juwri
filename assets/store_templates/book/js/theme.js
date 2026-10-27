// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* book: the booking theme. Plain ES module, no dependencies, no storefront core (a booking has no cart: the services are the
   checkboxes of a form that goes to the booking engine, which holds the time). Everything here is an enhancement; the pages work
   without it. Money: nothing here adds, rounds or formats an amount — the total is the server's quote (boot: endpoints.quote).
     [data-book-form]            the services form: running summary on the bar and the panel, the server quote
     [data-book-location]        where (venue / at home): shows only the services that can be done there
     [data-book-search]          filters the services by name and description (Arabic letters normalised)
     [data-book-open]            opens a service's sheet (the server renders it)
     [data-book-refresh]         the choose-a-time page: staff and day reload the times; the picked time shows in the footer
     [data-book-confirm]         asks before a destructive action */

const $ = (root, sel) => root.querySelector(sel);
const $$ = (root, sel) => Array.from(root.querySelectorAll(sel));
const safe = (fn) => { try { return fn(); } catch (e) { return null; } };

export function normalize(s) {
    return String(s || '')
        .normalize('NFKD')
        .replace(/[ً-ٰٟـ]/g, '')
        .replace(/[آأإ]/g, 'ا')
        .replace(/ة/g, 'ه')
        .replace(/ى/g, 'ي')
        .toLowerCase()
        .trim();
}

const MAX_SERVICES = 4;

/** "1 h 15 min" from minutes, in the page language's own digits and unit words (the page's data-unit-* attributes carry them). */
function minutes(total, units) {
    const h = Math.floor(total / 60);
    const m = total % 60;
    const part = (n, key) => (units[key] || '').replace(':count', String(n));
    if (h === 0) return part(m, 'min');
    return m === 0 ? part(h, 'h') : (units.hm || '').replace(':hours', String(h)).replace(':minutes', String(m));
}

function csrf() {
    const m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.getAttribute('content') : '';
}

function boot() {
    const el = document.querySelector('script[type="application/json"][data-book-boot]');
    return el ? safe(() => JSON.parse(el.textContent)) : null;
}

function home() {
    const form = document.querySelector('[data-book-form]');
    if (!form) return;
    const b = boot() || {};
    const units = b.units || {};
    const boxes = () => $$(document, 'input[data-book-service]');
    const bar = document.querySelector('[data-book-bar]');
    const lines = document.querySelector('[data-book-lines]');
    const empty = document.querySelector('[data-book-empty]');
    const continues = $$(document, '[data-book-continue], .c-bkbar__pill');
    let seq = 0;

    const location = () => {
        const r = document.querySelector('input[data-book-location]:checked');
        return r ? r.value : 'venue';
    };

    async function quote(ids) {
        if (!b.quote || ids.length === 0) return null;
        const mine = ++seq;
        try {
            const res = await fetch(b.quote, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
                body: JSON.stringify({ lines: ids.map((item) => ({ item, qty: 1 })), order_type: location() === 'at_home' ? 'at_home' : 'appointment' }),
            });
            if (!res.ok || mine !== seq) return null;
            return (await res.json()).quote || null;
        } catch (e) { return null; }
    }

    async function refresh() {
        const on = boxes().filter((x) => x.checked);
        const ids = on.map((x) => x.value);
        for (const x of boxes()) {
            const li = x.closest('.c-svc');
            if (li) li.classList.toggle('is-selected', x.checked);
            x.disabled = !x.checked && ids.length >= MAX_SERVICES;
        }
        const mins = on.reduce((sum, x) => sum + (Number(x.closest('.c-svc').dataset.min) || 0), 0);
        const label = on.length === 0 ? (units.none || '') : [(units.counts || {})[on.length] || '', minutes(mins, units)].join(' · ');
        for (const el of $$(document, '[data-book-summary]')) el.textContent = label;
        if (bar) bar.hidden = on.length === 0;
        for (const c of continues) c.disabled = on.length === 0 || c.hasAttribute('data-book-closed');
        if (lines) {
            lines.replaceChildren(...on.map((x) => {
                const li = document.createElement('li');
                li.className = 'c-bkline';
                const t = document.createElement('div'); t.className = 'c-bkline__text';
                const n = document.createElement('p'); n.className = 'c-bkline__name'; n.textContent = x.dataset.name;
                t.append(n);
                const p = document.createElement('p'); p.className = 'c-bkline__price'; p.textContent = x.dataset.price;
                li.append(t, p);
                return li;
            }));
        }
        if (empty) empty.hidden = on.length > 0;
        const totals = $$(document, '[data-book-total]');
        for (const t of totals) t.textContent = '';
        const q = await quote(ids);
        if (q) for (const t of totals) t.textContent = q.total.formatted;
    }

    form.addEventListener('change', refresh);
    document.addEventListener('change', (e) => {
        if (e.target.matches('input[data-book-service]')) refresh();
        if (e.target.matches('input[data-book-location]')) { filterLocation(); refresh(); }
    });

    const geo = document.querySelector('[data-book-geo]');
    const locate = geo && $(geo, '[data-book-locate]');
    if (locate && navigator.geolocation) {
        locate.hidden = false;
        locate.addEventListener('click', () => {
            const status = $(geo, '[data-book-geo-status]');
            navigator.geolocation.getCurrentPosition((pos) => {
                $(geo, '[data-book-lat]').value = pos.coords.latitude.toFixed(6);
                $(geo, '[data-book-lng]').value = pos.coords.longitude.toFixed(6);
                if (status) status.textContent = units.located || '';
            }, () => { if (status) status.textContent = units.locate_failed || ''; }, { timeout: 10000 });
        });
    }

    function filterLocation() {
        const at = location() === 'at_home';
        if (geo) geo.hidden = !at;
        for (const li of $$(document, '.c-svc')) {
            const ok = at ? li.dataset.atHome === '1' : li.dataset.venue !== '0';
            li.hidden = !ok;
            if (!ok) { const x = $(li, 'input[data-book-service]'); if (x) x.checked = false; }
        }
        for (const s of $$(document, '.c-cat')) s.hidden = !$$(s, '.c-svc').some((li) => !li.hidden);
    }

    form.addEventListener('submit', (e) => {
        if (!boxes().some((x) => x.checked)) e.preventDefault();
    });
    // the page may arrive with ticked boxes (the browser restores them on Back)
    // a button the server rendered disabled means "no hours yet": it stays off whatever is ticked
    for (const c of continues) if (c.disabled) c.setAttribute('data-book-closed', '');
    filterLocation();
    refresh();
}

function search() {
    const form = document.querySelector('[data-book-search]');
    if (!form) return;
    const input = $(form, 'input[type="search"]');
    form.addEventListener('submit', (e) => e.preventDefault());
    input.addEventListener('input', () => {
        const q = normalize(input.value);
        let shown = 0;
        for (const li of $$(document, '.c-svc')) {
            const hit = q === '' || normalize(li.textContent).includes(q);
            li.hidden = !hit;
            if (hit) shown += 1;
        }
        for (const s of $$(document, '.c-cat')) s.hidden = !$$(s, '.c-svc').some((li) => !li.hidden);
        const none = document.querySelector('[data-book-noresults]');
        if (none) none.hidden = shown > 0 || q === '';
    });
    input.addEventListener('keydown', (e) => { if (e.key === 'Escape') { input.value = ''; input.dispatchEvent(new Event('input')); } });
}

function sheet() {
    const host = document.querySelector('[data-book-sheet-host]');
    if (!host) return;
    const page = document.querySelector('[data-kit-page]');
    const homeUrl = (boot() || {}).home || location.pathname;
    let opener = null;

    function close() {
        const s = document.getElementById('item-sheet');
        if (s) s.remove();
        if (page) page.removeAttribute('inert');
        document.body.classList.remove('is-locked');
        if (/[?&]item=/.test(location.search)) history.replaceState(null, '', location.pathname);
        if (opener && document.contains(opener)) opener.focus();
        opener = null;
    }

    function wire(s, focusTitle = true) {
        const form = $(s, '[data-book-sheet-form]');
        const add = $(s, '[data-book-add]');
        if (add && form) {
            form.addEventListener('submit', (e) => {
                const box = document.querySelector('input[data-book-service][value="' + form.dataset.item + '"]');
                if (!box) return; // not on this page: the form goes straight to choosing a time
                e.preventDefault();
                if (!box.checked && !box.disabled) { box.checked = true; box.dispatchEvent(new Event('change', { bubbles: true })); }
                close();
            });
        }
        for (const c of $$(s, '[data-book-close]')) c.addEventListener('click', (e) => { e.preventDefault(); close(); });
        s.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') { e.preventDefault(); close(); return; }
            // no Tab trap: the page behind is inert, so Tab leaves the sheet at its ends and comes back at the other end
        });
        if (page) page.setAttribute('inert', '');
        s.hidden = false;
        const h = $(s, '#sheet-title'); if (h && focusTitle) h.focus();
    }

    async function open(id, from) {
        const res = await fetch(homeUrl + '?fragment=item&item=' + encodeURIComponent(id), { credentials: 'same-origin', headers: { Accept: 'text/html' } });
        if (!res.ok) return false;
        const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
        const s = doc.getElementById('item-sheet');
        if (!s) return false;
        const old = document.getElementById('item-sheet'); if (old) old.remove();
        opener = from;
        host.replaceChildren(document.importNode(s, true));
        wire(document.getElementById('item-sheet'));
        return true;
    }

    document.addEventListener('click', async (e) => {
        const t = e.target.closest('[data-book-open]');
        if (!t || e.defaultPrevented || e.metaKey || e.ctrlKey || e.shiftKey) return;
        e.preventDefault();
        if (!(await open(t.dataset.bookOpen, t))) location.assign(t.getAttribute('href'));
    });
    // a deep link (?item=) arrives with the sheet in the page: wire it
    const pre = document.getElementById('item-sheet');
    if (pre && !pre.hidden) {
        if (page) page.setAttribute('inert', '');
        wire(pre, false); // a deep link: Tab starts at the close button, the first control of the sheet
    }
}

function times() {
    const refresh = document.querySelector('[data-book-refresh]');
    if (!refresh) return;
    const btn = document.querySelector('[data-book-refresh-btn]');
    if (btn) btn.hidden = true;
    const submit = () => { if (typeof refresh.requestSubmit === 'function') refresh.requestSubmit(); else refresh.submit(); };
    document.addEventListener('change', (e) => {
        if (e.target.matches('input[form="tp-refresh"]')) { submit(); return; }
        const laundry = e.target.matches('input[data-book-slot]');
        if (laundry) {
            // the laundry: the delivery times depend on the pickup time, so choosing the pickup reloads them (the choices ride along)
            const pick = document.querySelector('input[data-book-slot="pickup"]:checked');
            const back = document.querySelector('input[data-book-slot="return"]:checked');
            const kp = document.querySelector('[data-book-keep="pickup"]');
            const kr = document.querySelector('[data-book-keep="return"]');
            if (kp && pick) kp.value = pick.value;
            if (kr) kr.value = back ? back.value : '';
            if (e.target.dataset.bookSlot === 'pickup' && document.querySelector('input[data-book-slot="return"]')) { submit(); return; }
        }
        if (e.target.matches('input[name="starts_at"], input[data-book-slot]')) picked();
    });
    function label(r) {
        const day = r.closest('.c-pick') && document.querySelector('input[name="' + (r.dataset.bookSlot === 'return' ? 'return_date' : 'date') + '"]:checked');
        const d = day ? $(day.closest('.c-day'), '.c-day__dow').textContent + ' ' + $(day.closest('.c-day'), '.c-day__num').textContent.replace(/,.*$/, '') : '';
        return [d.trim(), r.dataset.range || $(r.closest('.c-slot'), '.c-slot__time').textContent].filter(Boolean).join(' ');
    }
    function picked() {
        const out = document.querySelector('[data-book-picked]');
        if (!out) return;
        const rs = $$(document, 'input[name="starts_at"]:checked, input[data-book-slot]:checked');
        if (!rs.length) return;
        out.textContent = rs.map(label).join(' · ');
    }
    picked();
}

function laundry() {
    const form = document.querySelector('[data-book-laundry]');
    if (!form) return;
    const units = (boot() || {}).units || {};
    const inputs = () => $$(document, 'input[data-book-qty]');
    const lines = document.querySelector('[data-book-lines]');
    const empty = document.querySelector('[data-book-empty]');
    const bar = document.querySelector('[data-book-bar]');
    for (const b of $$(document, '[data-book-step]')) b.hidden = false;
    document.addEventListener('click', (e) => {
        const b = e.target.closest('[data-book-step]');
        if (!b) return;
        const input = $(b.closest('.c-qty'), 'input');
        const next = Math.round(((Number(input.value) || 0) + Number(b.dataset.bookStep)) * 1000) / 1000;
        input.value = String(Math.min(999, Math.max(0, next)));
        input.dispatchEvent(new Event('input', { bubbles: true }));
    });
    function refresh() {
        let kg = 0; let pieces = 0; const on = [];
        for (const x of inputs()) {
            const v = Number(x.value) || 0;
            const li = x.closest('.c-svc');
            li.classList.toggle('is-selected', v > 0);
            if (v <= 0) continue;
            on.push([li.dataset.name, v, li.dataset.unit]);
            if (li.dataset.unit === 'kg') kg += v; else pieces += v;
        }
        const parts = [];
        if (kg > 0) parts.push((units.kg || '').replace(':kg', String(Math.round(kg * 1000) / 1000)));
        if (pieces > 0) parts.push((units.pieces || '').replace(':count', String(pieces)));
        for (const el of $$(document, '[data-book-summary]')) el.textContent = parts.length ? parts.join(' · ') : (units.none || '');
        if (bar) bar.hidden = on.length === 0;
        for (const c of $$(document, '[data-book-continue], .c-bkbar__pill')) c.disabled = on.length === 0;
        if (lines) {
            lines.replaceChildren(...on.map(([name, v, unit]) => {
                const li = document.createElement('li'); li.className = 'c-bkline';
                const t = document.createElement('div'); t.className = 'c-bkline__text';
                const n = document.createElement('p'); n.className = 'c-bkline__name'; n.textContent = name;
                const sub = document.createElement('p'); sub.className = 'c-bkline__sub';
                sub.textContent = unit === 'kg' ? (units.kg || '').replace(':kg', String(v)) : (units.pieces || '').replace(':count', String(v));
                t.append(n, sub); li.append(t);
                return li;
            }));
        }
        if (empty) empty.hidden = on.length > 0;
    }
    document.addEventListener('input', (e) => { if (e.target.matches('input[data-book-qty]')) refresh(); });
    form.addEventListener('submit', (e) => { if (!inputs().some((x) => Number(x.value) > 0)) e.preventDefault(); });
    refresh();
}

document.addEventListener('click', (e) => {
    const c = e.target.closest('[data-book-confirm]');
    if (c && !window.confirm(c.dataset.bookConfirm)) e.preventDefault();
});

home();
laundry();
search();
sheet();
times();
