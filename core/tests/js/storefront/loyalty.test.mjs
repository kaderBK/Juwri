// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// Item 26: the kiosk loyalty logic (no DOM) — opt-in first, nothing sent without it, reorder lines go into the cart through
// cart.add (the server quote prices them: today's prices), and "Not me" asks the server to forget.
import test from 'node:test';
import assert from 'node:assert/strict';
import { createKioskLoyalty, rewardText, startKioskLoyalty } from '../../../../assets/storefront/loyalty.js';
import { fakeApi } from './helpers.mjs';

const ep = { kiosk_loyalty_code: '/s/kiosk/loyalty/code', kiosk_loyalty_verify: '/s/kiosk/loyalty/verify', kiosk_loyalty_pass: '/s/kiosk/loyalty/pass',
    kiosk_loyalty_me: '/s/kiosk/loyalty/me', kiosk_loyalty_reorder: '/s/kiosk/loyalty/reorder', kiosk_loyalty_forget: '/s/kiosk/loyalty/forget' };
const cartFake = () => { const added = []; return { added, add: (item, opts) => { added.push([item, opts.qty, opts.variant || null]); } }; };

test('nothing is sent without the opt-in', async () => {
    const api = fakeApi({});
    const kl = createKioskLoyalty({ endpoints: ep, api, cart: cartFake() });
    assert.deepEqual(await kl.requestCode('+966500000001', false), { ok: false, reason: 'opt_in' });
    assert.equal(await kl.pass('tok', false), false);
    assert.equal(api.calls.length, 0);
});

test('code, verify, reorder through cart.add, forget', async () => {
    const api = fakeApi({
        [ep.kiosk_loyalty_code]: () => ({ ok: true, message: 'If this number has an account here, we sent it a code.' }),
        [ep.kiosk_loyalty_verify]: (b) => (b.code === '123456' ? { ok: true } : { throw: { status: 422 } }),
        [ep.kiosk_loyalty_reorder]: () => ({ add: [{ item: 'tea', qty: 3, variant: null, name: 'Tea' }, { item: 'kabsa', qty: 1, variant: 'v-l', name: 'Kabsa' }], choose: [{ item: 'x', name: 'Burger' }], unavailable: ['Juice'] }),
        [ep.kiosk_loyalty_forget]: () => ({ ok: true }),
    });
    const cart = cartFake();
    const kl = createKioskLoyalty({ endpoints: ep, api, cart });
    assert.equal((await kl.requestCode('+966500000001', true)).ok, true);
    assert.deepEqual(api.calls[0].body, { phone: '+966500000001', opt_in: true });
    assert.equal(await kl.verify('+966500000001', '000000'), false);
    assert.equal(await kl.verify('+966500000001', '123456'), true);
    const r = await kl.reorder(42);
    assert.deepEqual(cart.added, [['tea', 3, null], ['kabsa', 1, 'v-l']], 'only what the server priced as available today');
    assert.deepEqual([r.added, r.choose.map((c) => c.name), r.unavailable], [2, ['Burger'], ['Juice']]);
    await kl.forget();
    assert.equal(api.calls.at(-1).url, ep.kiosk_loyalty_forget);
});

test('the reward line fills :points and :discount, "collect" until it can be redeemed', () => {
    const t = { kiosk_reward: 'Reward: :points points for :discount off', kiosk_reward_not_yet: 'Collect :points points for :discount off' };
    const say = (k, d) => t[k] || d;
    assert.equal(rewardText({ threshold: 100, discount: { formatted: 'SAR 5.00' }, can_redeem: true }, say), 'Reward: 100 points for SAR 5.00 off');
    assert.equal(rewardText({ threshold: 250, discount: { formatted: 'SAR 10.00' }, can_redeem: false }, say), 'Collect 250 points for SAR 10.00 off');
});

test('off unless the owner turned it on', () => {
    assert.equal(startKioskLoyalty({ kiosk: { on: true }, endpoints: ep, api: fakeApi({}), cart: cartFake(), doc: null }), null);
    assert.equal(startKioskLoyalty({ kiosk: { on: true, loyalty: true }, endpoints: {}, api: fakeApi({}), cart: cartFake(), doc: null }), null);
});
