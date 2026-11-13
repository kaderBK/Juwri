// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * P4-10 — Marketing: consent-only sends and a working unsubscribe (E2E_RUN_P4=1; the SMS leg needs SMS_PROVIDER=log, i.e. E2E_RUN_PLATFORM_V2=1
 * on the runner).
 *
 * Customers come from real checkouts with different consent: e-mail consent only, none, e-mail + SMS, e-mail but SMS declined, and one in
 * another owner's store → the owner sends an e-mail campaign from the screen: exactly the customers who consented to marketing get it (the
 * no-consent customer and the other store's customer do not) → an SMS campaign goes only to the customer who also consented to SMS → the
 * unsubscribe link in the e-mail works (signature required, a tampered link is refused, only that customer is affected) and the next
 * campaign skips them → the public unsubscribe form withdraws e-mail + SMS for that customer in that store only → ticking the consent box
 * again at a later checkout opts the customer back in → the other owner cannot read, or aim a campaign at, this store's campaigns.
 */

const P = require('../lib/p4');
const { newPage } = require('../lib/harness');

const { L } = P;

module.exports = async function p4Marketing({ browser, log, shot }) {
  const check = L.checker('p4-marketing');
  const base = L.baseUrl();
  const tag = Date.now().toString(36);
  const a = P.ownerRestaurant(P.OWNER.email);
  const b = P.ownerRestaurant(P.OTHER.email);
  const prevA = P.setOptions(a, { require_phone_verification: '0', open_close_store: '1' });
  const prevB = P.setOptions(b, { require_phone_verification: '0' });
  const itemsA = P.makeMenu(a, [['e2eB Promo Latte', 6, {}]]);
  const itemsB = P.makeMenu(b, [['e2eB Promo Latte B', 6, {}]]);
  const mail = (n) => `e2e-mk-${n}-${tag}@example.test`;
  const pbase = String(Date.now()).slice(-6);
  const phone = (n) => `+1650${pbase}${n}`;

  const owner = await L.freshPage(browser, newPage, { width: 1280, height: 1000 });
  const other = await L.freshPage(browser, newPage, { width: 1280, height: 900 });
  const guest = await L.freshPage(browser, newPage, { width: 420, height: 800 });
  const anon = await L.freshPage(browser, newPage, { width: 800, height: 700 });
  const campaignIds = [];
  let step = 'start';
  const customerOf = (store, email) => L.dbq('select id, marketing_consent, sms_consent from customers where store_id = ? and email = ?', [store.id, email])[0];
  const consent = (store, email) => { const r = customerOf(store, email); return r ? `${Number(r[1])}/${Number(r[2])}` : null; };
  const checkout = async (store, items, n, flags) => {
    const r = await P.guestOrder(guest.page, base, store, [{ id: items, quantity: 1 }], Object.assign({
      name: `Promo ${n}`, 'phone-number': phone(n), capture_email: mail(n === 5 ? 3 : n),
    }, flags));
    check.ok(r.json && r.json.success, `checkout for customer ${n} succeeds (${r.status} ${JSON.stringify(r.json).slice(0, 100)})`);
  };
  const recipientsOf = (id) => L.dbq('select c.email, r.status from campaign_recipients r join customers c on c.id = r.customer_id where r.campaign_id = ? order by c.email', [id]).map((x) => `${x[0]}`);
  const newestCampaign = () => L.dbq('select id, status, sent_count, failed_count from campaigns where store_id = ? order by id desc limit 1', [a.id])[0];
  const send = async (channel, subject, body) => {
    await owner.page.goto(`${base}/campaigns/create`, { waitUntil: 'domcontentloaded' });
    await owner.page.select('#campnew-store', String(a.id));
    await owner.page.select('#campnew-channel', channel);
    await owner.page.select('#campnew-segment', 'all');
    if (channel === 'email') await owner.page.type('#campnew-subject', subject);
    await owner.page.type('#campnew-body', body);
    await Promise.all([owner.page.waitForNavigation({ waitUntil: 'domcontentloaded' }), owner.page.click('form.ui-campnew__form button[type=submit]')]);
    const c = newestCampaign();
    if (c) campaignIds.push(c[0]);
    return c;
  };
  try {
    // ── customers with different consent, from real checkouts ───────────────────────────────────────────────────────────────
    step = 'customers with different consent, from r';
    await checkout(a, itemsA['e2eB Promo Latte'], 1, { marketing_consent: '1' });
    await checkout(a, itemsA['e2eB Promo Latte'], 2, {});
    await checkout(a, itemsA['e2eB Promo Latte'], 3, { marketing_consent: '1', sms_consent: '1' });
    await checkout(a, itemsA['e2eB Promo Latte'], 4, { marketing_consent: '1' });
    await checkout(b, itemsB['e2eB Promo Latte B'], 5, { marketing_consent: '1', sms_consent: '1' }); // the other store, same e-mail as customer 3
    check.eq(consent(a, mail(1)), '1/0', 'customer 1: marketing yes, SMS no');
    check.eq(consent(a, mail(2)), '0/0', 'customer 2 ticked nothing: no consent is stored');
    check.eq(consent(a, mail(3)), '1/1', 'customer 3: marketing + SMS');
    check.eq(consent(a, mail(4)), '1/0', 'customer 4: marketing, SMS declined');
    check.eq(consent(b, mail(3)), '1/1', 'the other store has its own consenting customer with the same e-mail as customer 3');

    // ── e-mail campaign: consented customers only ───────────────────────────────────────────────────────────────────────────
    step = 'e-mail campaign: consented customers onl';
    await L.login(owner.page, base, P.OWNER);
    const cp1 = (() => { try { return require('fs').statSync(process.env.E2E_LOG).size; } catch (_e) { return 0; } })();
    const email1 = await send('email', `Promo ${tag}`, 'Hello {name}, 10% off this weekend.');
    check.ok(!!email1 && email1[0], 'the e-mail campaign is created from the screen');
    check.eq(recipientsOf(email1[0]).join(','), [mail(1), mail(3), mail(4)].sort().join(','), 'it targets exactly the three customers who consented to marketing');
    check.eq(`${email1[2]}/${email1[3]}`, '3/0', 'three sent, none failed');
    await owner.page.goto(`${base}/campaigns/${email1[0]}`, { waitUntil: 'domcontentloaded' });
    const shown = await owner.page.evaluate(() => document.body.innerText);
    check.ok(shown.includes(mail(1)) && shown.includes(mail(3)) && shown.includes(mail(4)) && !shown.includes(mail(2)), 'the campaign page lists those recipients and not the one without consent');
    await shot(owner.page, 'p4-marketing-campaign');
    const customer1 = customerOf(a, mail(1))[0];
    // the mail log holds the HTML body as written (8-bit): read the link from it directly (lib/platform.readMailLink would un-escape "=17" in the signed URL)
    let unsub = null;
    {
      const raw = require('fs').readFileSync(process.env.E2E_LOG, 'utf8').slice(cp1);
      const found = [...raw.matchAll(new RegExp(`https?://[^"\\s]*/campaigns/unsubscribe/${customer1}\\?(?:expires=\\d+&amp;)?signature=[0-9a-f]+`, 'g'))].map((m) => m[0]);
      unsub = found.length ? found[found.length - 1].replace(/&amp;/g, '&') : null;
      check.ok(!!unsub, 'customer 1\'s e-mail carries a signed unsubscribe link');
    }

    // ── SMS campaign: marketing AND SMS consent ───────────────────────────────────────────────────────────────────────────
    step = 'SMS campaign: marketing AND SMS consent';
    const sms1 = await send('sms', '', 'Hi {name}, a text offer.');
    check.eq(recipientsOf(sms1[0]).join(','), mail(3), 'the SMS campaign targets only the customer who also consented to SMS');
    check.eq(`${sms1[2]}/${sms1[3]}`, '1/0', 'and it is delivered');

    // ── unsubscribe link ──────────────────────────────────────────────────────────────────────────────────────────────────
    step = 'unsubscribe link';
    if (unsub) {
      const bad = unsub.replace(`/unsubscribe/${customer1}?`, `/unsubscribe/${customerOf(a, mail(3))[0]}?`);
      const badRes = await anon.page.goto(bad, { waitUntil: 'domcontentloaded' });
      check.eq(badRes.status(), 401, 'a link re-pointed at another customer is refused (signature)');
      check.eq(consent(a, mail(3)), '1/1', 'and that customer is unaffected');
      const ok = await anon.page.goto(unsub, { waitUntil: 'domcontentloaded' });
      check.eq(ok.status(), 200, 'the signed unsubscribe link opens without signing in');
      check.eq(consent(a, mail(1)), '1/0', 'P6-222: opening the link alone changes nothing (confirm page)');
      await Promise.all([anon.page.waitForNavigation({ waitUntil: 'domcontentloaded' }), anon.page.click('form button[type=submit]')]);
      check.ok((await anon.page.evaluate(() => document.body.innerText)).includes(a.title), 'and the confirmation page names the store');
      check.eq(consent(a, mail(1)), '0/0', 'customer 1 no longer has marketing consent');
      check.eq(consent(a, mail(3)), '1/1', 'customers 3 and 4 are untouched');
      check.eq(consent(b, mail(3)), '1/1', 'so is the other store');
    }
    const email2 = await send('email', `Second ${tag}`, 'Another one for {name}.');
    check.eq(recipientsOf(email2[0]).join(','), [mail(3), mail(4)].sort().join(','), 'the next campaign skips the unsubscribed customer');

    // ── the public unsubscribe form: both channels, this store only ─────────────────────────────────────────────────────────
    step = 'the public unsubscribe form: both channe';
    await anon.page.goto(`${base}/${a.slug}/unsubscribe`, { waitUntil: 'domcontentloaded' });
    const st = await P.formPost(anon.page, `/${a.slug}/unsubscribe`, `email=${encodeURIComponent(mail(3))}`).catch(() => 0);
    check.ok(st === 200 || st === 419, `the public unsubscribe form answers (${st})`);
    if (st === 419) { // a static page without a token: take one from the storefront and post again
      await anon.page.goto(`${base}/${a.slug}`, { waitUntil: 'domcontentloaded' });
      await P.formPost(anon.page, `/${a.slug}/unsubscribe`, `email=${encodeURIComponent(mail(3))}`);
    }
    check.eq(consent(a, mail(3)), '0/0', 'customer 3 withdrew e-mail and SMS together');
    check.eq(consent(b, mail(3)), '1/1', 'while the same e-mail address in the other store keeps its consent');
    const sms2 = await send('sms', '', 'Second text.');
    check.eq(recipientsOf(sms2[0]).length, 0, 'an SMS campaign now has nobody to send to');

    // ── consent can be given again ────────────────────────────────────────────────────────────────────────────────────────
    step = 'consent can be given again';
    await checkout(a, itemsA['e2eB Promo Latte'], 1, { marketing_consent: '1' });
    check.eq(consent(a, mail(1)), '1/0', 'ticking the box again at a later checkout opts customer 1 back in');
    const email3 = await send('email', `Third ${tag}`, 'Welcome back {name}.');
    check.eq(recipientsOf(email3[0]).join(','), [mail(1), mail(4)].sort().join(','), 'and the next campaign includes them again');

    // ── another owner ────────────────────────────────────────────────────────────────────────────────────────────────────
    step = 'another owner';
    await L.login(other.page, base, P.OTHER);
    const res = await other.page.goto(`${base}/campaigns/${email1[0]}`, { waitUntil: 'domcontentloaded' });
    check.eq(res.status(), 404, 'the other owner cannot open this store\'s campaign');
    await other.page.goto(`${base}/campaigns`, { waitUntil: 'domcontentloaded' });
    check.ok(!(await other.page.evaluate(() => document.body.innerText)).includes(`Promo ${tag}`), 'nor see it in their list');
    const before = Number(L.dbq('select count(*) from campaigns where store_id = ?', [a.id])[0][0]);
    await other.page.goto(`${base}/campaigns/create`, { waitUntil: 'domcontentloaded' });
    const st2 = await other.page.evaluate(async (storeId) => {
      const t = document.querySelector('meta[name=csrf-token], input[name=_token]');
      const token = t ? (t.content || t.value) : '';
      const body = new URLSearchParams({ _token: token, store_id: storeId, channel: 'email', subject: 'x', body: 'x', segment: 'all' });
      return (await fetch('/campaigns', { method: 'POST', credentials: 'same-origin', body, redirect: 'manual' })).status;
    }, String(a.id));
    check.ok(st2 !== 500, `aiming a campaign at this store is answered, not crashed (${st2})`);
    check.eq(Number(L.dbq('select count(*) from campaigns where store_id = ?', [a.id])[0][0]), before, 'and creates no campaign in this store');
  } catch (e) {
    e.message += ` [in section: ${step}]`;
    throw e;
  } finally {
    await Promise.all([owner.close(), other.close(), guest.close(), anon.close()]);
    P.tinker(`
      $ids = ${P.php(campaignIds)};
      \\App\\Models\\CampaignRecipient::whereIn('campaign_id', $ids)->delete();
      \\App\\Models\\Campaign::whereIn('id', $ids)->delete();
      \\App\\Models\\Customer::whereIn('store_id', [${a.id}, ${b.id}])->where('email', 'like', 'e2e-mk-%-${tag}@example.test')->delete();
      \\Juwri\\Modules\\Menu\\Models\\Menu::whereIn('id', ${P.php([...Object.values(itemsA), ...Object.values(itemsB)])})->get()->each(fn ($m) => $m->delete());
      echo 'OK=1';`, 'OK');
    P.restoreOptions(a, prevA);
    P.restoreOptions(b, prevB);
  }
  check.done(log);
};
