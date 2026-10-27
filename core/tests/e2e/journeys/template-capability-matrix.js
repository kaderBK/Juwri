// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Template capability matrix — rev/Templats-issues.md, made checkable.
 *
 * IMPORTANT, and the reason an earlier version of this file was deleted: a
 * capability probe is only trustworthy once it has been CALIBRATED against a
 * theme known to have the capability. The first attempt guessed selectors,
 * reported "reviews missing on 17/17" — including crave, which demonstrably has
 * them — and would have led to rebuilding features that already exist.
 *
 * So this runs crave FIRST as the reference. If a probe cannot find a
 * capability on crave that crave is known to ship, the journey fails on the
 * PROBE, not on the theme. Only after calibration passes are the other themes
 * measured, and the result is reported as a matrix rather than a pass/fail.
 *
 * It also separates three different things the reports conflate:
 *   - the theme has no markup for it at all          (missing feature)
 *   - the markup exists but is not reachable on home (placement)
 *   - the markup exists and works                    (fine)
 *
 * KNOWN LIMITATION — read this before quoting a row. It measures the HOME page
 * only. Anything built when a product is opened (reviews above all, on themes
 * that render the section from JS) reads as ABSENT here even when it works.
 * Browser spot-checks on 2026-07-31 confirmed orbit, velora and crave all show a
 * POPULATED reviews section once a product is open, while this matrix still
 * lists velora under ABSENT. Treat "absent" as "not on the home page", never as
 * "the theme lacks it", until a product-open probe exists.
 *
 * Env: E2E_RUN_TPL_MATRIX=1, E2E_TPL_STORES (default: the 17 retained demos).
 */

const RETAINED = require('../lib/retained');

/* Selector sets derived from crave's real markup, plus the generic conventions
   the other themes use. A capability counts as PRESENT when any selector
   matches anywhere in the document, and as ON_HOME when a match is rendered
   (offsetParent !== null) without opening anything. */
const CAPS = {
  /* Every naming variant found across the seventeen themes. An earlier, shorter
     list reported "reviews missing on 14/17" — orbit and velora were among the
     "missing" and both render a populated Reviews section, under
     .product-reviews. Calibrating against crave alone cannot catch a theme that
     uses a THIRD convention, so this list was built by grepping all 17. */
  reviews: '.p-reviews, #cvReviews, .product-reviews, .review-block, #reviewBlock, '
    + '.review-card, #reviewCard, .ff-reviews, .v-reviews, .p-reviews-row, '
    + '[data-reviews], [data-review-list]',
  account: '[data-go="profile"], [data-auth], [data-open-auth], [data-sheet="auth"], [href*="login"], [href*="register"], [href*="account"], .avatar',
  my_orders: '.orders-block, .orders-list, [data-my-orders], [data-tab="orders"], [data-go="orders"], [data-view="orders"], [href*="orders"]',
  order_type: '#cvOrderTypeSeg, [data-order-type], [data-otype], [name="ordering-type"], .order-type, [data-type-switch]',
  address: '#cvAddrBlock, #cvAddrField, #cvCustAddr, [data-address], [name="address"], [data-open-address]',
  language: '[data-lang-code]',
  stories: '#storyRail, .stories, [data-story], .mbs-stories, .story-rail',
  categories: '[data-cat], [data-category], .cat, .category-chip',
  offer_strip: '[data-offer], .offer-strip, .offers, [data-offers], .promo-strip, .promos, .promo',
};

/* What crave is KNOWN to ship. If a probe misses one of these on crave the
   probe is wrong — that is the calibration contract. Note crave has no
   offer-strip: "there is no offer-strip section" is a feature request, not a
   regression against the reference. */
const CRAVE_MUST_HAVE = ['reviews', 'account', 'my_orders', 'order_type', 'address', 'language', 'categories'];

module.exports = async function templateCapabilityMatrix({ page, cfg, log, shot, sleep }) {
  const stores = (process.env.E2E_TPL_STORES || RETAINED.join(','))
    .split(',').map((s) => s.trim()).filter(Boolean);

  const measure = async (slug) => {
    const resp = await page.goto(`${cfg.baseUrl}/${slug}-demo`, { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
    if (!resp || resp.status() >= 400) return { error: `HTTP ${resp && resp.status()}` };
    await sleep(1800); // let theme bundles build their rails
    const home = await page.evaluate((caps) => {
      const out = {};
      for (const [name, sel] of Object.entries(caps)) {
        const els = [...document.querySelectorAll(sel)];
        out[name] = {
          present: els.length > 0,
          onHome: els.some((e) => e.offsetParent !== null),
        };
      }
      return out;
    }, CAPS);

    /* Second pass with a product open. Several themes build the reviews section
       (and other product-scoped UI) from JS only once an item is opened, so a
       home-only scan reports them absent when they work perfectly — that is how
       an earlier run concluded "reviews on 3/17" and nearly sent me rebuilding
       features orbit and velora already had. */
    const opened = await page.evaluate(() => {
      const card = document.querySelector('[data-open], [data-item-id], .card[data-id]');
      if (!card) return false;
      card.click();
      return true;
    });
    if (opened) {
      await sleep(1800);
      const onProduct = await page.evaluate((caps) => {
        const out = {};
        for (const [name, sel] of Object.entries(caps)) {
          out[name] = [...document.querySelectorAll(sel)].some((e) => e.offsetParent !== null);
        }
        return out;
      }, CAPS);
      for (const name of Object.keys(CAPS)) {
        home[name].onProduct = onProduct[name];
        // Visible anywhere counts as present, however the theme builds it.
        home[name].present = home[name].present || onProduct[name];
      }
    }

    return home;
  };

  // ── calibration ──
  const craveResult = await measure('crave');
  if (craveResult.error) throw new Error(`calibration: crave-demo did not load (${craveResult.error})`);

  const blind = CRAVE_MUST_HAVE.filter((c) => !craveResult[c].present);
  if (blind.length) {
    throw new Error(
      'PROBE IS WRONG, not the themes: these capabilities were not found on crave, '
      + 'which ships all of them — fix the selectors before trusting any row:\n  - ' + blind.join('\n  - ')
    );
  }
  log(`calibration ok — crave matches all ${CRAVE_MUST_HAVE.length} reference capabilities`);
  await shot(page, 'matrix-crave');

  // ── measure ──
  const rows = [{ slug: 'crave', caps: craveResult }];
  for (const slug of stores.filter((s) => s !== 'crave')) {
    const caps = await measure(slug);
    if (caps.error) { log(`tpl ${slug}: ${caps.error}`); continue; }
    rows.push({ slug, caps });
  }

  const names = Object.keys(CAPS);
  log('--- capability matrix (present / reachable on home) ---');
  for (const name of names) {
    const missing = rows.filter((r) => !r.caps[name].present).map((r) => r.slug);
    const productOnly = rows.filter((r) => r.caps[name].present && !r.caps[name].onHome && r.caps[name].onProduct).map((r) => r.slug);
    const buried = rows.filter((r) => r.caps[name].present && !r.caps[name].onHome && !r.caps[name].onProduct).map((r) => r.slug);
    log(`${name}: ${rows.length - missing.length}/${rows.length} present`
      + (missing.length ? ` | ABSENT: ${missing.join(',')}` : '')
      + (productOnly.length ? ` | on product page: ${productOnly.join(',')}` : '')
      + (buried.length ? ` | present but never visible: ${buried.join(',')}` : ''));
  }
};
