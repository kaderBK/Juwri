// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * P4-11 — AI agents (stub provider): an agent's actions stay inside its own store (E2E_RUN_P4=1; the stub AI is armed on the runner).
 *
 * The owner's agent (MCP over an API token of owner A) lists only A's stores, and every tool call that names store B's id (store, order,
 * menu item, category) is refused and B's data is unchanged; the storefront agent bound to store A (storefront token) cannot read or order
 * store B's item (nothing is ordered anywhere); the on-menu chat of store A answers and logs its conversation in A only, and a store whose
 * chat is switched off answers 403. Spend limits (daily cap, plan allowance, review hold: refused with no provider call) and the prepaid-credit
 * / own-key rules are pinned by AgentSpendCapTest and the Enterprise CreditUsagePathsTest: the E2E stub replaces the AI service, so it
 * never reaches the metering the caps live in.
 */

const P = require('../lib/p4');
const { newPage } = require('../lib/harness');

const { L } = P;

module.exports = async function p4Agents({ browser, log, shot }) {
  const check = L.checker('p4-agents');
  const base = L.baseUrl();
  const tag = Date.now().toString(36);
  const a = P.ownerRestaurant(P.OWNER.email);
  const b = P.ownerRestaurant(P.OTHER.email);
  const prevA = P.setOptions(a, { require_phone_verification: '0', agent_chat_enabled: '1' });
  const prevB = P.setOptions(b, { require_phone_verification: '0', agent_chat_enabled: '0' });
  const itemsA = P.makeMenu(a, [['e2eB Agent Pizza A', 9, {}]]);
  const itemsB = P.makeMenu(b, [['e2eB Agent Pizza B', 9, {}]]);
  const catB = P.tinker(`$c = \\App\\Models\\MenuCategory::create(['store_id' => ${b.id}, 'name' => 'e2eB Agent Cat B ${tag}', 'active' => '1']); echo 'ID='.$c->id;`, 'ID');
  const secrets = P.tinker(`
    $o = \\Juwri\\Core\\Models\\User::where('email', ${P.q(P.OWNER.email)})->firstOrFail();
    $raw = \\Illuminate\\Support\\Str::random(40);
    \\Juwri\\Modules\\Integrations\\Models\\ApiToken::create(['user_id' => $o->id, 'name' => 'e2eB agent ${tag}', 'token' => hash('sha256', $raw), 'abilities' => ['*']]);
    $sf = \\Illuminate\\Support\\Str::random(48);
    \\App\\Models\\StorefrontToken::create(['store_id' => ${a.id}, 'token' => hash('sha256', $sf), 'scopes' => ['agent'], 'active' => true]);
    echo 'SECRETS='.json_encode(['api' => $raw, 'sf' => $sf, 'mcp' => route('mcp'), 'sfMcp' => route('mcp.storefront')]);`, 'SECRETS');
  const { api, sf, mcp, sfMcp } = JSON.parse(secrets);
  const at = (full) => `${base}${new URL(full).pathname}`; // the route() URL carries APP_URL's host: use the journey's base
  const rpc = async (url, headers, method, params) => {
    const r = await fetch(url, { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json', Accept: 'application/json' }, headers), body: JSON.stringify({ jsonrpc: '2.0', id: 1, method, params }) });
    let json = null; try { json = await r.json(); } catch (_e) { /* not json */ }
    return { status: r.status, json };
  };
  const call = (name, args) => rpc(at(mcp), { Authorization: `Bearer ${api}` }, 'tools/call', { name, arguments: args });
  const refused = (res) => !!res.json && (!!res.json.error || (res.json.result && res.json.result.isError === true));
  const row = (sql, params) => L.dbq(sql, params);
  const bState = () => JSON.stringify([
    row('select id, name, active from items where store_id = ? order by id', [b.id]),
    row('select id, name from categories where store_id = ? order by id', [b.id]),
    row('select id, status from orders where store_id = ? order by id', [b.id]),
  ]);

  const guest = await L.freshPage(browser, newPage, { width: 420, height: 800 });
  const placed = await P.guestOrder(guest.page, base, b, [{ id: itemsB['e2eB Agent Pizza B'], quantity: 1 }], { name: 'Agent B Guest' });
  check.ok(placed.json && placed.json.success, `store B has an order to protect (${placed.status})`);
  const orderB = placed.json && placed.json.order_id;
  const ordersA = () => Number(row('select count(*) from orders where store_id = ?', [a.id])[0][0]);
  try {
    // ── owner agent (MCP, API token of owner A) ──────────────────────────────────────────────────────────────────────────
    const listed = await call('list_restaurants', {});
    const text = JSON.stringify(listed.json);
    check.ok(text.includes(a.slug) && !text.includes(b.slug), 'the owner agent lists its own stores and not store B');
    const before = bState();
    const attempts = [
      ['get_menu', { store_id: +b.id }],
      ['list_orders', { store_id: +b.id }],
      ['get_order', { store_id: +b.id, order_id: +orderB }],
      ['get_order', { store_id: +a.id, order_id: +orderB }],
      ['update_order_status', { store_id: +b.id, order_id: +orderB, status: 'cancelled' }],
      ['update_order_status', { store_id: +a.id, order_id: +orderB, status: 'cancelled' }],
      ['update_menu_item', { store_id: +b.id, item_id: +itemsB['e2eB Agent Pizza B'], name: 'Hacked' }],
      ['update_menu_item', { store_id: +a.id, item_id: +itemsB['e2eB Agent Pizza B'], name: 'Hacked' }],
      ['set_item_availability', { store_id: +a.id, item_id: +itemsB['e2eB Agent Pizza B'], available: false }],
      ['delete_menu_item', { store_id: +a.id, item_id: +itemsB['e2eB Agent Pizza B'] }],
      ['update_category', { store_id: +a.id, category_id: +catB, name: 'Hacked' }],
      ['delete_category', { store_id: +a.id, category_id: +catB }],
      ['add_menu_item', { store_id: +a.id, category_id: +catB, name: 'Planted', price: 1 }],
      ['add_menu_item', { store_id: +b.id, name: 'Planted', price: 1 }],
    ];
    for (const [tool, args] of attempts) {
      const res = await call(tool, args);
      check.ok(refused(res), `${tool} ${JSON.stringify(args)} is refused (${JSON.stringify(res.json).slice(0, 110)})`);
    }
    check.eq(bState(), before, 'store B\'s menu, categories and order are unchanged after all of that');
    check.eq(Number(row('select count(*) from items where name = ?', ['Planted'])[0][0]), 0, 'and nothing was planted in either store');

    // ── storefront agent bound to store A (storefront token) ─────────────────────────────────────────────────────────────
    const sfCall = (name, args) => rpc(at(sfMcp), { 'X-Storefront-Token': sf }, 'tools/call', { name, arguments: args });
    const a0 = ordersA();
    const ownItem = await sfCall('get_item', { item_id: +itemsA['e2eB Agent Pizza A'] });
    check.ok(!refused(ownItem) && JSON.stringify(ownItem.json).includes('Agent Pizza A'), 'the storefront agent reads store A\'s own item');
    const foreignItem = await sfCall('get_item', { item_id: +itemsB['e2eB Agent Pizza B'] });
    check.ok(!JSON.stringify(foreignItem.json).includes('Agent Pizza B'), 'but cannot read store B\'s item');
    const foreignOrder = await sfCall('create_order', { items: [{ id: +itemsB['e2eB Agent Pizza B'], quantity: 1 }], type: 'takeaway', customer_name: 'Mallory' });
    check.ok(!/"order_id"\s*:\s*\d+|\\"order_id\\"\s*:\s*\d+/.test(JSON.stringify(foreignOrder.json)), `an order naming store B's item is not placed (${JSON.stringify(foreignOrder.json).slice(0, 120)})`);
    check.eq(ordersA(), a0, 'no order appeared in store A');
    check.eq(bState(), before, 'nor in store B');
    const foreignStatus = await sfCall('order_status', { track_token: 'e2e-not-a-token' });
    check.ok(!/"status"\s*:\s*"(new|preparing|ready)"/.test(JSON.stringify(foreignStatus.json)), 'an unknown tracking token tells nothing');

    // ── on-menu chat (stub AI) ───────────────────────────────────────────────────────────────────────────────────────────
    await guest.page.goto(`${base}/${a.slug}`, { waitUntil: 'domcontentloaded' });
    const chat = (store) => guest.page.evaluate(async (id, key) => {
      const t = (document.querySelector('meta[name=csrf-token]') || {}).content || '';
      const r = await fetch(`/agent-chat/${id}/message`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': t, 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify({ message: 'what pizza do you have', session_key: key }) });
      let json = null; try { json = await r.json(); } catch (_e) { /* not json */ }
      return { status: r.status, json };
    }, store.id, `e2eb-${tag}`);
    const chatA = await chat(a);
    check.eq(chatA.status, 200, 'store A\'s chat answers');
    check.ok(chatA.json && typeof chatA.json.reply === 'string' && chatA.json.reply.length > 0, 'with a reply');
    const conv = row('select store_id from agent_conversations where session_key = ?', [`e2eb-${tag}`]);
    check.ok(conv.length === 1 && String(conv[0][0]) === String(a.id), 'the conversation is logged in store A only');
    const chatB = await chat(b);
    check.eq(chatB.status, 403, 'a store whose chat is switched off answers 403');
    check.eq(Number(row('select count(*) from agent_conversations where store_id = ? and session_key = ?', [b.id, `e2eb-${tag}`])[0][0]), 0, 'and logs nothing');
  } finally {
    await guest.close();
    P.tinker(`
      \\Juwri\\Modules\\Integrations\\Models\\ApiToken::where('name', 'e2eB agent ${tag}')->delete();
      \\App\\Models\\StorefrontToken::where('store_id', ${a.id})->where('scopes', 'like', '%agent%')->delete();
      \\Juwri\\Modules\\Agents\\Models\\AgentConversation::where('session_key', 'e2eb-${tag}')->delete();
      \\App\\Models\\MenuCategory::where('id', ${catB})->delete();
      \\Juwri\\Modules\\Menu\\Models\\Menu::whereIn('id', ${P.php([...Object.values(itemsA), ...Object.values(itemsB)])})->get()->each(fn ($m) => $m->delete());
      echo 'OK=1';`, 'OK');
    P.restoreOptions(a, prevA);
    P.restoreOptions(b, prevB);
  }
  check.done(log);
};
