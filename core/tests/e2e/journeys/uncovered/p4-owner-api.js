// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * p4-owner-api — P4-12 Owner API v1 in a real browser + real HTTP:
 *
 *  create    owner A makes tokens on Developer → API (password re-check when asked); the raw token is shown once
 *  main      with a read+write token: /me, the store list (only A's stores), a store's menu, add an item, move an
 *            order's status — each answers like the docs say
 *  IDOR      the same token against owner B's store, B's item / category / order through A's own store, and A's other
 *            store's order through store A1 → 403/404 every time, and B's rows are unchanged
 *  scopes    a menu:read token reads the menu, is refused orders and menu writes (403)
 *  limit     the named limiter (throttle:owner-api, API_OWNER_RATE on this instance): the token gets exactly its
 *            budget, then 429 with Retry-After; another token of the same account still answers
 *  revoke    A revokes the read+write token in the dashboard → the very next API and MCP call is 401
 *  suspended the owner suspended (status 0) → the remaining token is refused; reinstated → it answers again
 *
 * The instance: `php -S` on E2E_P4_PORT_API (default 8623) with API_OWNER_RATE=E2E_API_RATE (default 25).
 * Run (runner): runner_e2e.sh <slot> [--community] p4-owner-api
 */

const B = require('../../lib/lane-b');
const C = require('../../lib/lane-c');

const PASS = 'e2eC-Api-1!';
const A = { email: 'e2eC-api-a@demo.test', username: 'e2ecapia' };
const BB = { email: 'e2eC-api-b@demo.test', username: 'e2ecapib' };

const dbEnv = () => (process.env.E2E_DB ? { DB_DATABASE: process.env.E2E_DB } : {});

function tinker(php) {
  const out = B.artisan(['tinker', '--execute', php], dbEnv());
  const m = out.match(/OUT=(\{.*\})/);
  if (!m) throw new Error(`p4-owner-api tinker failed: ${out.slice(-900)}`);
  return JSON.parse(m[1]);
}

function fixtures() {
  return tinker(`
    $mk = function ($em, $un) {
      $u = \\Juwri\\Core\\Models\\User::where('email', $em)->first() ?? new \\Juwri\\Core\\Models\\User(['email' => $em]);
      $u->forceFill(['email' => $em, 'username' => $un, 'name' => 'e2eC Api '.$un, 'password' => \\Illuminate\\Support\\Facades\\Hash::make('${PASS}'),
        'status' => 1, 'user_type' => 'user', 'email_verified_at' => now(), 'two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
      \\Juwri\\Modules\\Integrations\\Models\\ApiToken::where('user_id', $u->id)->delete();
      return $u->fresh();
    };
    $store = function ($u, $tag) {
      $s = \\Juwri\\Core\\Editions\\Limits\\StoreLimitGuard::bypass(fn () => \\Juwri\\Core\\Models\\Store::create(['user_id' => $u->id, 'store_type' => 'restaurant',
        'slug' => 'e2ec-api-'.\\Illuminate\\Support\\Str::lower(\\Illuminate\\Support\\Str::random(6)), 'color' => '#111', 'title' => 'Api '.$tag, 'sub_title' => 't',
        'description' => 'd', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '0555000111']));
      $c = \\App\\Models\\MenuCategory::create(['store_id' => $s->id, 'name' => 'Cat '.$tag, 'active' => '1']);
      $m = \\Juwri\\Modules\\Menu\\Models\\Menu::create(['store_id' => $s->id, 'category_id' => $c->id, 'name' => 'e2eC item '.$tag, 'price' => 5, 'active' => 1]);
      $o = \\App\\Models\\Order::create(['store_id' => $s->id, 'type' => 'takeaway', 'status' => 'pending', 'track_token' => \\Illuminate\\Support\\Str::random(32),
        'customer_name' => 'Buyer '.$tag, 'phone_number' => '0500000009', 'address' => '9 Rd', 'seen' => 0]);
      \\App\\Models\\OrderItem::create(['order_id' => $o->id, 'item_id' => $m->id, 'quantity' => 1]);
      return ['id' => $s->id, 'category' => $c->id, 'item' => $m->id, 'order' => $o->id, 'title' => $s->title];
    };
    $a = $mk('${A.email}', '${A.username}'); $b = $mk('${BB.email}', '${BB.username}');
    $tag = \\Illuminate\\Support\\Str::lower(\\Illuminate\\Support\\Str::random(4));
    $out = ['a' => $a->id, 'b' => $b->id, 'a1' => $store($a, 'A1-'.$tag), 'a2' => $store($a, 'A2-'.$tag), 'b1' => $store($b, 'B1-'.$tag),
      'page' => route('api.index', [], false)];
    \\Illuminate\\Support\\Facades\\Artisan::call('cache:clear');
    echo 'OUT='.json_encode($out);
  `);
}

async function api(base, token, method, path, body) {
  const r = await fetch(base + path, {
    method,
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json' } : {}) },
    body: body ? JSON.stringify(body) : undefined,
  });
  const text = await r.text();
  let json = null; try { json = JSON.parse(text); } catch (_e) { json = null; }
  return { status: r.status, json, text, retryAfter: r.headers.get('retry-after') };
}

/**
 * Make a token on the Developer → API page; answers the password re-check when it is asked. The token is shown once
 * from the server-side one-time store (OneTimeSecrets) — it used to ride in a session flash that a concurrent
 * dashboard poll could drop.
 */
async function createToken(page, base, path, name, abilities) {
  const submit = async () => {
    await page.goto(base + path, { waitUntil: 'domcontentloaded' });
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
      page.evaluate((n, abs) => {
        const sel = document.querySelector('#api-token-abilities');
        const form = sel.closest('form');
        form.querySelector('[name="name"]').value = n;
        [...sel.options].forEach((o) => { o.selected = abs.includes(o.value); });
        form.submit();
      }, name, abilities),
    ]);
    if (/password\/confirm/.test(page.url())) {
      await page.type('input[name="password"]', PASS);
      await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.evaluate(() => document.querySelector('input[name="password"]').form.submit())]);
      return submit();
    }
    return page.evaluate(() => document.querySelector('.ui-api__secret')?.textContent.trim() || null);
  };
  const raw = await submit();
  if (!raw || raw.length !== 40) {
    const why = await page.evaluate(() => [...document.querySelectorAll('.ui-banner, .ui-alert, [role="alert"], .ui-field__error, .invalid-feedback, .toast')]
      .map((e) => e.textContent.trim().replace(/\s+/g, ' ')).filter(Boolean).join(' | ').slice(0, 400));
    throw new Error(`token "${name}" not shown after creating it (on ${page.url()}; page says: ${why || 'nothing'})`);
  }
  return raw;
}

module.exports = async function p4OwnerApi({ browser, log, shot }) {
  const R = C.recorder('p4-owner-api', log);
  const root = B.appRoot();
  const port = parseInt(process.env.E2E_P4_PORT_API || '8623', 10);
  const rate = parseInt(process.env.E2E_API_RATE || '25', 10);
  const base = `http://127.0.0.1:${port}`;
  const server = await C.startServer(root, port, { ...dbEnv(), APP_URL: base, API_OWNER_RATE: String(rate), SESSION_DOMAIN: '' });
  log(`  p4-owner-api: ${base} (${B.eePresentOnDisk() ? 'Enterprise' : 'Community'} build, owner-api budget ${rate}/min)`);
  const a = await C.actorPage(browser);
  try {
    const f = fixtures();
    await C.login(a.page, base, A.email, PASS);

    // ── create ──────────────────────────────────────────────────────────────────────────────────────────────────
    const full = await createToken(a.page, base, f.page, 'e2eC full', ['read', 'write']);
    await shot(a.page, 'p4-owner-api-1-token');
    const stored = C.one('select token, abilities from api_tokens where user_id = ? and name = ?', [f.a, 'e2eC full']);
    if (!stored || stored.token === full || stored.token.length !== 64) R.fail('the token is not stored hashed');
    R.ok('create: Developer → API shows a 40-character token once; the database keeps only its hash');
    let used = 0;
    const call = async (...args) => { used++; return api(base, full, ...args); };

    // ── main endpoints ──────────────────────────────────────────────────────────────────────────────────────────
    const me = await call('GET', '/api/v1/me');
    if (me.status !== 200) R.fail(`/me → ${me.status}`);
    const list = await call('GET', '/api/v1/stores');
        if (list.status !== 200 || !list.text.includes(f.a1.title) || !list.text.includes(f.a2.title) || list.text.includes(f.b1.title)) R.fail(`store list → ${list.status} ${list.text.slice(0, 300)}`);
    const menu = await call('GET', `/api/v1/stores/${f.a1.id}/menu`);
    if (menu.status !== 200 || !menu.text.includes('e2eC item A1')) R.fail(`menu → ${menu.status}`);
    const add = await call('POST', `/api/v1/stores/${f.a1.id}/menu/items`, { name: 'e2eC api tea', price: 7, category_id: f.a1.category });
    if (![200, 201].includes(add.status) || !C.one('select id from items where store_id = ? and name = ?', [f.a1.id, 'e2eC api tea'])) R.fail(`add item → ${add.status} ${add.text.slice(0, 200)}`);
    const st = await call('PUT', `/api/v1/stores/${f.a1.id}/orders/${f.a1.order}/status`, { status: 'preparing' });
    if (st.status !== 200 || C.one('select status from orders where id = ?', [f.a1.order]).status !== 'preparing') R.fail(`order status → ${st.status} ${st.text.slice(0, 200)}`);
    R.ok('main: /me, store list (A\'s two stores only), menu, add item, order status — all answer and write A\'s own rows');

    // ── IDOR ────────────────────────────────────────────────────────────────────────────────────────────────────
    const bBefore = JSON.stringify(C.sql('select id, name, price_minor, category_id, active from items where store_id = ? order by id', [f.b1.id]))
      + JSON.stringify(C.sql('select id, status from orders where store_id = ? order by id', [f.b1.id]))
      + JSON.stringify(C.sql('select id, name from categories where store_id = ? order by id', [f.b1.id]));
    const probes = [
      ['GET', `/api/v1/stores/${f.b1.id}`],
      ['GET', `/api/v1/stores/${f.b1.id}/menu`],
      ['GET', `/api/v1/stores/${f.b1.id}/orders`],
      ['GET', `/api/v1/stores/${f.b1.id}/analytics`],
      ['POST', `/api/v1/stores/${f.b1.id}/menu/items`, { name: 'pwned', price: 1 }],
      ['GET', `/api/v1/stores/${f.a1.id}/orders/${f.b1.order}`],
      ['PUT', `/api/v1/stores/${f.a1.id}/orders/${f.b1.order}/status`, { status: 'cancelled' }],
      ['PUT', `/api/v1/stores/${f.a1.id}/menu/items/${f.b1.item}`, { name: 'pwned', price: 1 }],
      ['PATCH', `/api/v1/stores/${f.a1.id}/menu/items/${f.b1.item}/sold-out`],
      ['DELETE', `/api/v1/stores/${f.a1.id}/menu/categories/${f.b1.category}`],
      ['GET', `/api/v1/stores/${f.a1.id}/orders/${f.a2.order}`],
      ['PUT', `/api/v1/stores/${f.a1.id}/orders/${f.a2.order}/status`, { status: 'cancelled' }],
    ];
    for (const [m, p, body] of probes) {
      const r = await call(m, p, body);
      if (![403, 404].includes(r.status)) R.fail(`${m} ${p} → ${r.status} ${r.text.slice(0, 200)}`);
      if (/Buyer B1|e2eC item B1/.test(r.text)) R.fail(`${m} ${p} leaked B's data`);
    }
    const bAfter = JSON.stringify(C.sql('select id, name, price_minor, category_id, active from items where store_id = ? order by id', [f.b1.id]))
      + JSON.stringify(C.sql('select id, status from orders where store_id = ? order by id', [f.b1.id]))
      + JSON.stringify(C.sql('select id, name from categories where store_id = ? order by id', [f.b1.id]));
    if (bAfter !== bBefore) R.fail("B's rows changed through A's token");
    if (C.one('select status from orders where id = ?', [f.a2.order]).status !== 'pending') R.fail("A2's order was changed through store A1");
    R.ok(`IDOR: ${probes.length} cross-account / cross-store calls → 403/404, nothing of B (or of A's other store) read or changed`);

    // ── scopes ──────────────────────────────────────────────────────────────────────────────────────────────────
    const ro = await createToken(a.page, base, f.page, 'e2eC menu-read', ['menu:read']);
    const roMenu = await api(base, ro, 'GET', `/api/v1/stores/${f.a1.id}/menu`);
    const roOrders = await api(base, ro, 'GET', `/api/v1/stores/${f.a1.id}/orders`);
    const roWrite = await api(base, ro, 'POST', `/api/v1/stores/${f.a1.id}/menu/items`, { name: 'nope', price: 1 });
    if (roMenu.status !== 200 || roOrders.status !== 403 || roWrite.status !== 403) R.fail(`menu:read token: menu ${roMenu.status}, orders ${roOrders.status}, write ${roWrite.status}`);
    R.ok('scopes: a menu:read token reads the menu (200) and is refused orders and menu writes (403)');

    // ── named rate limit ────────────────────────────────────────────────────────────────────────────────────────
    const burst = await createToken(a.page, base, f.page, 'e2eC burst', ['read']);
    let first429 = 0;
    let retry = null;
    for (let i = 1; i <= rate + 3; i++) {
      const r = await api(base, burst, 'GET', '/api/v1/me');
      if (r.status === 429) { first429 = i; retry = r.retryAfter; break; }
      if (r.status !== 200) R.fail(`/me #${i} → ${r.status}`);
    }
    if (first429 !== rate + 1) R.fail(`429 came at call ${first429}, the budget is ${rate}`);
    if (!retry || Number(retry) < 1) R.fail(`429 without Retry-After (${retry})`);
    const other = await call('GET', `/api/v1/stores/${f.a1.id}/menu`);
    if (other.status !== 200) R.fail(`another token of the account → ${other.status} while the first is throttled`);
    R.ok(`limit: exactly ${rate} calls a minute per token, then 429 (Retry-After ${retry}s); the account's other token still answers`);

    // ── revoke ──────────────────────────────────────────────────────────────────────────────────────────────────
    if (used >= rate) R.fail(`the journey used ${used} calls of the full token's budget ${rate}; raise E2E_API_RATE`);
    const tokenId = C.one('select id from api_tokens where user_id = ? and name = ?', [f.a, 'e2eC full']).id;
    await a.page.goto(base + f.page, { waitUntil: 'domcontentloaded' });
    const found = await a.page.evaluate((id) => {
      const form = [...document.querySelectorAll('form')].find((x) => new RegExp(`/api/tokens/${id}$`).test(x.action));
      if (!form) return false;
      form.removeAttribute('data-confirm');
      form.submit();
      return true;
    }, tokenId);
    if (!found) R.fail('no revoke button for the token on the API page');
    await a.page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {});
    if (C.one('select id from api_tokens where id = ?', [tokenId])) R.fail('the token row is still there after revoking');
    const after = await api(base, full, 'GET', '/api/v1/stores');
    const mcp = await api(base, full, 'POST', '/mcp', { jsonrpc: '2.0', id: 1, method: 'tools/list' });
    const kept = await api(base, ro, 'GET', `/api/v1/stores/${f.a1.id}/menu`);
    if (after.status !== 401 || mcp.status !== 401) R.fail(`revoked token: API ${after.status}, MCP ${mcp.status}`);
    if (kept.status !== 200) R.fail(`the account's other token → ${kept.status} after revoking the first`);
    R.ok('revoke: dashboard revoke → the very next API and MCP calls are 401; the other token still works');

    // ── suspended owner ─────────────────────────────────────────────────────────────────────────────────────────
    tinker(`\\Juwri\\Core\\Models\\User::whereKey(${Number(f.a)})->update(['status' => 0]); echo 'OUT={}';`);
    const susp = await api(base, ro, 'GET', `/api/v1/stores/${f.a1.id}/menu`);
    tinker(`\\Juwri\\Core\\Models\\User::whereKey(${Number(f.a)})->update(['status' => 1]); echo 'OUT={}';`);
    const back = await api(base, ro, 'GET', `/api/v1/stores/${f.a1.id}/menu`);
    if (susp.status !== 403 || !susp.json || susp.json.code !== 'account_blocked' || back.status !== 200) R.fail(`suspended owner's token → ${susp.status} ${susp.text.slice(0, 120)}, reinstated → ${back.status}`);
    R.ok('suspended: while the owner is suspended the token is refused (403 account_blocked); reinstated, it answers again');

    R.summary();
    return undefined;
  } finally {
    await a.close();
    server.stop();
  }
};
