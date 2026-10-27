// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* juwri storefront core — loyalty at the kiosk (item 26). Runs only when `context.kiosk.loyalty` (the owner turned it on) on a
   kiosk session. The customer opts in, types their phone and the code sent to it (or shows the pass QR from their own phone);
   only then does the kiosk show their first name, points, favourites (the Menu recommendation engine) and recent orders.
   Favourites and "Order again" go into the cart through cart.add — priced by the server quote, i.e. today's prices; the
   reorder answer already left out what is gone or sold out and lists lines whose choices must be picked again.
   The server forgets the customer on the idle return, the staff exit and "Not me".

   Hooks: [data-kiosk-loyalty-open] [data-kiosk-overlay="loyalty"] [data-kl-step="phone|code|me"] [data-kl-optin] [data-kl-phone]
   [data-kl-send] [data-kl-code] [data-kl-verify] [data-kl-pass] [data-kl-msg] [data-kl-name] [data-kl-points] [data-kl-reward]
   [data-kl-reward-text] [data-kl-redeem] [data-kl-favs] [data-kl-orders] [data-kl-notme] [data-kl-close]. Events: kiosk:loyalty {recognised}. */
import { emit } from './util.js';

/** The logic, without the DOM (tested on its own). */
export function createKioskLoyalty({ endpoints = {}, api, cart }) {
    const e = endpoints;
    return {
        async requestCode(phone, optIn) {
            if (!optIn) return { ok: false, reason: 'opt_in' };
            const r = await api.post(e.kiosk_loyalty_code, { phone, opt_in: true });
            return { ok: true, message: r.message || '' };
        },
        async verify(phone, code) {
            try { await api.post(e.kiosk_loyalty_verify, { phone, code }); return true; } catch (err) { if (err && err.offline) throw err; return false; }
        },
        async pass(token, optIn) {
            if (!optIn) return false;
            try { await api.post(e.kiosk_loyalty_pass, { token, opt_in: true }); return true; } catch (err) { if (err && err.offline) throw err; return false; }
        },
        async me() {
            try { return await api.getJson(e.kiosk_loyalty_me); } catch (err) { if (err && err.offline) throw err; return null; }
        },
        addFavourite(fav) { if (fav && fav.item) cart.add(fav.item, { qty: 1, name: fav.name || '' }); },
        /** @returns {{added: number, choose: Array, unavailable: Array}} */
        async reorder(orderId) {
            const r = await api.post(e.kiosk_loyalty_reorder, { order: orderId });
            let added = 0;
            for (const l of r.add || []) { cart.add(l.item, { qty: l.qty, variant: l.variant || null, name: l.name || '' }); added += 1; }
            return { added, choose: r.choose || [], unavailable: r.unavailable || [] };
        },
        async forget() { try { await api.post(e.kiosk_loyalty_forget, {}); } catch (err) { /* forgotten on the idle return anyway */ } },
    };
}

/** The reward line shown at the "me" step, without the DOM (tested on its own): `:points` and `:discount` are filled in;
    the "collect" wording is used until the balance reaches the threshold. */
export function rewardText(reward, say) {
    const can = !!(reward && reward.can_redeem);
    const template = say(can ? 'kiosk_reward' : 'kiosk_reward_not_yet', can ? 'Reward: :points points for :discount off' : 'Collect :points points for :discount off');
    const discount = (reward && reward.discount && reward.discount.formatted) || '';
    return String(template).replace(':points', String((reward && reward.threshold) || 0)).replace(':discount', discount);
}

export function startKioskLoyalty({ kiosk, endpoints = {}, api, cart, doc = globalThis.document }) {
    if (!kiosk || !kiosk.on || !kiosk.loyalty || !endpoints.kiosk_loyalty_code) return null;
    const kl = createKioskLoyalty({ endpoints, api, cart });
    const q = (s) => (doc && doc.querySelector ? doc.querySelector(s) : null);
    const box = q('[data-kiosk-overlay="loyalty"]');
    const open = q('[data-kiosk-loyalty-open]');
    if (!box || !open) return kl;
    // the partial carries the translated strings (data-t-*): this file adds no boot i18n keys
    const say = (k, d) => (box.getAttribute && box.getAttribute('data-t-' + k)) || d;
    const step = (name) => { for (const s of box.querySelectorAll('[data-kl-step]')) s.hidden = s.getAttribute('data-kl-step') !== name; };
    const msg = (t) => { const m = box.querySelector('[data-kl-msg]'); if (m) m.textContent = t || ''; };
    const val = (sel) => { const i = box.querySelector(sel); return i ? String(i.value || '').trim() : ''; };
    const optIn = () => { const c = box.querySelector('[data-kl-optin]'); return !!(c && c.checked); };
    // sent with the order (cart extras, cleared after it); the server decides whether the recognised customer may redeem
    const setRedeem = (on) => { if (cart && cart.setExtras) cart.setExtras({ loyalty_redeem: on ? '1' : null }); };
    const reset = () => {
        for (const i of box.querySelectorAll('input')) { if (i.type === 'checkbox') i.checked = false; else i.value = ''; }
        const rb = box.querySelector('[data-kl-reward]'); if (rb) rb.hidden = true;
        const rd = box.querySelector('[data-kl-redeem]'); if (rd) rd.disabled = true;
        setRedeem(false);
        msg(''); step('phone');
    };
    const close = () => { box.hidden = true; };
    const li = (label, btnText, onClick) => {
        const row = doc.createElement('li'); const span = doc.createElement('span'); span.textContent = label; row.appendChild(span);
        const b = doc.createElement('button'); b.type = 'button'; b.className = 'c-btn c-btn--secondary'; b.textContent = btnText; b.addEventListener('click', onClick); row.appendChild(b);
        return row;
    };
    const show = (me) => {
        step('me');
        const n = box.querySelector('[data-kl-name]'); if (n) n.textContent = me.name || '';
        const p = box.querySelector('[data-kl-points]'); if (p) p.textContent = String(me.points || 0);
        const reward = me.reward || null;
        const rb = box.querySelector('[data-kl-reward]'); const rd = box.querySelector('[data-kl-redeem]'); const rt = box.querySelector('[data-kl-reward-text]');
        if (rb) rb.hidden = !reward;
        if (rd) { rd.checked = false; rd.disabled = !(reward && reward.can_redeem); }
        if (rt) rt.textContent = reward ? rewardText(reward, say) : '';
        setRedeem(false);
        const favs = box.querySelector('[data-kl-favs]'); const orders = box.querySelector('[data-kl-orders]');
        if (favs) { favs.textContent = ''; for (const f of me.favourites || []) favs.appendChild(li(`${f.name} · ${(f.price && f.price.formatted) || ''}`, say('add', 'Add'), () => { kl.addFavourite(f); msg(say('added_to_your_order', 'Added to your order')); })); }
        if (orders) {
            orders.textContent = '';
            for (const o of me.orders || []) {
                orders.appendChild(li(`${o.date || ''} · ${o.items} ${say('items', 'items')}`, say('order_again', 'Order again'), async () => {
                    const r = await kl.reorder(o.id);
                    const parts = [];
                    if (r.added) parts.push(say('added_to_your_order', 'Added to your order'));
                    if (r.choose.length) parts.push(say('kiosk_pick_choices_again', 'Pick the choices again for:') + ' ' + r.choose.map((c) => c.name).join(', '));
                    if (r.unavailable.length) parts.push(say('kiosk_not_available_today', 'Not available today:') + ' ' + r.unavailable.join(', '));
                    msg(parts.join(' '));
                }));
            }
        }
        emit('kiosk:loyalty', { recognised: true });
    };
    open.addEventListener('click', () => { reset(); box.hidden = false; });
    for (const b of box.querySelectorAll('[data-kl-close]')) b.addEventListener('click', close);
    const redeem = box.querySelector('[data-kl-redeem]');
    if (redeem) redeem.addEventListener('change', () => setRedeem(!!redeem.checked));
    const on = (sel, fn) => { const b = box.querySelector(sel); if (b) b.addEventListener('click', fn); };
    on('[data-kl-send]', async () => {
        if (!optIn()) { msg(say('kiosk_loyalty_opt_in_first', 'Tick the box to use your account here.')); return; }
        const r = await kl.requestCode(val('[data-kl-phone]'), true).catch(() => ({ ok: false }));
        msg(r.message || ''); if (r.ok) step('code');
    });
    on('[data-kl-verify]', async () => {
        if (await kl.verify(val('[data-kl-phone]'), val('[data-kl-code]')).catch(() => false)) { const me = await kl.me(); if (me) show(me); }
        else msg(say('kiosk_code_did_not_work', 'That code did not work.'));
    });
    // the pass QR, read by the kiosk's scanner (it types the code and Enter into this field)
    const passIn = box.querySelector('[data-kl-pass]');
    if (passIn) passIn.addEventListener('keydown', async (ev) => {
        if (ev.key !== 'Enter') return;
        if (!optIn()) { msg(say('kiosk_loyalty_opt_in_first', 'Tick the box to use your account here.')); return; }
        const ok = await kl.pass(String(passIn.value || '').trim(), true).catch(() => false);
        passIn.value = '';
        if (ok) { const me = await kl.me(); if (me) show(me); } else msg(say('kiosk_code_did_not_work', 'That code did not work.'));
    });
    on('[data-kl-notme]', async () => { await kl.forget(); reset(); close(); emit('kiosk:loyalty', { recognised: false }); });
    return kl;
}
