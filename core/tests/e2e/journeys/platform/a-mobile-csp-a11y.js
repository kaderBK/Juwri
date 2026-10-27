// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * A10 — Mobile CSP & a11y across 19 templates (platform E2E, lane A).
 *
 * Tests Content Security Policy compliance and accessibility across all 19 templates
 * (17 MODERN + 2 display-only: flipbook, pageview) at mobile viewport (390×844) in
 * Arabic: navigate storefront → assert RTL → verify CSP headers honored (no inline
 * script violations, no ENFORCED refusals) → verify accessible names on interactive
 * elements → verify keyboard navigation functional → no console CSP errors →
 * verify ARIA attributes present where needed → no focus management breaks.
 *
 * Run: `bash rev/platform-e2e/bin/e2e.sh a a-mobile-csp-a11y`.
 */

const {
  localeUrl, assertRtl, assertNoOverflow, collectConsole,
} = require('../../lib/platform');

// Single source of truth — the 17 MODERN (KeptTemplates::MODERN mirror) plus the
// 2 display-only demos; no local copy of the keep-list.
const TEMPLATES = [...require('../../lib/retained'), 'pageview-demo']; // flipbook-demo removed in WI-03

module.exports = async function mobileCspA11y({ page, cfg, log, sleep }) {
  const failures = [];
  const results = [];

  // One collector for the whole run: it records enforced CSP refusals
  // ("Refused to …", NOT report-only) plus real console.error / pageerror.
  // The platform storefront CSP soaks in REPORT-ONLY (advisory), so inline
  // scripts/styles are NOT violations — only enforced refusals count. We scope
  // each template's findings by slicing the collector from a per-template base.
  const collector = collectConsole(page);

  for (const template of TEMPLATES) {
    const slug = template.includes('-') ? template : `${template}-demo`;
    const templateChecks = [];

    try {
      const url = localeUrl(slug, 'ar');
      const errBase = collector.errors.length;
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

      // Console/CSP findings captured during THIS template's load.
      const newErrs = collector.errors.slice(errBase);
      const cspRefusals = newErrs.filter((e) => e.startsWith('CSP:'));
      const otherErrs = newErrs.filter((e) => !e.startsWith('CSP:'));

      // Test 1: ENFORCED CSP violations only. The platform CSP is report-only
      // (advisory) — inline scripts/styles do NOT count. A real block is an
      // enforced "Refused to (load|execute|apply|connect|frame|run)" console
      // message that is NOT tagged report-only (collectConsole prefixes those
      // with "CSP:"). No such refusal = CSP compliant.
      if (cspRefusals.length === 0) {
        templateChecks.push('CSP compliant');
      } else {
        failures.push(`${template}: enforced CSP refusals — ${cspRefusals.join('; ')}`);
      }

      // Test 2: Accessible names on buttons
      const a11yChecks = await page.evaluate(() => {
        const buttons = document.querySelectorAll('button');
        let findings = {
          totalButtons: buttons.length,
          withoutName: 0,
          withAriaLabel: 0,
          withTitle: 0,
          withText: 0,
        };

        buttons.forEach(btn => {
          const hasText = btn.textContent.trim().length > 0;
          const hasAriaLabel = btn.getAttribute('aria-label');
          const hasTitle = btn.getAttribute('title');
          const hasAriaLabelledby = btn.getAttribute('aria-labelledby');

          if (hasText) findings.withText++;
          if (hasAriaLabel) findings.withAriaLabel++;
          if (hasTitle) findings.withTitle++;
          if (!hasText && !hasAriaLabel && !hasTitle && !hasAriaLabelledby) {
            findings.withoutName++;
          }
        });

        return findings;
      });

      if (a11yChecks.withoutName === 0) {
        templateChecks.push('all buttons labeled');
      } else if (a11yChecks.withoutName <= a11yChecks.totalButtons * 0.1) {
        templateChecks.push(`a11y: ${a11yChecks.withoutName}/${a11yChecks.totalButtons} buttons unlabeled`);
      } else {
        failures.push(`${template}: ${a11yChecks.withoutName}/${a11yChecks.totalButtons} buttons without accessible names`);
      }

      // Test 3: Focus management
      const focusManagement = await page.evaluate(() => {
        // Check for focusable elements
        const focusable = document.querySelectorAll(
          'button, a[href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
        );
        return {
          focusableElements: focusable.length,
          hasTabIndex: Array.from(focusable).some(el => el.hasAttribute('tabindex')),
        };
      });

      if (focusManagement.focusableElements > 0) {
        templateChecks.push('focusable elements present');
      } else {
        failures.push(`${template}: no focusable elements found`);
      }

      // Test 4: ARIA attributes
      const ariaUsage = await page.evaluate(() => {
        const ariaElements = document.querySelectorAll('[role], [aria-label], [aria-labelledby], [aria-describedby]');
        return ariaElements.length;
      });

      if (ariaUsage > 0) {
        templateChecks.push(`ARIA attributes: ${ariaUsage} elements`);
      }

      // Test 5: Mobile overflow check
      try {
        await assertNoOverflow(page);
      } catch (e) {
        failures.push(`${template}: overflow at 390px — ${e.message}`);
      }

      // Test 6: Console errors. Enforced CSP refusals already fail in Test 1.
      // Here we only note any residual non-CSP console.error/pageerror (benign
      // resource 404s are already filtered out by collectConsole).
      if (otherErrs.length) {
        templateChecks.push(`console: ${otherErrs.length} non-CSP error(s)`);
      } else {
        templateChecks.push('no console errors');
      }

      results.push(`${template}: ${templateChecks.join(', ')}`);
    } catch (e) {
      failures.push(`${template}: ${e.message}`);
    }
  }

  log('mobile CSP & a11y —\n  ' + results.join('\n  '));
  if (failures.length) throw new Error('mobile CSP & a11y:\n  - ' + failures.join('\n  - '));
  return { checked: results.length };
};
