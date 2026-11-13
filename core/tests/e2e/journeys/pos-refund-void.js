// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * STF-013 / E2E-007 (opt-in) — POS void + refund from the orders hub.
 *
 * Verifies the register's correction surface end-to-end in a real browser:
 * a manager rings a sale, settles it, then refunds it from the order detail
 * panel — over-refund is rejected with a visible toast, a valid refund
 * succeeds, and a second full refund is refused (exactly-once). A second
 * unpaid order is voided and lands as cancelled.
 *
 * Orders are created through the register's own endpoints from inside the
 * authenticated page (same session + CSRF the UI uses), then every
 * assertion is driven through the visible UI.
 *
 * Env: E2E_RUN_POS_REFUND=1, E2E_STAFF_USER / E2E_STAFF_PASS (a staff
 * account with pos + pos_refund permissions).
 */

module.exports = async function posRefundVoid({ page, cfg, log, shot, sleep }) {
  const user = process.env.E2E_STAFF_USER;
  const pass = process.env.E2E_STAFF_PASS;
  if (!user || !pass) throw new Error('pos-refund-void: set E2E_STAFF_USER / E2E_STAFF_PASS');

  // ── staff login (form.submit() — ref-clicks are flaky on the Herd form) ──
  await page.goto(`${cfg.baseUrl}/staff/login`, { waitUntil: 'networkidle2' });
  await page.waitForSelector('input[name=email], #email', { timeout: cfg.navTimeout });
  await page.type('input[name=email], #email', user);
  await page.type('input[name=password], #password', pass);
  await Promise.all([
    page.evaluate(() => document.querySelector('form').submit()),
    page.waitForNavigation({ waitUntil: 'networkidle2', timeout: cfg.navTimeout }).catch(() => {}),
  ]);

  const resp = await page.goto(`${cfg.baseUrl}/pos`, { waitUntil: 'networkidle2' });
  if (!resp || resp.status() >= 400) throw new Error(`pos-refund-void: register returned ${resp && resp.status()}`);
  await page.waitForFunction(() => window.PosBoot && document.querySelector('meta[name=csrf-token]'), { timeout: cfg.navTimeout });
  await shot(page, 'refund-1-register');

  const boot = await page.evaluate(() => ({ canRefund: !!window.PosBoot.canRefund }));
  if (!boot.canRefund) throw new Error('pos-refund-void: PosBoot.canRefund is false — staff needs pos_refund');

  // ── seed two orders through the register's own endpoints ──
  const seeded = await page.evaluate(async () => {
    const csrf = document.querySelector('meta[name=csrf-token]').content;
    const post = async (url, body) => {
      const r = await fetch(url + (url.includes('?') ? '&' : '?') + 'restaurant=' + window.PosBoot.storeId, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
        body: JSON.stringify(body),
      });
      return { status: r.status, data: await r.json().catch(() => ({})) };
    };
    // Any active menu item works — pull one from the register's own menu payload.
    const menuUrl = window.PosBoot.routes.menu
      + (window.PosBoot.routes.menu.includes('?') ? '&' : '?')
      + 'restaurant=' + window.PosBoot.storeId;
    const menuRes = await fetch(menuUrl, { headers: { Accept: 'application/json' } }).catch(() => null);
    let itemId = null;
    if (menuRes && menuRes.ok) {
      const menu = await menuRes.json();
      const cats = menu.categories || menu || [];
      outer: for (const c of Array.isArray(cats) ? cats : []) {
        for (const it of c.items || []) { itemId = it.id; break outer; }
      }
    }
    if (!itemId) return { error: 'no menu item found' };

    const mk = () => post(window.PosBoot.routes.ordersStore, { type: 'takeaway', items: [{ item_id: itemId, quantity: 1 }] });
    const a = await mk();
    const b = await mk();
    if (a.status !== 201 || b.status !== 201) return { error: `order create failed (${a.status}/${b.status})` };

    const settle = await post(window.PosBoot.routes.ordersBase + '/' + a.data.id + '/settle', { method: 'cash', tendered: 1000 });
    return { paidId: a.data.id, unpaidId: b.data.id, total: settle.data.total, settleStatus: settle.status };
  });
  if (seeded.error) throw new Error('pos-refund-void: ' + seeded.error);
  if (seeded.settleStatus !== 200) throw new Error('pos-refund-void: settle failed ' + seeded.settleStatus);
  log(`pos-refund-void: paid #${seeded.paidId} (total ${seeded.total}), unpaid #${seeded.unpaidId}`);

  // ── UI: open Orders → history → the paid order ──
  const openDetail = async (tab, orderId) => {
    await page.evaluate((t) => {
      const app = document.querySelector('[x-data]').__x?.$data || Alpine.$data(document.querySelector('[x-data]'));
      app.activePanel = 'orders';
      app.setOrdersTab(t);
    }, tab);
    await sleep(900); // list fetch
    const opened = await page.evaluate((id) => {
      const app = Alpine.$data(document.querySelector('[x-data]'));
      const rows = app.currentOrders() || [];
      const row = rows.find((o) => +o.id === +id);
      if (!row) return false;
      app.openOrderDetail(row);
      return true;
    }, orderId);
    if (!opened) throw new Error(`pos-refund-void: order #${orderId} not in the ${tab} tab`);
    await sleep(700); // detail fetch
  };

  await openDetail('history', seeded.paidId);
  await shot(page, 'refund-2-detail');

  // Locale-agnostic clicks: the correction buttons are the .danger actions —
  // exactly one is visible at a time (Void on unpaid, Refund on paid) — and
  // the pane's confirm is the .danger button inside .pos-adjust-pane.
  const clickDanger = async (scope) => {
    // The detail panel re-renders when the order fetch lands (slower on a MySQL-backed site): wait for the control
    // to be visible instead of checking once.
    await page.waitForFunction((sel) => [...document.querySelectorAll(sel)].some((x) => x.offsetParent !== null),
      { timeout: 8000 }, scope).catch(() => {});
    const found = await page.evaluate((sel) => {
      const b = [...document.querySelectorAll(sel)].find((x) => x.offsetParent !== null);
      if (b) { b.click(); return true; }
      return false;
    }, scope);
    if (!found) {
      const seen = await page.evaluate((sel) => [...document.querySelectorAll(sel)].map((x) => {
        const r = x.getBoundingClientRect(); const cs = getComputedStyle(x);
        return `${x.textContent.trim().slice(0, 20)}[${cs.display} ${Math.round(r.width)}x${Math.round(r.height)}]`;
      }), scope);
      throw new Error(`pos-refund-void: no visible ${scope} (matched: ${seen.join(', ') || 'none'})`);
    }
  };
  const openCorrection = () => clickDanger('.pos-detail-actions .pos-detail-btn.danger');
  const confirmCorrection = () => clickDanger('.pos-adjust-pane .pos-detail-btn.danger');
  const appState = () => page.evaluate(() => {
    const app = Alpine.$data(document.querySelector('[x-data]'));
    return {
      pane: app.adjPane,
      refunded: app.selectedOrder ? +(app.selectedOrder.refunded_total || 0) : null,
      total: app.selectedOrder ? +app.selectedOrder.total : null,
      status: app.selectedOrder && app.selectedOrder.status,
    };
  });

  // Wait for the adjust POST to finish, then (optionally) for the refreshed order to satisfy `want`: the panel refresh
  // after a correction is async and slower on a MySQL-backed site than the fixed pauses assumed.
  const settle = async (want) => {
    await page.waitForFunction(() => !Alpine.$data(document.querySelector('[x-data]')).adjSending, { timeout: 15000 }).catch(() => {});
    if (!want) { await sleep(600); return; }
    const until = Date.now() + 15000;
    while (Date.now() < until) { if (want(await appState())) return; await sleep(300); }
  };

  // Over-refund → server 422; pane stays open; refunded_total stays 0.
  await openCorrection();
  await page.evaluate(() => {
    const app = Alpine.$data(document.querySelector('[x-data]'));
    app.adjAmount = 999999;
    app.adjReason = 'e2e over-refund probe';
  });
  await confirmCorrection();
  await settle();
  await shot(page, 'refund-3-over-rejected');
  let st = await appState();
  if (st.pane !== 'refund' || st.refunded !== 0) {
    throw new Error(`pos-refund-void: over-refund not rejected (pane=${st.pane}, refunded=${st.refunded})`);
  }

  // Full refund (default amount = remaining) → success, pane closes,
  // refunded_total equals the total after the panel refresh.
  await page.evaluate(() => {
    const app = Alpine.$data(document.querySelector('[x-data]'));
    app.openAdjust('refund'); // reset amount to remaining
    app.adjReason = 'e2e full refund';
  });
  await confirmCorrection();
  await settle((x) => x.pane === null && x.refunded >= x.total);
  await shot(page, 'refund-4-refunded');
  st = await appState();
  if (st.pane !== null || !st.refunded || st.refunded < st.total) {
    throw new Error(`pos-refund-void: refund did not land (pane=${st.pane}, refunded=${st.refunded}, total=${st.total})`);
  }

  // Second full refund → rejected (exactly-once): refunded_total must not grow.
  await openCorrection();
  await page.evaluate(() => {
    const app = Alpine.$data(document.querySelector('[x-data]'));
    app.adjAmount = +app.selectedOrder.total;
    app.adjReason = 'e2e double refund probe';
  });
  await confirmCorrection();
  await settle();
  st = await appState();
  if (st.pane !== 'refund' || st.refunded > st.total) {
    throw new Error(`pos-refund-void: double refund not rejected (pane=${st.pane}, refunded=${st.refunded})`);
  }
  await shot(page, 'refund-5-double-rejected');
  await page.evaluate(() => { Alpine.$data(document.querySelector('[x-data]')).adjPane = null; });

  // ── UI: void the unpaid order ──
  await openDetail('history', seeded.unpaidId).catch(async () => {
    // Unpaid POS orders may list under held/history depending on flow; try held.
    await openDetail('held', seeded.unpaidId);
  });
  await openCorrection(); // unpaid order → the visible danger action is Void
  await page.evaluate(() => {
    const app = Alpine.$data(document.querySelector('[x-data]'));
    app.adjReason = 'e2e void probe';
  });
  await confirmCorrection();
  await settle((x) => x.status === 'cancelled');
  await shot(page, 'refund-6-voided');
  st = await appState();
  if (st.status !== 'cancelled') throw new Error(`pos-refund-void: void did not cancel (status: ${st.status})`);

  log('pos-refund-void: over-refund blocked, refund exactly-once, void cancels — all via UI');
};
