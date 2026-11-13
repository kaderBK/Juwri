// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * TPL (opt-in) — the shared offers strip renders the store's live discounts on
 * every retained theme, and its cards actually open the item.
 *
 * The strip is server-rendered from items MenuScheduleService::decorate() has
 * already marked down, so a PHPUnit test can prove the data. What it cannot
 * prove is the part that broke every previous theme-wide surface: whether the
 * strip is VISIBLE once seventeen different bundles have finished laying the
 * page out, and whether its cards reach each theme's own item sheet. Those are
 * browser facts, so they are checked in a browser.
 *
 * A card that resolves no theme card marks itself [data-mbo-inert] rather than
 * rendering a dead control — this asserts none of them had to, which is the
 * real coverage claim: the strip's one click path works on all seventeen.
 *
 * Env: E2E_RUN_OFFERS=1, E2E_OFFER_STORES (default: the 17 retained demos).
 * Needs ThemeShowcaseSeeder — it seeds the happy hour the strip reads.
 */

const RETAINED = require('../lib/retained');

module.exports = async function storefrontOffersStrip({ page, cfg, log, shot, sleep }) {
  const stores = (process.env.E2E_OFFER_STORES || RETAINED.join(','))
    .split(',').map((s) => s.trim()).filter(Boolean);

  const failures = [];
  const rows = [];

  for (const theme of stores) {
    const slug = `${theme}-demo`;
    const resp = await page.goto(`${cfg.baseUrl}/${slug}`, { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
    if (!resp || resp.status() >= 400) { failures.push(`${theme}: page returned ${resp && resp.status()}`); continue; }
    await sleep(2600); // the strip re-checks for theme cards at 900ms and 2500ms

    /* Dismiss the intro splash first, exactly as the CSP sweep does. dough and
       picnic cover the whole page with an onboarding overlay until a guest taps
       past it, so measuring underneath it says "covered" about every element on
       the page, the strip included. */
    await page.evaluate(() => {
      document.querySelectorAll(
        '#splashSkip, #mbSplashSkip, #obSkip, #onbSkip, [id$="SplashSkip"], [id$="splashSkip"], '
        + '.splash-skip, .ob-skip, .onb-skip, [data-csp-action="splash-skip"]'
      ).forEach((skip) => skip.click());
    });
    await sleep(600);

    const seen = await page.evaluate(async () => {
      const strip = document.getElementById('mbOffersStrip');
      if (!strip) return { present: false };
      const cards = [...strip.querySelectorAll('.mbo-card')];

      /* HIT-TEST, not a box measurement. Four themes paint an app shell of
         position:fixed panes over the whole viewport: the strip measured a
         full-size visible box on every one of them while being completely
         covered by the theme's own chrome. Scroll it into view and ask the
         document what is actually on top of it. */
      strip.scrollIntoView({ block: 'center' });
      await new Promise((r) => setTimeout(r, 400));
      const r = strip.getBoundingClientRect();
      const hit = document.elementFromPoint(
        Math.round(r.left + r.width / 2),
        Math.round(Math.max(1, Math.min(r.top + 30, window.innerHeight - 1))),
      );

      return {
        present: true,
        visible: r.width > 0 && r.height > 0 && !!hit && strip.contains(hit),
        cards: cards.length,
        inert: cards.filter((c) => c.hasAttribute('data-mbo-inert')).length,
        // Every card must show both prices, and the "now" price must be lower.
        badPrices: cards.filter((c) => {
          // Both cells carry the store's currency sign, on either side.
          const num = (sel) => parseFloat(String((c.querySelector(sel) || {}).textContent || '').replace(/[^0-9.]/g, ''));
          const now = num('.mbo-now');
          const was = num('.mbo-was');
          return !(now > 0) || !(was > 0) || !(was > now);
        }).length,
        /* Placement, not just presence. The strip is moved next to the theme's
           menu by script, and two wrong anchors both LOOKED fine to a presence
           check: one left it under the theme's fixed chrome, another hopped it
           above the hero and top bar to the very top of the page. Distance to
           the nearest theme item card is the thing that actually says "this is
           next to the menu". */
        gapToMenu: (() => {
          /* Distance to the NEAREST theme item card, not to the card of the
             first offer — that item can legitimately sit far down a long menu,
             which made a correctly-placed strip measure 500px "adrift".
             The attribute union is the one the strip resolves against (feast
             and velvet carry only `data-id`); a picture and some height are
             what separate a real item card from a chip carrying the same id. */
          /* What legitimately sits between the strip and the menu: the meal-deals rail and the
             order-type bar (both injected next to the strip by the same anchor helper) and the
             theme's own category rows, which it marks [data-mb-grid-lead] precisely so injected
             sections land ABOVE them. Walk past those siblings and measure from the last one, so
             the gap is "strip block -> first menu card", not the height of the category selector. */
          let lower = r.bottom;
          const walked = [];
          for (let n = strip.nextElementSibling; n; n = n.nextElementSibling) {
            if (!(n.id === 'mb-combo-rail' || n.id === 'mbOrderType' || n.hasAttribute('data-mb-grid-lead'))) break;
            lower = Math.max(lower, n.getBoundingClientRect().bottom);
            walked.push(n);
          }
          const sel = '[data-open], [data-open-item], [data-item-id], [data-menu-id], [data-id]';
          const gaps = [...document.querySelectorAll(sel)]
            .filter((e) => !strip.contains(e) && !walked.some((n) => n.contains(e)) && e.querySelector('img') && e.getBoundingClientRect().height > 60)
            // Edge-to-edge distance between the two boxes (0 when they touch or overlap).
            // A card ABOVE the strip (the popular rail sits there on several themes) is
            // measured from its bottom edge to the strip's top; one below, from the strip's
            // bottom to its top. The earlier top-to-bottom form added the strip's own height
            // and the card's own height to the gap for anything above it.
            .map((e) => {
              const b = e.getBoundingClientRect();
              return Math.max(0, b.top - lower, r.top - b.bottom);
            });
          return gaps.length ? Math.round(Math.min(...gaps)) : null;
        })(),
        firstId: cards.length ? cards[0].getAttribute('data-mbo-item') : null,
      };
    });

    if (!seen.present) { failures.push(`${theme}: no offers strip rendered (is the happy hour seeded?)`); continue; }
    if (!seen.visible) { failures.push(`${theme}: strip is in the DOM but not visible`); continue; }
    if (!seen.cards) { failures.push(`${theme}: strip rendered with zero cards — an empty shell`); continue; }
    if (seen.inert) { failures.push(`${theme}: ${seen.inert}/${seen.cards} cards resolve no theme card`); continue; }
    if (seen.badPrices) { failures.push(`${theme}: ${seen.badPrices}/${seen.cards} cards lack a lower discounted price`); continue; }
    if (seen.gapToMenu === null) { failures.push(`${theme}: no theme item card on the page to anchor against`); continue; }
    // All seventeen land within ~100px of the menu; 300 leaves room for a
    // theme's own section spacing without admitting "somewhere up near the hero".
    if (seen.gapToMenu > 300) { failures.push(`${theme}: strip sits ${seen.gapToMenu}px from the nearest menu card — it drifted off its anchor`); continue; }

    // Shot the strip itself, before the click navigates away from it.
    await shot(page, `offers-strip-${theme}`);

    /* Click the first card: the theme's own item view must open. Seventeen
       themes disagree completely on what that is — crave and orbit swap in a
       full-page `.view-sub`, feast slides a drawer, others use a bottom sheet —
       so asserting on `dialog, .sheet, .modal` proves nothing (it reported all
       seventeen broken while every one of them worked). The theme-agnostic
       fact is: elements that were not visible became visible, and one of them
       is the item's own name. */
    const opened = await page.evaluate(async () => {
      /* offsetParent is null for ANY position:fixed element, which is what most
         of these product views are — measure the box instead. */
      const vis = (e) => {
        const r = e.getBoundingClientRect();
        if (r.width <= 0 || r.height <= 0) return false;
        const c = getComputedStyle(e);
        return c.display !== 'none' && c.visibility !== 'hidden' && parseFloat(c.opacity) > 0.01;
      };
      const strip = document.getElementById('mbOffersStrip');
      const card = strip.querySelector('.mbo-card');
      const name = card.querySelector('.mbo-name').textContent.trim();
      const snap = () => new Set([...document.querySelectorAll('body *')].filter(vis));

      const before = snap();
      card.click();
      await new Promise((r) => setTimeout(r, 1400));
      const fresh = [...snap()].filter((e) => !before.has(e) && !strip.contains(e));
      // Not just "something moved": the item that was clicked is what opened.
      return fresh.some((e) => e.textContent.trim() === name);
    });
    if (!opened) failures.push(`${theme}: clicking an offer card opened no item view`);

    rows.push(`${theme}: ${seen.cards} cards, ${seen.gapToMenu}px from the menu, opens=${opened}`);
  }

  log('offers strip —\n  ' + rows.join('\n  '));
  if (failures.length) throw new Error('offers strip:\n  - ' + failures.join('\n  - '));
  return { checked: rows.length };
};
