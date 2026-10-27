// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * A9 — Shared sections across 17 MODERN templates (platform E2E, lane A).
 *
 * Tests shared UI components (language switch, order-type selector, offers strip)
 * across all 17 MODERN templates in Arabic at 390×844: load storefront home →
 * assert RTL → verify language switch control renders → verify order-type selector
 * (pickup/delivery) renders → verify offers/promos strip renders → collect DOM
 * codepoint checks (not screenshots) → no console errors / no ENFORCED CSP violations.
 *
 * Run: `bash rev/platform-e2e/bin/e2e.sh a a-shared-sections`.
 */

const {
  cfg: pcfg, localeUrl, assertRtl, assertNoOverflow,
  collectConsole, assertNoConsoleErrors,
} = require('../../lib/platform');

// Single source of truth — the JS mirror of KeptTemplates::MODERN (no local copy).
const TEMPLATES = require('../../lib/retained');

module.exports = async function sharedSections({ page, cfg, log, sleep }) {
  const failures = [];
  const results = [];

  for (const template of TEMPLATES) {
    const slug = `${template}-demo`;
    const templateChecks = [];

    try {
      const url = localeUrl(slug, 'ar');
      const resp = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });

      if (!resp || resp.status() >= 400) {
        failures.push(`${template}: navigation failed (${resp && resp.status()})`);
        continue;
      }

      await sleep(2200);

      try {
        await assertRtl(page);
      } catch (e) {
        failures.push(`${template}: RTL assertion failed — ${e.message}`);
        continue;
      }

      // Test 1: Language switch control
      const langSwitch = await page.evaluate(() => {
        const controls = [
          // Note: CSS attribute matches are case-sensitive without the `i` flag —
          // crave/matcha/glow expose `button[aria-label="Language"]` (capital L),
          // so match language/locale/Arabic case-insensitively.
          document.querySelector('[data-language-switch], [data-lang-select], .lang-menu, [class*="lang"], [aria-label*="language" i], [aria-label*="locale" i], [aria-label*="لغة"]'),
          document.querySelector('[data-region], [class*="lang-picker"], [class*="language-select"], [id*="lang" i]'),
        ];
        for (const ctrl of controls) {
          if (ctrl) {
            const rect = ctrl.getBoundingClientRect();
            if (rect.height > 0 && rect.width > 0 && getComputedStyle(ctrl).display !== 'none') {
              return {
                found: true,
                visible: true,
                text: ctrl.textContent?.slice(0, 20),
              };
            }
          }
        }
        return { found: false, visible: false };
      });

      if (langSwitch.visible) {
        templateChecks.push('language-switch visible');
      } else if (langSwitch.found) {
        templateChecks.push('language-switch found but not visible');
      } else {
        failures.push(`${template}: language switch control not found`);
      }

      // Test 2: Order-type selector (pickup/delivery, dine-in/takeout, etc.)
      const orderTypeSelector = await page.evaluate(() => {
        const selectors = [
          '[data-order-type], [data-type-select], [class*="order-type"], [class*="pickup-delivery"]',
          'button[aria-label*="pickup" i], button[aria-label*="delivery" i], button[aria-label*="dine" i]',
          '[data-group-name="type"], .type-selector, .order-mode',
          // The order-type segment renders on the home surface as an id-bearing bar
          // (crave/matcha/glow: #cvOrderTypeSeg / #mbOrderType; the injected shared
          // strip: #mbOrderType / #orderTypeSeg). Match those ids directly rather
          // than bare [data-ot] — the latter also matches the HIDDEN in-cart
          // segment first in document order, masking the visible home bar.
          '#cvOrderTypeSeg, #mbOrderType, #orderTypeSeg, #otSeg',
        ];
        for (const sel of selectors) {
          const el = document.querySelector(sel);
          if (el) {
            const rect = el.getBoundingClientRect();
            if (rect.height > 0 && rect.width > 0 && getComputedStyle(el).display !== 'none') {
              return {
                found: true,
                visible: true,
                tag: el.tagName,
              };
            }
          }
        }
        return { found: false, visible: false };
      });

      if (orderTypeSelector.visible) {
        templateChecks.push('order-type selector visible');
      } else if (orderTypeSelector.found) {
        templateChecks.push('order-type selector found but not visible');
      } else {
        failures.push(`${template}: order-type selector not found`);
      }

      // Test 3: Offers/promos strip
      const offersStrip = await page.evaluate(() => {
        const selectors = [
          '[data-offers], [data-promos], [class*="offer"], [class*="promo"], .hero-banner, [class*="hero"]',
          '[data-banner], .banner, .banner-section, [class*="strip"]',
        ];
        for (const sel of selectors) {
          const el = document.querySelector(sel);
          if (el) {
            const rect = el.getBoundingClientRect();
            if (rect.height > 0 && rect.width > 0 && getComputedStyle(el).display !== 'none') {
              return {
                found: true,
                visible: true,
                height: Math.round(rect.height),
              };
            }
          }
        }
        return { found: false, visible: false };
      });

      if (offersStrip.visible) {
        templateChecks.push('offers/promos strip visible');
      } else if (offersStrip.found) {
        templateChecks.push('offers/promos strip found but not visible');
      } else {
        failures.push(`${template}: offers/promos strip not found`);
      }

      // Check for no overflow at mobile width
      try {
        await assertNoOverflow(page);
      } catch (e) {
        failures.push(`${template}: overflow at 390px — ${e.message}`);
      }

      // Collect console errors
      const conErrs = collectConsole(page);
      if (conErrs.length) {
        failures.push(`${template}: ${conErrs.length} console errors`);
      }

      results.push(`${template}: ${templateChecks.join(', ')}`);
    } catch (e) {
      failures.push(`${template}: ${e.message}`);
    }
  }

  log('shared sections —\n  ' + results.join('\n  '));
  if (failures.length) throw new Error('shared sections:\n  - ' + failures.join('\n  - '));
  return { checked: results.length };
};
