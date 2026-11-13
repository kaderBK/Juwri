// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * TPL (opt-in) — the rating histogram and write-a-review, ported to the themes
 * that render item reviews without them.
 *
 * crave, matcha and glow ship the full widget. orbit and velora render a list,
 * crumb renders a count-and-average row, and none of the three had a
 * distribution chart or any way to leave a review with text — which is what the
 * "no Reviews section like Crave" reports turned out to be about once the
 * sections themselves were browser-verified.
 *
 * The shared block ADDS the missing pieces to the theme's own section, so what
 * has to be checked is exactly what a server test cannot see: that it lands in
 * the right place on three different section shapes, that it leaves the three
 * complete widgets alone, and that the write flow reaches the server.
 *
 * Env: E2E_RUN_REVIEWS=1. The write check posts a REAL review (held pending, so
 * nothing public changes) against E2E_REVIEW_STORE, default orbit-demo.
 */

/** Themes whose item-reviews section needs the upgrade. */
const UPGRADED = ['orbit', 'velora', 'crumb'];
/** Themes that already ship crave's full widget and must be left alone. */
const COMPLETE = ['crave', 'matcha', 'glow'];

const browserUserAgent = (page) => page.browser().userAgent();

module.exports = async function storefrontItemReviews({ page, cfg, log, shot, sleep }) {
  const failures = [];
  const rows = [];

  const openFirstItem = async () => {
    await page.evaluate(() => {
      document.querySelectorAll(
        '#splashSkip, #mbSplashSkip, #obSkip, #onbSkip, .splash-skip, .ob-skip, .onb-skip'
      ).forEach((skip) => skip.click());
      // Not the meal-deal sheet: it carries data-open="0" (a visibility flag, not an item id) and is
      // the first match in document order on every theme that renders the combo rail.
      const card = document.querySelector(
        '[data-open]:not(.mb-combo-sheet):not([data-open="0"]), [data-open-item], [data-item-id]'
      );
      if (card) (card.querySelector('[data-open-item], [data-open]') || card).click();
    });
    await sleep(2600);
  };

  for (const theme of [...UPGRADED, ...COMPLETE]) {
    const resp = await page.goto(`${cfg.baseUrl}/${theme}-demo`, { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
    if (!resp || resp.status() >= 400) { failures.push(`${theme}: page returned ${resp && resp.status()}`); continue; }
    await sleep(2200);
    await openFirstItem();

    const seen = await page.evaluate(() => {
      const vis = (e) => {
        if (!e) return false;
        const r = e.getBoundingClientRect();
        if (r.width <= 0 || r.height <= 0) return false;
        const c = getComputedStyle(e);
        return c.display !== 'none' && c.visibility !== 'hidden' && parseFloat(c.opacity) > 0.01;
      };
      const block = document.querySelector('.mbr-x');
      const write = document.querySelector('[data-mbr-write]');

      return {
        present: !!block,
        visible: vis(block),
        bars: block ? block.querySelectorAll('.mbr-x-bar').length : 0,
        write: vis(write),
        /* The theme's own figure must not be doubled: where the section already
           prints one (orbit "Reviews 84"), the block shows bars only. */
        ownFigure: !!document.querySelector('.product-reviews, .p-reviews-row'),
        blockFigure: block ? !!block.querySelector('.mbr-x-avg') : false,
      };
    });

    if (COMPLETE.includes(theme)) {
      if (seen.present) failures.push(`${theme} already ships the full widget — the shared block must stand down`);
      else rows.push(`${theme}: left alone (native widget)`);
      continue;
    }

    if (!seen.present) { failures.push(`${theme}: no histogram block was injected`); continue; }
    if (!seen.visible) { failures.push(`${theme}: histogram block is in the DOM but not visible`); continue; }
    if (seen.bars !== 5) { failures.push(`${theme}: ${seen.bars} distribution bars, expected 5`); continue; }
    if (!seen.write) { failures.push(`${theme}: no visible write-a-review control`); continue; }
    if (seen.ownFigure && seen.blockFigure) {
      failures.push(`${theme}: the block prints its own average next to the theme's — two different counts`);
    }

    rows.push(`${theme}: 5 bars, write control${seen.blockFigure ? ', own figure' : ''}`);
    await shot(page, `item-reviews-${theme}`);
  }

  /* The write flow, end to end and for real. Held pending by the server, so the
     public list is unchanged — but anything less would not prove the endpoint
     accepts what the sheet sends. */
  const store = process.env.E2E_REVIEW_STORE || 'orbit-demo';
  /* The server allows one review per (ip + user agent + item). A fresh UA suffix per run keeps the
     write check repeatable against a database that was not reseeded (SKIP_SEED=1, a shared dev DB). */
  await page.setUserAgent(`${await browserUserAgent(page)} e2e-reviews-${Date.now()}`);
  await page.goto(`${cfg.baseUrl}/${store}`, { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
  await sleep(2200);
  await openFirstItem();

  const wrote = await page.evaluate(async () => {
    const write = document.querySelector('[data-mbr-write]');
    if (!write) return { error: 'no write control' };
    write.click();
    await new Promise((r) => setTimeout(r, 500));

    const sheet = document.querySelector('.mbr-sheet');
    if (!sheet || sheet.hidden) return { error: 'sheet did not open' };

    // Submitting with no rating must be refused before any request goes out.
    sheet.querySelector('[data-mbr-send]').click();
    await new Promise((r) => setTimeout(r, 300));
    const refused = sheet.querySelector('[data-mbr-msg]').textContent.trim();

    sheet.querySelector('[data-mbr-star="4"]').click();
    sheet.querySelector('[data-mbr-name]').value = 'E2E Tester';
    sheet.querySelector('[data-mbr-text]').value = 'Posted by the shared item-reviews journey.';
    sheet.querySelector('[data-mbr-send]').click();
    /* The sheet confirms, then closes itself ~1.8s AFTER the response lands — so a fixed sleep
       races a slow server. Capture the confirmation, then wait (bounded) for the close. */
    let message = '';
    for (let i = 0; i < 40 && (!message || message === refused); i++) {
      await new Promise((r) => setTimeout(r, 150));
      message = sheet.querySelector('[data-mbr-msg]').textContent.trim();
    }
    for (let i = 0; i < 40 && !sheet.hidden; i++) await new Promise((r) => setTimeout(r, 150));

    return {
      refused,
      message,
      closed: sheet.hidden,
      stars: sheet.querySelectorAll('[data-mbr-star].is-on').length,
    };
  });

  if (wrote.error) failures.push(`write flow: ${wrote.error}`);
  else {
    if (!wrote.refused) failures.push('write flow: submitting with no rating was not refused');
    if (!wrote.message || /^$/.test(wrote.message)) failures.push('write flow: no confirmation message');
    if (!wrote.closed) failures.push(`write flow: the sheet stayed open after a successful send (message: "${wrote.message}")`);
    rows.push(`write flow on ${store}: refused without a rating, accepted with 4 stars`);
  }

  /* Consolidation: orbit and velora ship their own inline "rate this dish"
     stars, which used to POST a stars-only review straight to a second
     endpoint. They must now OPEN the sheet preselected to the tapped rating and
     make NO immediate request — one rating flow, one endpoint. */
  for (const theme of ['orbit', 'velora']) {
    await page.goto(`${cfg.baseUrl}/${theme}-demo`, { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
    await sleep(2200);
    await openFirstItem();

    const inline = await page.evaluate(async () => {
      const posts = [];
      const of = window.fetch;
      window.fetch = function (i, init) {
        const u = (typeof i === 'string') ? i : (i && i.url);
        const m = (init && init.method) || 'GET';
        if (String(m).toUpperCase() === 'POST' && u && /review/.test(u)) {
          posts.push(String(u));
          return Promise.resolve(new Response(JSON.stringify({ ok: true }), { status: 201, headers: { 'Content-Type': 'application/json' } }));
        }
        return of.apply(this, arguments);
      };

      const star = document.querySelector('#rateStars [data-rate="4"]');
      if (!star) return { error: 'no inline rate control' };
      star.click();
      await new Promise((r) => setTimeout(r, 700));

      const sheet = document.querySelector('.mbr-sheet');
      return {
        immediatePosts: posts.length, // must be 0 — the old path posted here
        opened: !!sheet && !sheet.hidden,
        preselected: sheet ? sheet.querySelectorAll('[data-mbr-star].is-on').length : 0,
      };
    });

    if (inline.error) { failures.push(`${theme} inline stars: ${inline.error}`); continue; }
    if (inline.immediatePosts) failures.push(`${theme}: tapping an inline star fired ${inline.immediatePosts} immediate review POST(s) — the old path is still live`);
    if (!inline.opened) failures.push(`${theme}: tapping an inline star did not open the review sheet`);
    if (inline.preselected !== 4) failures.push(`${theme}: sheet opened with ${inline.preselected} stars, expected the tapped 4`);
    rows.push(`${theme}: inline stars open the sheet preselected, no immediate post`);
  }

  log('item reviews —\n  ' + rows.join('\n  '));
  if (failures.length) throw new Error('item reviews:\n  - ' + failures.join('\n  - '));
  return { checked: rows.length };
};
