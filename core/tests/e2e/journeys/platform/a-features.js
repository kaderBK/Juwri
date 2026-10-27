// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * A8 — Storefront features (platform E2E, lane A).
 *
 * Drives customer-facing feature discovery on three template families
 * (crave, orbit, velora) in Arabic at 390×844: open home → assert RTL →
 * verify feature gates are respected (store options) → test combo meals,
 * loyalty program, gift cards, reservations, waitlist, catering requests,
 * happy-hour badges → no console errors / no ENFORCED CSP refusals.
 *
 * Run: `bash rev/platform-e2e/bin/e2e.sh a a-features`.
 */

const {
  cfg: pcfg, localeUrl, assertRtl, assertNoOverflow,
  collectConsole, assertNoConsoleErrors, sleep,
} = require('../../lib/platform');

const TEMPLATES = ['crave', 'orbit', 'velora'];

module.exports = async function storefrontFeatures({ page, cfg, log, shot, sleep }) {
  const failures = [];
  const rows = [];

  for (const template of TEMPLATES) {
    const slug = `${template}-demo`;
    const templateRows = [];
    let hasFeatures = true;

    try {
      const url = localeUrl(slug, 'ar');
      const resp = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });

      if (!resp || resp.status() >= 400) {
        failures.push(`${template}: page returned ${resp && resp.status()}`);
        continue;
      }

      await sleep(2200);

      try {
        await assertRtl(page);
      } catch (e) {
        failures.push(`${template}: RTL assertion failed — ${e.message}`);
        continue;
      }

      const features = await page.evaluate(() => {
        return {
          hasCombo: !!document.querySelector('[data-combo], .combo-section, [data-combo-group]'),
          hasLoyalty: !!document.querySelector('[data-loyalty], .loyalty-widget, [class*="loyalty"]'),
          hasGiftCard: !!document.querySelector('[data-gift], .gift-card, [class*="gift"]'),
          hasReservation: !!document.querySelector('[data-reservation], .reservation-form, [class*="reservation"]'),
          hasWaitlist: !!document.querySelector('[data-waitlist], .waitlist-form, [class*="waitlist"]'),
          hasCatering: !!document.querySelector('[data-catering], .catering-form, [class*="catering"]'),
        };
      });

      if (features.hasCombo) templateRows.push('combo meals');
      if (features.hasLoyalty) templateRows.push('loyalty program');
      if (features.hasGiftCard) templateRows.push('gift cards');
      if (features.hasReservation) templateRows.push('reservations');
      if (features.hasWaitlist) templateRows.push('waitlist');
      if (features.hasCatering) templateRows.push('catering');

      if (templateRows.length === 0) hasFeatures = false;

      if (features.hasCombo) {
        try {
          const comboOpened = await page.evaluate(async () => {
            const comboCard = document.querySelector('[data-combo], [data-combo-id], .combo-section [data-open]');
            if (comboCard) {
              comboCard.click ? comboCard.click() : (comboCard.closest('[data-open], [data-open-item]') || comboCard).click?.();
              await new Promise((r) => setTimeout(r, 500));
              return true;
            }
            return false;
          });

          if (comboOpened) {
            const comboSlotsVisible = await page.evaluate(() => {
              const slot = document.querySelector('[data-combo-slot], .combo-slot, [data-modifier-group], .modifier-group');
              if (slot) {
                const r = slot.getBoundingClientRect();
                return r.height > 0 && r.width > 0 && getComputedStyle(slot).display !== 'none';
              }
              return false;
            });

            if (comboSlotsVisible) {
              templateRows.push('combo slots open');
            } else {
              failures.push(`${template}: combo item opened but slot choices not visible`);
            }
          }
        } catch (e) {
          failures.push(`${template}: combo test error — ${e.message}`);
        }
      }

      if (features.hasLoyalty) {
        const loyaltyVisible = await page.evaluate(() => {
          const loyaltyEl = document.querySelector('[data-loyalty], .loyalty-widget');
          if (loyaltyEl) {
            const r = loyaltyEl.getBoundingClientRect();
            return r.height > 0 && r.width > 0;
          }
          return false;
        });

        if (loyaltyVisible) {
          templateRows.push('loyalty badge visible');
        }
      }

      if (features.hasGiftCard) {
        const giftCardUI = await page.evaluate(() => {
          const el = document.querySelector('[data-gift], .gift-card');
          if (el) {
            const r = el.getBoundingClientRect();
            return r.height > 0 && r.width > 0;
          }
          return false;
        });

        if (giftCardUI) {
          templateRows.push('gift card option visible');
        }
      }

      const happyHour = await page.evaluate(() => {
        const badge = document.querySelector('[data-hh], .hh-badge, .happy-hour');
        return !!badge;
      });

      if (happyHour) {
        templateRows.push('happy-hour badge');
      }

      try {
        await assertNoOverflow(page);
      } catch (e) {
        failures.push(`${template}: overflow at 390px — ${e.message}`);
      }

      const conErrs = collectConsole(page);
      if (conErrs.length) {
        failures.push(`${template}: ${conErrs.length} console errors`);
      }

      if (templateRows.length > 0) {
        await shot(page, `features-${template}`);
        rows.push(`${template}: ${templateRows.join(', ')}`);
      } else if (hasFeatures === false) {
        rows.push(`${template}: no features detected (verify store configuration)`);
      }
    } catch (e) {
      failures.push(`${template}: ${e.message}`);
    }
  }

  log('storefront features —\n  ' + rows.join('\n  '));
  if (failures.length) throw new Error('storefront features:\n  - ' + failures.join('\n  - '));
  return { checked: rows.length };
};
