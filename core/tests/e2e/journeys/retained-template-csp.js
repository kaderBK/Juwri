// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Customer interaction matrix for the 17 storefront templates retained for
 * production. This is deliberately deeper than the viewport smoke: every
 * theme opens a real product, adds it to the local cart, opens the cart, and
 * reaches and audits checkout without submitting an order. Set
 * E2E_RETAINED_MOBILE=1 to repeat the journey at a 390x844 mobile viewport
 * with horizontal-overflow and checkout-action visibility assertions.
 *
 * Set E2E_REQUIRE_CSP=1 when the target environment has CSP_ENFORCE=true. The
 * journey then requires the enforcing header and rejects every CSP refusal.
 */

const THEMES = {
  crave:   { searchOpen: '#cvSearchOpen', searchInput: '#cvSearchOverlayInput', searchResult: '#cvSearchResults [data-open]', searchClose: '#cvSearchClose', add: '#addToCart', cart: '[data-tab="cart"]', checkout: '#cvGoCheckout', checkoutState: '[data-view="checkout"]', finalAction: '#cvPlaceOrder', total: '#cvCheckoutTotals, #cvCheckoutCtaTotal' },
  matcha:  { searchInput: '#cvSearchInput', searchResult: '#cvGrid [data-open]', add: '#addToCart', cart: '[data-go="cart"]', checkout: '#cvGoCheckout', checkoutState: '[data-view="checkout"]', finalAction: '#cvPlaceOrder', total: '#cvCheckoutTotals, #cvCheckoutCtaTotal' },
  glow:    { searchInput: '#cvSearchInput', searchResult: '#cvGrid [data-open]', add: '#addToCart', cart: '[data-tab="cart"]', checkout: '#cvGoCheckout', checkoutState: '[data-view="checkout"]', finalAction: '#cvPlaceOrder', total: '#cvCheckoutTotals, #cvCheckoutCtaTotal' },
  aurora:  { product: '[data-open-item]', searchSeed: '.au-card[data-name]', searchInput: '#au-search-input', searchResult: '.au-card[data-name]', add: '#au-add-to-cart', cart: '#au-cart-toggle', checkoutState: '#au-order-form', finalAction: '#au-checkout-btn', total: '#au-checkout-total' },
  vivid:   { product: '[data-open-item]', searchSeed: '[data-item-card][data-name]', searchInput: '#vd-search-input', searchResult: '[data-item-card][data-name]', add: '#vd-detail-add', cart: '#vd-cart-toggle', checkoutState: '#vd-order-form', finalAction: '#vd-checkout-btn', total: '#vd-checkout-total' },
  feast:   { product: '.ff-menu-card[data-id]:not(.ff-sold-out)', searchSeed: '.ff-menu-card[data-id]', searchOpen: '#ff-search-open', searchInput: '#ff-sl-input', searchResult: '#ff-sl-results .ff-sl-row', searchClose: '#ff-sl-close', add: '#ff-detail-add-btn', cart: '#ff-cart-toggle', checkoutState: '#ff-checkout-form', finalAction: '#ff-submit-order-btn', total: '#ff-sum-total' },
  velvet:  { product: '.v-menu-card[data-id]:not(.v-sold-out)', searchInput: '#v-search-input', searchResult: '.v-menu-card[data-id]', add: '#v-detail-add-btn', cart: '#v-cart-toggle', checkoutState: '#v-checkout-form', finalAction: '#v-submit-order-btn', total: '#v-sum-total' },
  dough:   { searchOpen: '#searchBtn', searchInput: '#soInput', searchResult: '#soResults [data-open]', searchClose: '#soClose', add: '#iAdd', cart: '#bagBtn', checkout: '#goCheckout', checkoutState: '[data-view="checkout"]', finalAction: '#placeOrder', total: '#coBandTotal, #poTotal' },
  plate:   { searchOpen: '#searchBtn', searchInput: '#searchInput', searchResult: '#searchResults [data-sopen]', searchClose: '#searchClose', add: '#addBtn', cart: '#cartBtn', checkout: '#checkoutBtn', checkoutState: '[data-view="checkout"]', finalAction: '#placeBtn', total: '#ckTotals, #ckTotalCta' },
  lime:    { searchSeed: '.card[data-open]', searchOpen: '#searchGo', searchInput: '#searchInput', searchResult: '#searchGrid [data-open]', searchClose: '[data-tab="home"]', searchLabel: '.card-name', add: '#addBtn', cart: '[data-tab="cart"]', checkout: '#goCheckout', checkoutState: '[data-view="checkout"]', finalAction: '#placeOrder', total: '#checkoutTotals, #placeTotal' },
  picnic:  { searchOpen: '#searchBtn', searchInput: '#soInput', searchResult: '#soResults [data-open]', searchClose: '#soClose', add: '#addToCart', cart: '[data-tab="cart"]', checkout: '#goCheckout', checkoutState: '[data-view="checkout"]', finalAction: '#placeOrder', total: '#checkoutTotals, #checkoutCtaTotal' },
  orbit:   { searchOpen: '#searchOpen', searchInput: '#searchInput', searchResult: '#searchResults [data-open]', searchClose: '#searchClose', add: '#addToCart', cart: '[data-tab="cart"]', checkout: '#goCheckout', checkoutState: '[data-view="checkout"]', finalAction: '#placeOrder', total: '#checkoutTotals, #checkoutCtaTotal' },
  velora:  { searchOpen: '#searchOpen', searchInput: '#soInput', searchResult: '#soResults [data-open]', searchClose: '#soBack', add: '#addToCart', cart: '[data-tab="cart"]', checkout: '#goCheckout', checkoutState: '[data-view="checkout"]', finalAction: '#placeOrder', total: '#checkoutTotals, #checkoutCtaTotal' },
  crumb:   { searchOpen: '#homeSearchBtn', searchInput: '#soInput', searchResult: '#soResults [data-open]', searchClose: '#soClose', add: '#addToCart', cart: '[data-tab="cart"]', checkout: '#goCheckout', checkoutState: '[data-view="checkout"]', finalAction: '#placeOrder', total: '#checkoutTotals, #checkoutCtaTotal' },
  soiree:  { searchOpen: '#navSearch', searchInput: '#soInput', searchResult: '#soResults [data-open]', searchClose: '#soClose', searchLabel: '.hname b, .m-copy b', add: '#pAddBtn', cart: '[data-go="cart"]', checkout: '[data-paytile]', checkoutState: '[data-view="checkout"]', finalAction: '#placeOrder', total: '#checkoutTotals, #checkoutCtaTotal' },
  scoop:   { searchSeed: '.pcard[data-open]', searchOpen: '#searchBtn', searchInput: '#searchInput', searchResult: '#grid [data-open]', searchClose: '#searchClose', searchLabel: '.pc-name', add: '#addToCart', cart: '[data-tab="cart"]', checkout: '#goCheckout', checkoutState: '[data-view="checkout"]', finalAction: '#placeOrder', total: '#checkoutTotals, #checkoutCtaTotal' },
  ember:   { searchSeed: '.pcard[data-open]', searchOpen: '#searchOpen', searchInput: '#searchInput', searchResult: '#searchResults [data-open]', searchClose: '#searchClose', searchLabel: '.pcard > b, .mrow-body b', add: '#addToCart', cart: '[data-go="cart"]', checkout: '#goCheckout', checkoutState: '[data-view="checkout"]', finalAction: '#placeOrder', total: '#coTotals, #placeTotal' },
};

async function clickFirst(page, selector, label) {
  const result = await page.evaluate((sel) => {
    const candidates = Array.from(document.querySelectorAll(sel));
    const target = candidates.find((el) => {
      const style = getComputedStyle(el);
      const rect = el.getBoundingClientRect();
      return !el.disabled && !el.hidden && style.display !== 'none' &&
        style.visibility !== 'hidden' && rect.width > 0 && rect.height > 0;
    });
    if (!target) return false;
    target.click();
    return true;
  }, selector);

  if (!result) throw new Error(`${label}: no enabled visible control matched ${selector}`);
}

async function satisfyMinimumOrder(page, checkoutSelector, label) {
  const result = await page.evaluate((selector) => {
    const checkout = document.querySelector(selector);
    if (!checkout) return { ready: false, reason: `missing ${selector}` };

    let increments = 0;
    while (checkout.disabled && increments < 50) {
      const plus = Array.from(document.querySelectorAll('[data-cq="1"]')).find((element) => {
        const style = getComputedStyle(element);
        const rect = element.getBoundingClientRect();
        return !element.disabled && !element.hidden && style.display !== 'none' &&
          style.visibility !== 'hidden' && rect.width > 0 && rect.height > 0;
      });
      if (!plus) break;
      plus.click();
      increments += 1;
    }

    return {
      ready: !checkout.disabled,
      increments,
      reason: checkout.disabled ? 'minimum order remained unsatisfied' : null,
    };
  }, checkoutSelector);

  if (!result.ready) {
    throw new Error(`${label}: ${result.reason} after ${result.increments} cart increments`);
  }
}

async function exerciseSearch(page, contract, theme, sleep) {
  const query = await page.evaluate((selector, labelSelector) => {
    const candidates = Array.from(document.querySelectorAll(selector));
    const target = candidates.find((element) => {
      const style = getComputedStyle(element);
      const rect = element.getBoundingClientRect();
      return !element.hidden && style.display !== 'none' && style.visibility !== 'hidden' &&
        rect.width > 0 && rect.height > 0;
    });
    if (!target) return '';

    const labelled = target.getAttribute('data-name') ||
      (labelSelector ? target.querySelector(labelSelector)?.textContent : '') ||
      target.querySelector('[class*="name"], h2, h3, h4, b')?.textContent ||
      target.textContent;
    return String(labelled || '').replace(/\s+/g, ' ').trim().slice(0, 48);
  }, contract.searchSeed || contract.product || '[data-open]', contract.searchLabel || '');
  if (!query) throw new Error(`${theme} search: could not derive a real product query`);

  if (contract.searchOpen) {
    await clickFirst(page, contract.searchOpen, `${theme} search open`);
    await sleep(250);
  }

  const inputReady = await page.evaluate((selector, value) => {
    const input = document.querySelector(selector);
    if (!input) return false;
    input.removeAttribute('readonly');
    input.focus();
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    return true;
  }, contract.searchInput, query);
  if (!inputReady) throw new Error(`${theme} search: missing input ${contract.searchInput}`);
  await sleep(250);

  const visibleResults = await page.evaluate((selector) =>
    Array.from(document.querySelectorAll(selector)).filter((element) => {
      const style = getComputedStyle(element);
      const rect = element.getBoundingClientRect();
      return !element.hidden && style.display !== 'none' && style.visibility !== 'hidden' &&
        rect.width > 0 && rect.height > 0;
    }).length
  , contract.searchResult);
  if (!visibleResults) {
    throw new Error(`${theme} search: no visible result for "${query}" via ${contract.searchResult}`);
  }

  await page.evaluate((selector) => {
    const input = document.querySelector(selector);
    if (!input) return;
    input.value = '';
    input.dispatchEvent(new Event('input', { bubbles: true }));
  }, contract.searchInput);
  await sleep(100);

  if (contract.searchClose) {
    await clickFirst(page, contract.searchClose, `${theme} search close`);
    await sleep(350);
  }
}

async function auditReportedHomeFeatures(page, theme) {
  if (!['orbit', 'velora', 'crumb'].includes(theme)) return;

  // The story rail is filled by an async fetch of the stories endpoint and stays `hidden` until it answers: wait for it
  // (up to 25 s: the runner is shared) instead of racing the request — a slow answer failed velora's audit now and then on the Herd sites.
  await page.waitForFunction((sel) => {
    const el = document.querySelector(sel);
    return el && !el.hidden && el.getBoundingClientRect().height > 0;
  }, { timeout: 25000 }, theme === 'crumb' ? '#reelRail' : '#storyRail').catch(() => {});

  const audit = await page.evaluate((currentTheme) => {
    const visible = (selector) => {
      const element = document.querySelector(selector);
      if (!element) return false;
      const style = getComputedStyle(element);
      const rect = element.getBoundingClientRect();
      return !element.hidden && style.display !== 'none' && style.visibility !== 'hidden' &&
        rect.width > 0 && rect.height > 0;
    };
    const bannerSelector = currentTheme === 'crumb'
      ? '#crumbBanners a[href]'
      : currentTheme === 'orbit'
        ? '#promoTrack a[href]'
        : '#bannerTrack a[href]';
    return {
      language: visible(currentTheme === 'crumb' ? '#homeLangBtn' : '#langBtn'),
      stories: visible(currentTheme === 'crumb' ? '#reelRail' : '#storyRail'),
      offer: visible(bannerSelector),
      orderTypes: document.querySelectorAll('.home-order-types [data-ot]').length,
      // crumb keeps its sign-in entry on the identity card of the Profile view (not the home
      // view), so the home audit checks it is rendered there rather than visible on home.
      account: currentTheme !== 'crumb' || Boolean(document.querySelector('[data-view="profile"] #crumbAuthBtn')),
      guestOrderLookup: currentTheme !== 'crumb' ||
        Boolean(document.querySelector('#lookupInput') && document.querySelector('#lookupBtn')),
      address: currentTheme === 'orbit'
        ? visible('#addrBtn')
        : currentTheme === 'velora'
          // A multi-branch store shows the branch switcher in place of the address link.
          ? (visible('.home-address') || visible('.home-branch'))
          : visible('#locBtn'),
      categoryButtons: document.querySelectorAll('[data-cat]').length,
    };
  }, theme);

  if (!audit.language || !audit.stories || !audit.offer || !audit.orderTypes ||
      !audit.account || !audit.guestOrderLookup || !audit.address || !audit.categoryButtons) {
    throw new Error(`${theme} reported-home audit failed ${JSON.stringify(audit)}`);
  }
}

async function auditReportedProductFeatures(page, theme) {
  if (!['orbit', 'velora', 'crumb'].includes(theme)) return;

  // The story rail is filled by an async fetch of the stories endpoint and stays `hidden` until it answers: wait for it
  // (up to 25 s: the runner is shared) instead of racing the request — a slow answer failed velora's audit now and then on the Herd sites.
  await page.waitForFunction((sel) => {
    const el = document.querySelector(sel);
    return el && !el.hidden && el.getBoundingClientRect().height > 0;
  }, { timeout: 25000 }, theme === 'crumb' ? '#reelRail' : '#storyRail').catch(() => {});

  const audit = await page.evaluate((currentTheme) => {
    const text = document.body.innerText;
    const back = Array.from(document.querySelectorAll('button, a')).some((element) =>
      /back to menu|back/i.test((element.textContent || '').trim()) &&
      element.getBoundingClientRect().width > 0
    );
    const reviews = /reviews?/i.test(text);
    let modifierPreserved = true;
    if (currentTheme === 'crumb') {
      const extra = document.querySelector('#pExtras [data-ex]');
      const options = Array.from(document.querySelectorAll('#pGroups [data-group]'));
      if (extra && options.length > 1) {
        extra.click();
        options[1].click();
        modifierPreserved = extra.classList.contains('is-on') ||
          Boolean(document.querySelector('#pExtras [data-ex].is-on'));
      }
    }
    return { back, reviews, modifierPreserved };
  }, theme);

  if (!audit.back || !audit.reviews || !audit.modifierPreserved) {
    throw new Error(`${theme} reported-product audit failed ${JSON.stringify(audit)}`);
  }
}

async function auditCheckout(page, contract, theme) {
  const audit = await page.evaluate((cfg) => {
    const visible = (element) => {
      if (!element) return false;
      const style = getComputedStyle(element);
      const rect = element.getBoundingClientRect();
      return !element.hidden && style.display !== 'none' && style.visibility !== 'hidden' &&
        rect.width > 0 && rect.height > 0;
    };
    const scope = document.querySelector(cfg.checkoutState);
    const action = document.querySelector(cfg.finalAction);
    const totals = Array.from(document.querySelectorAll(cfg.total)).filter(visible);
    const controls = scope
      ? Array.from(scope.querySelectorAll('input, select, textarea, button')).filter(visible)
      : [];
    const contact = controls.filter((element) =>
      /name|phone|email|address/i.test([
        element.getAttribute('name'),
        element.id,
        element.getAttribute('autocomplete'),
        element.getAttribute('placeholder'),
      ].filter(Boolean).join(' '))
    );
    const authGate = controls.filter((element) =>
      /login|register|sign in/i.test(`${element.id} ${element.textContent || ''}`)
    );
    const fulfillment = Array.from(document.querySelectorAll(
      '[data-ot], [name="ordering-type"], [name="order_type"], #orderTypeSeg, #cvOrderTypeSeg'
    ));
    const payment = Array.from(document.querySelectorAll(
      '[data-pm], [data-paytile], [name="payment_method"], [name="payment-method"]'
    ));

    // Defense in depth: even if a future test mistakenly activates the CTA,
    // no form submission is allowed in this non-destructive journey.
    document.addEventListener('submit', (event) => {
      event.preventDefault();
      event.stopImmediatePropagation();
    }, true);

    return {
      actionVisible: visible(action),
      actionDisabled: Boolean(action && action.disabled),
      actionText: (action?.textContent || '').replace(/\s+/g, ' ').trim(),
      totalVisible: totals.some((element) => (element.textContent || '').trim() !== ''),
      totalText: totals.map((element) => (element.textContent || '').replace(/\s+/g, ' ').trim()).filter(Boolean),
      visibleControls: controls.length,
      contactControls: contact.length,
      authGates: authGate.length,
      fulfillmentSignals: fulfillment.length,
      paymentSignals: payment.length,
      actionInViewport: Boolean(action && (() => {
        const rect = action.getBoundingClientRect();
        return rect.left >= -1 && rect.right <= window.innerWidth + 1;
      })()),
      horizontalOverflow: Math.max(
        document.documentElement.scrollWidth,
        document.body?.scrollWidth || 0
      ) - window.innerWidth,
    };
  }, contract);

  if (!audit.actionVisible || audit.actionDisabled || !audit.actionText) {
    throw new Error(`${theme} checkout audit: invalid final action ${JSON.stringify(audit)}`);
  }
  if (!audit.totalVisible) {
    throw new Error(`${theme} checkout audit: no rendered total ${JSON.stringify(audit)}`);
  }
  if (audit.visibleControls < 2) {
    throw new Error(`${theme} checkout audit: fewer than two visible controls ${JSON.stringify(audit)}`);
  }
  if (!audit.fulfillmentSignals) {
    throw new Error(`${theme} checkout audit: no fulfillment controls ${JSON.stringify(audit)}`);
  }
  if (!audit.contactControls && !audit.authGates) {
    throw new Error(`${theme} checkout audit: neither contact fields nor auth gate ${JSON.stringify(audit)}`);
  }
  if (process.env.E2E_RETAINED_MOBILE === '1' && !audit.actionInViewport) {
    throw new Error(`${theme} mobile checkout audit: final action extends outside viewport ${JSON.stringify(audit)}`);
  }
  if (process.env.E2E_RETAINED_MOBILE === '1' && audit.horizontalOverflow > 1) {
    throw new Error(`${theme} mobile checkout audit: ${audit.horizontalOverflow}px horizontal overflow ${JSON.stringify(audit)}`);
  }

  return audit;
}

module.exports = async function retainedTemplateCsp({ page, cfg, log, shot, sleep, clearServiceWorkers }) {
  const requested = (process.env.E2E_RETAINED_TEMPLATES || Object.keys(THEMES).join(','))
    .split(',').map((value) => value.trim()).filter(Boolean);
  const requireCsp = process.env.E2E_REQUIRE_CSP === '1';
  const mobile = process.env.E2E_RETAINED_MOBILE === '1';
  const wide = process.env.E2E_RETAINED_WIDE === '1';
  const failures = [];

  if (mobile) await page.setViewport({ width: 390, height: 844, deviceScaleFactor: 1 });
  else if (wide) await page.setViewport({ width: 1600, height: 1000, deviceScaleFactor: 1 });

  for (const theme of requested) {
    const contract = THEMES[theme];
    if (!contract) {
      failures.push(`${theme}: unknown retained template`);
      continue;
    }

    const cspErrors = [];
    const pageErrors = [];
    const onConsole = (message) => {
      const text = message.text();
      // Report-only environments intentionally report third-party/widget
      // findings. Strict CSP validation is a separate E2E_REQUIRE_CSP=1 run.
      if (requireCsp && /Content Security Policy|Refused to/i.test(text)) cspErrors.push(text);
    };
    const onPageError = (error) => pageErrors.push(error.message);
    page.on('console', onConsole);
    page.on('pageerror', onPageError);

    try {
      const url = `${cfg.baseUrl}/en/${theme}-demo?e2e_csp=${Date.now()}`;
      const response = await page.goto(url, { waitUntil: 'networkidle2' });
      if (!response || response.status() >= 400) {
        throw new Error(`HTTP ${response ? response.status() : 'no response'}`);
      }

      await clearServiceWorkers(page);
      await page.evaluate(async () => {
        localStorage.clear();
        sessionStorage.clear();
        if ('caches' in window) {
          const keys = await caches.keys();
          await Promise.all(keys.map((key) => caches.delete(key)));
        }
      });
      const reloadResponse = await page.reload({ waitUntil: 'networkidle2' });

      const header = (reloadResponse || response).headers()['content-security-policy'] || '';
      if (requireCsp && !header.includes("script-src-attr 'none'")) {
        throw new Error('enforcing Content-Security-Policy header is missing script-src-attr none');
      }

      const shell = await page.evaluate((strict) => {
        const handlers = Array.from(document.querySelectorAll('[onclick],[onchange],[onerror],[onload],[onfocus],[onblur]'))
          // WATI injects its own onerror while CSP is report-only. Enforcement
          // removes WATI entirely, so strict runs do not get this exception.
          .filter((element) => strict || !element.closest('[class*="wa-chat"]'));
        return {
          lang: document.documentElement.lang,
          dir: document.documentElement.dir,
          main: Boolean(document.querySelector('main, #main-content')),
          inlineHandlers: handlers.length,
          handlerSamples: handlers.slice(0, 5).map((element) => element.outerHTML.slice(0, 240)),
        };
      }, requireCsp);
      if (shell.lang !== 'en' || shell.dir !== 'ltr' || !shell.main) {
        throw new Error(`invalid shell ${JSON.stringify(shell)}`);
      }
      if (shell.inlineHandlers !== 0) {
        throw new Error(`${shell.inlineHandlers} rendered inline event handlers: ${shell.handlerSamples.join(' | ')}`);
      }

      await page.evaluate(() => {
        document.querySelectorAll(
          '#splashSkip, #mbSplashSkip, #obSkip, [id$="SplashSkip"], [id$="splashSkip"], ' +
          '.splash-skip, .ob-skip, [data-csp-action="splash-skip"]'
        ).forEach((skip) => skip.click());
      });
      await sleep(450);

      await auditReportedHomeFeatures(page, theme);
      await exerciseSearch(page, contract, theme, sleep);
      await clickFirst(page, contract.product || '[data-open]', `${theme} product`);
      await sleep(350);
      await auditReportedProductFeatures(page, theme);
      await page.evaluate(() => {
        document.querySelectorAll('.opt-group').forEach((group) => {
          const required = group.querySelector('.opt-req:not(.optional)');
          if (required) group.querySelectorAll('.opt[data-opt]').forEach((option) => option.click());
        });
        document.querySelectorAll('#pGroups .optcard').forEach((group) => {
          if (group.querySelector('.optcard-tag.req')) {
            group.querySelectorAll('[data-opt]').forEach((option) => option.click());
          }
        });
        const doughGroups = new Map();
        document.querySelectorAll('#iOptWrap [data-mod]').forEach((option) => {
          if (!doughGroups.has(option.dataset.mod)) doughGroups.set(option.dataset.mod, option);
        });
        doughGroups.forEach((option) => option.click());
      });
      await clickFirst(page, contract.add, `${theme} add-to-cart`);
      await sleep(400);

      await clickFirst(page, contract.cart, `${theme} cart`);
      await sleep(350);
      if (contract.checkout) {
        await satisfyMinimumOrder(page, contract.checkout, `${theme} checkout entry`);
        await clickFirst(page, contract.checkout, `${theme} checkout entry`);
        await sleep(350);
      }

      const checkoutVisible = await page.evaluate((selector) => {
        const element = document.querySelector(selector);
        if (!element) return false;
        const style = getComputedStyle(element);
        const rect = element.getBoundingClientRect();
        return !element.hidden && style.display !== 'none' &&
          style.visibility !== 'hidden' && rect.width > 0 && rect.height > 0;
      }, contract.checkoutState);
      if (!checkoutVisible) throw new Error(`checkout state not visible: ${contract.checkoutState}`);
      const checkoutAudit = await auditCheckout(page, contract, theme);
      if (wide && theme === 'orbit' && checkoutAudit.horizontalOverflow > 1) {
        throw new Error(`orbit wide audit: ${checkoutAudit.horizontalOverflow}px horizontal overflow`);
      }

      const finalHandlers = await page.evaluate((strict) =>
        Array.from(document.querySelectorAll('[onclick],[onchange],[onerror],[onload],[onfocus],[onblur]'))
          .filter((element) => strict || !element.closest('[class*="wa-chat"]')).length
      , requireCsp);
      if (finalHandlers !== 0) throw new Error(`${finalHandlers} runtime-generated inline event handlers`);
      if (cspErrors.length) throw new Error(`CSP violations: ${cspErrors.join(' | ')}`);
      if (pageErrors.length) throw new Error(`page errors: ${pageErrors.join(' | ')}`);

      log(
        `retained-csp ${theme}: search → product → cart → checkout audit OK ` +
        `(viewport=${mobile ? '390x844' : wide ? '1600x1000' : 'default'}, controls=${checkoutAudit.visibleControls}, ` +
        `payment=${checkoutAudit.paymentSignals})`
      );
    } catch (error) {
      const diagnostics = [
        cspErrors.length ? `CSP: ${cspErrors.join(' | ')}` : '',
        pageErrors.length ? `page errors: ${pageErrors.join(' | ')}` : '',
      ].filter(Boolean).join(' || ');
      failures.push(`${theme}: ${error.message}${diagnostics ? ` || ${diagnostics}` : ''}`);
      await shot(page, `retained-csp-${theme}-FAIL`);
    } finally {
      page.off('console', onConsole);
      page.off('pageerror', onPageError);
    }
  }

  if (failures.length) {
    throw new Error(`retained template failures:\n${failures.join('\n')}`);
  }
};
