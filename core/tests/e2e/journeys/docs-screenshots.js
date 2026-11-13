// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * D4-7 — docs screenshot pipeline.
 *
 * Logs in as the seeded owner and captures the help-center screens (getting-started
 * + ~28 CONTEXT_MAP guides), once
 * per locale (E2E_DOCS_LOCALES, default `en,ar`): English at `assets/docs/img/*.png`
 * and each other locale under `assets/docs/img/<locale>/*.png`, so the Arabic
 * guides show the Arabic (RTL) dashboard. These are repo-root static assets
 * served at `/assets/docs/img/...`, committed and referenced by the matching
 * guides in `core/resources/docs/users/` (and `.../ar/`); the nightly e2e job
 * regenerates them and uploads the fresh set as a `docs-screenshots` artifact so
 * a UI change is easy to re-baseline. See core/tests/e2e/README.md.
 *
 * Unlike the smoke journeys this one writes OUTSIDE __screenshots__ (which is
 * gitignored) — the PNGs are product content, not triage output. It also uses a
 * desktop viewport: docs illustrate the full-width dashboard, not the 420px
 * mobile shell the smoke journeys assert against.
 *
 * Data: ThemePackDemoSeeder (owner themepack-owner@demo.test, store
 * themepack-demo with seeded categories + menu items so POS and the menu
 * workspace render with content).
 *
 * Opt-in: registered only when E2E_RUN_DOCS=1 (see run.js). The captured
 * screens include the owner-accessible POS register (auth:staff,web).
 */

const fs = require('fs');
const path = require('path');

// core/tests/e2e/journeys -> repo root
const REPO_ROOT = path.resolve(__dirname, '..', '..', '..', '..');
const DOCS_IMG_DIR = path.join(REPO_ROOT, 'assets', 'docs', 'img');

// Square viewport at 2× device scale: the output is the whole 1500×1500 viewport
// (no clip), so the FULL width is shown — sidebar + content, nothing cut off
// horizontally — while the square keeps it focused on the top of the screen
// rather than an endless scroll. 2× keeps the text retina-sharp when the doc
// scales the image down (the earlier blurriness was 1× at a too-wide size).
const SIZE = 1500;
const RENDER = { width: SIZE, height: SIZE, deviceScaleFactor: 2 };

// Where a locale's screenshots live: English at the root (assets/docs/img), each
// other locale in its own subdir (assets/docs/img/ar) — mirrors the docs/ar/
// overlay convention, so the ar guides reference /assets/docs/img/ar/<slug>.png.
function localeDir(locale) {
  return locale === 'en' ? DOCS_IMG_DIR : path.join(DOCS_IMG_DIR, locale);
}

async function captureTo(page, dir, slug, log) {
  fs.mkdirSync(dir, { recursive: true });
  const file = path.join(dir, `${slug}.png`);

  // Scrollbars would render as a grey strip on the trailing edge of the capture.
  await page.addStyleTag({ content: '::-webkit-scrollbar{display:none!important}' }).catch(() => {});
  // Un-hinted text looks broken in docs; wait for webfonts before shooting.
  await page.evaluate(() => (document.fonts ? document.fonts.ready : Promise.resolve())).catch(() => {});

  // Capture the WHOLE square viewport — full width (nothing cut horizontally),
  // top-anchored (focused, not an endless scroll), crisp at 2×.
  const pageDir = await page.evaluate(() => document.documentElement.getAttribute('dir') || 'ltr');
  await page.screenshot({ path: file, clip: { x: 0, y: 0, width: SIZE, height: SIZE } });
  log(`docs-screenshots: captured ${slug} (${pageDir}, ${SIZE}²@2x) -> ${path.relative(REPO_ROOT, file)}`);
  return file;
}

// Upsell / access markers that mean the page rendered but shows a plan wall or a
// 403 instead of the real feature — a shot we must NOT ship. Sourced from
// templates/lamar/user/feature-locked.blade.php + the reseller upsell overlay.
const GATED_TEXT =
  /not included in your current plan|This feature is managed by your provider|Upgrade to unlock|upgrade your plan|You are not authorized|403 ?\| ?Forbidden|This action is unauthorized/i;

async function gotoOk(page, cfg, urlPath) {
  const res = await page.goto(`${cfg.baseUrl}${urlPath}`, { waitUntil: 'networkidle2' });
  const status = res ? res.status() : 0;
  const landed = new URL(page.url()).pathname;
  if (status >= 500) {
    throw new Error(`docs-screenshots: ${urlPath} returned ${status}`);
  }
  if (/(^|\/)login$/.test(landed)) {
    throw new Error(`docs-screenshots: ${urlPath} redirected to ${landed} (owner session lost or access denied)`);
  }
  const body = await page.evaluate(() => (document.body?.innerText || '').slice(0, 3000));
  if (/Whoops, looks like something went wrong|Illuminate\\|Fatal error/i.test(body)) {
    throw new Error(`docs-screenshots: ${urlPath} rendered an exception page`);
  }
  // Feature-gated routes redirect to feature-locked/{key} or render the plan wall
  // inline; a wrong/feature-aborted path renders the styled 404 (status 404, body
  // "Page Not Found"). All are "no shippable screen" — the caller decides whether
  // to skip (gated/soft shots) or fail (core shots that must always work).
  const notFound = status === 404 || /Page Not Found|(^|\D)404(\D|$)/.test(body.slice(0, 400));
  const gated = notFound || /(^|\/)feature-locked(\/|$)/.test(landed) || GATED_TEXT.test(body);
  return { landed, gated };
}

/**
 * Find a restaurant's numeric id from the Restaurants list. When a public slug
 * is given, prefer the card that links to that storefront (the showcase owner
 * has many demo stores); otherwise the first /stores/{id} anchor.
 */
async function resolveRestaurantId(page, cfg, slug = null) {
  await gotoOk(page, cfg, '/stores');
  const id = await page.evaluate((wantSlug) => {
    const idOf = (root) => {
      for (const a of root.querySelectorAll('a[href]')) {
        const m = (a.getAttribute('href') || '').match(/stores\/(\d+)(?:\/|$|\?)/);
        if (m) return m[1];
      }
      return null;
    };
    if (wantSlug) {
      const pub = Array.from(document.querySelectorAll('a[href]'))
        .find((a) => (a.getAttribute('href') || '').replace(/\/$/, '').endsWith('/' + wantSlug));
      let node = pub;
      for (let up = 0; node && up < 6; up++, node = node.parentElement) {
        const found = idOf(node);
        if (found) return found;
      }
    }
    return idOf(document);
  }, slug);
  if (!id) {
    throw new Error('docs-screenshots: could not resolve a restaurant id from /stores');
  }
  return id;
}

/** Log in via the real form (any prior session is replaced). */
async function loginAs(page, cfg, email, pass, label) {
  // Drop any prior actor's session first (dashboard logout is POST-only, so the
  // clean cross-actor reset is deleting the session cookies, as role-pages does).
  const cookies = await page.cookies().catch(() => []);
  if (cookies.length) {
    await page.deleteCookie(...cookies).catch(() => {});
  }
  await page.goto(`${cfg.baseUrl}/login`, { waitUntil: 'networkidle2' });
  await page.waitForSelector('#username, input[name="username"]', { timeout: cfg.navTimeout });
  await page.type('#username', email);
  await page.type('#password', pass);
  await Promise.all([
    page.click('button[type="submit"], input[type="submit"]'),
    page.waitForNavigation({ waitUntil: 'networkidle2', timeout: cfg.navTimeout }).catch(() => {}),
  ]);
  if (new URL(page.url()).pathname === '/login') {
    throw new Error(`docs-screenshots: ${label} login was rejected (still on /login)`);
  }
}

/**
 * White-label safety: the PNGs are shared static assets, but a reseller's
 * rebranded Help Center must not show the PLATFORM brand inside every
 * screenshot. Before dashboard-chrome shots, swap the brand logo for a neutral
 * tile and rewrite the brand wordmark to "Demo" everywhere it appears as text.
 */
const NEUTRAL_LOGO =
  'data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><rect width="48" height="48" rx="10" fill="%237c3aed"/><text x="24" y="31" font-family="sans-serif" font-size="20" font-weight="700" fill="white" text-anchor="middle">D</text></svg>';

async function scrubBrand(page) {
  await page.evaluate((logo) => {
    const BRAND = 'Demo';
    // Brand NAME: the sidebar logo has no text, but its <img alt> is the brand
    // title (blade `alt="{{ $appName }}"`); fall back to og:site_name.
    const brandImg = document.querySelector('.app-navbar-brand img[alt], .navbar-brand img[alt]');
    const ogName = document.querySelector('meta[property="og:site_name"]');
    const name = ((brandImg && brandImg.getAttribute('alt')) || (ogName && ogName.content) || '').trim();

    // Brand LOGO: swap every mark (light/dark variants + avatar initials).
    document.querySelectorAll('.app-navbar-brand img, .navbar-brand img').forEach((img) => {
      img.src = logo; img.srcset = ''; img.alt = BRAND;
    });
    document.querySelectorAll('.app-navbar-brand .avatar-initials').forEach((el) => {
      el.textContent = 'D';
    });

    // Brand WORDMARK: replace the name in every text node (topbar header etc.).
    if (name && name.length > 2) {
      const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
      let node;
      while ((node = walker.nextNode())) {
        if (node.nodeValue && node.nodeValue.includes(name)) {
          node.nodeValue = node.nodeValue.split(name).join(BRAND);
        }
      }
      document.title = document.title.split(name).join(BRAND);
    }
  }, NEUTRAL_LOGO).catch(() => {});
}

module.exports = async function docsScreenshots({ page, cfg, log, sleep }) {
  await page.setViewport(RENDER);

  // Capture one set per locale so each guide shows its own language. The app uses
  // URL-prefix localization with hideDefaultLocaleInURL: an UNPREFIXED dashboard
  // URL renders the site default (DEFAULT_LANGUAGE, ar/RTL on the dev box), so we
  // request `/{locale}/...`. Prefixing is safe even when the locale IS the
  // default — mcamara redirects the redundant prefix to the unprefixed URL.
  const locales = (process.env.E2E_DOCS_LOCALES || 'en,ar')
    .split(',').map((s) => s.trim()).filter(Boolean);

  // Rich demo store for the menu / QR / theme-store / POS screens: the showcase
  // flagship (ThemeShowcaseSeeder) ships 100+ menu items with real photography,
  // so those screens look like a working restaurant rather than a bare fixture.
  const richEmail = process.env.E2E_DOCS_RICH_EMAIL || 'showcase-demo@example.com';
  const richPass = process.env.E2E_DOCS_RICH_PASS || 'showcase-demo-pass';
  const richSlug = process.env.E2E_DOCS_RICH_SLUG || 'crave-demo';

  // Batch control: E2E_DOCS_ONLY=slug,slug limits the run to those doc slugs
  // (keeps a single run under ~5min and lets a skipped/failed slug be re-shot in
  // isolation). Unset = shoot everything. Matches on the doc slug, not the URL.
  const only = (process.env.E2E_DOCS_ONLY || '')
    .split(',').map((s) => s.trim()).filter(Boolean);
  const wanted = (slug) => only.length === 0 || only.includes(slug);
  const skipped = [];

  let total = 0;
  // gated:false → core screen that must always render (throws on failure).
  // gated:true  → feature/plan-gated screen: if it lands on an upsell/403 wall we
  //               log a SKIP and ship no image rather than a "buy this" screenshot.
  // soft:true   → exploratory route (the EXTRA guides): swallow ANY failure —
  //               a 500, a login/redirect, a bad path — into a logged skip so one
  //               shaky route can't fail the whole run. Use only where a missing
  //               screenshot is acceptable (the doc keeps its text).
  // prep:fn      → run after the page renders and before the capture (e.g. open a
  //               modal, expand a panel) so a sub-feature that lives inside a page
  //               gets its own distinct shot. Best-effort — a throw is ignored.
  const shoot = async (dir, slug, urlPath, { scrub = true, gated = false, soft = false, prep = null } = {}) => {
    if (!wanted(slug)) return;
    try {
      const { gated: walled } = await gotoOk(page, cfg, urlPath);
      if (walled) {
        if (!gated && !soft) {
          throw new Error(`docs-screenshots: ${urlPath} hit an upsell/403 wall unexpectedly`);
        }
        log(`docs-screenshots: SKIP ${slug} (${urlPath}) — feature-gated/upsell wall`);
        skipped.push(slug);
        return;
      }
      // Give client-rendered widgets (charts, POS tiles, QR canvas) a beat to paint.
      await sleep(1200);
      if (prep) {
        await prep().catch(() => {});
        await sleep(700); // let whatever prep opened settle/animate in
      }
      if (scrub) {
        await scrubBrand(page);
      }
      await captureTo(page, dir, slug, log);
      total++;
    } catch (e) {
      if (!soft) {
        throw e;
      }
      log(`docs-screenshots: SKIP ${slug} (${urlPath}) — soft skip: ${e.message}`);
      skipped.push(slug);
    }
  };

  // The ~28 CONTEXT_MAP guides — all dashboard chrome (scrub the brand). Most are
  // GLOBAL top-level paths (the dashboard is store-scoped by session); whatsapp is
  // the one restaurant-scoped path. url() takes (rid, locale). Every shot is
  // gated:true so a plan-locked screen self-skips instead of shipping an upsell
  // wall. The DATA screens (dashboard/orders/analytics/customers/subscription)
  // read best on the themepack store, which DocsScreenshotSeeder tops up with two
  // weeks of paid orders → living KPIs; the FEATURE screens (gift-cards, kds,
  // directory …) need the Pro-plan showcase store to be unlocked at all. So we
  // shoot the whole set on themepack first, then re-shoot whatever themepack
  // gated on the showcase flagship (Phase B).
  const DASH_GUIDES = [
    ['dashboard-overview', (r, l) => `/${l}/dashboard`],
    ['orders', (r, l) => `/${l}/orders`],
    ['staff', (r, l) => `/${l}/staff`],
    ['kds', (r, l) => `/${l}/kds`],
    ['plans-billing', (r, l) => `/${l}/subscription`],
    ['inventory', (r, l) => `/${l}/inventory`],
    ['coupons', (r, l) => `/${l}/coupons`],
    ['analytics', (r, l) => `/${l}/analytics`],
    ['whatsapp', (r, l) => `/${l}/stores/${r}/whatsapp-settings`],
    ['delivery', (r, l) => `/${l}/dispatch`],
    ['branches', (r, l) => `/${l}/branches`],
    ['wallet', (r, l) => `/${l}/wallet`],
    ['customers', (r, l) => `/${l}/customers`],
    ['reservations', (r, l) => `/${l}/reservations`],
    ['gift-cards', (r, l) => `/${l}/gift-cards`],
    ['food-safety', (r, l) => `/${l}/food-safety`],
    ['directory', (r, l) => `/${l}/directory`],
    ['disputes', (r, l) => `/${l}/disputes`],
    ['catering', (r, l) => `/${l}/catering`],
    ['reviews-feedback', (r, l) => `/${l}/customer-feedback`],
    ['accounting', (r, l) => `/${l}/accounting`],
    ['apps-integrations', (r, l) => `/${l}/aggregators`],
    ['marketing-automation', (r, l) => `/${l}/automations`],
  ];
  const guideUrl = Object.fromEntries(DASH_GUIDES);

  // EXTRA guides — the rest of the users/ corpus that maps to a concrete
  // owner-accessible dashboard page (beyond CONTEXT_MAP). All `soft` (see shoot):
  // a route that 500s, redirects, or walls just drops its screenshot and the doc
  // keeps its text — no run failure. Global unless the url() uses `r` (restaurant
  // scoped; those use singular `restaurant/` vs the CONTEXT_MAP plural — verified
  // against route:list). Re-shot on the flagship in Phase B if themepack skips.
  const EXTRA_GUIDES = [
    ['campaigns', (r, l) => `/${l}/campaigns`],
    ['loyalty', (r, l) => `/${l}/restaurant/${r}/loyalty`],
    ['referrals', (r, l) => `/${l}/referral`],
    ['banners', (r, l) => `/${l}/banners`],
    ['media-library', (r, l) => `/${l}/media`],
    ['waitlist', (r, l) => `/${l}/restaurant/${r}/waitlist`],
    ['floor-map', (r, l) => `/${l}/restaurant/${r}/floor-map`],
    ['activity-log', (r, l) => `/${l}/activity-log`],
    ['payroll', (r, l) => `/${l}/payroll-runs/labor/dashboard`],
    ['backup-export', (r, l) => `/${l}/backup`],
    ['digital-signage', (r, l) => `/${l}/signage`],
    ['waiter-mode', (r, l) => `/${l}/waiter-mode`],
    ['team-chat', (r, l) => `/${l}/team`],
    ['marketplace-listing', (r, l) => `/${l}/stores/${r}/marketplace-settings`],
    ['menu-studio', (r, l) => `/${l}/studio`],
    ['connect-ai', (r, l) => `/${l}/connect-ai`],
    ['printing', (r, l) => `/${l}/printing`],
    ['fiscalization', (r, l) => `/${l}/fiscal`],
    ['custom-domain', (r, l) => `/${l}/stores/${r}/domains`],
    ['translations', (r, l) => `/${l}/stores/${r}/translations`],
    ['staff-scheduling', (r, l) => `/${l}/rota`],
    ['api-tokens', (r, l) => `/${l}/developers`],
    ['ownership-transfer', (r, l) => `/${l}/transfer`],
    ['kiosk', (r, l) => `/${l}/kiosk-settings`],
    ['modifiers', (r, l) => `/${l}/modifiers`],
    ['ai-agents', (r, l) => `/${l}/ai-ordering`],
    ['menu-library', (r, l) => `/${l}/platform-catalog`],
    ['support-and-feedback', (r, l) => `/${l}/help`],
    ['hr-employees', (r, l) => `/${l}/leave`],
    // Hotel routes use the app's `app.locale` (session-locale) middleware,
    // NOT the LaravelLocalization URL-prefix group — so `/{locale}/hotel/stays`
    // 404s. Shoot it UNPREFIXED: the Locale middleware reads session('locale'),
    // which the preceding `/{locale}/…` shots in this same loop have already set,
    // so /hotel/stays renders EN in the en pass and AR (rtl) in the ar pass.
    ['hotel-folios', () => '/hotel/stays'],
  ];
  const extraUrl = Object.fromEntries(EXTRA_GUIDES);

  // ── Phase A — ThemePackDemoSeeder owner. Single-store owner, so every GLOBAL
  // dashboard path renders this one store (no store-pinning needed), and it
  // carries the KPI history + the "Get started" checklist the getting-started
  // guide documents. Plan-gated feature screens self-skip here → re-shot in B.
  await loginAs(page, cfg, cfg.ownerEmail, cfg.ownerPass, 'themepack owner');
  const tpRid = await resolveRestaurantId(page, cfg);
  log(`docs-screenshots: themepack store -> restaurant id ${tpRid}`);
  for (const locale of locales) {
    const dir = localeDir(locale);
    log(`docs-screenshots: [themepack] locale = ${locale} -> ${path.relative(REPO_ROOT, dir)}`);
    await shoot(dir, 'getting-started', `/${locale}/dashboard`);
    for (const [slug, url] of DASH_GUIDES) {
      await shoot(dir, slug, url(tpRid, locale), { gated: true });
    }
    for (const [slug, url] of EXTRA_GUIDES) {
      // hotel-folios shoots the UNPREFIXED /hotel/stays (non-localized route); its
      // language comes from session('locale'). Prime it by visiting the localized
      // dashboard first, so this works even when EXTRA is run in isolation
      // (E2E_DOCS_ONLY) with no earlier /{locale}/ shot to set the session.
      if (slug === 'hotel-folios' && wanted(slug)) {
        await gotoOk(page, cfg, `/${locale}/dashboard`).catch(() => {});
      }
      await shoot(dir, slug, url(tpRid, locale), { gated: true, soft: true });
    }
  }
  // Slugs the bare themepack plan walled off — recover them from the Pro flagship.
  const gatedOnThemepack = Array.from(new Set(skipped));

  // ── Phase B — Showcase owner: the photographed Pro-plan flagship. Its menu is
  // the 100+ item catalogue the menu / QR / theme-store / POS screens illustrate,
  // and its plan unlocks the feature screens themepack gated. Pin it as the active
  // dashboard store first: POS ?store= runs PosController::context, which
  // also sets the dashboard store session, so the subsequent GLOBAL feature paths
  // render the flagship rather than the owner's default store.
  await loginAs(page, cfg, richEmail, richPass, 'showcase owner');
  const rid = await resolveRestaurantId(page, cfg, richSlug);
  log(`docs-screenshots: rich store ${richSlug} -> restaurant id ${rid}`);
  await gotoOk(page, cfg, `/pos?store=${rid}`); // pin flagship as active store

  for (const locale of locales) {
    const dir = localeDir(locale);
    log(`docs-screenshots: [flagship] locale = ${locale} -> ${path.relative(REPO_ROOT, dir)}`);
    await shoot(dir, 'menu-items', `/${locale}/stores/${rid}/menu`);
    await shoot(dir, 'qr-codes', `/${locale}/stores/${rid}/qr-designer`);
    await shoot(dir, 'themes', `/${locale}/store/themes`);
    // menu-categories + menu-import-export have no page of their own — both live
    // ON the menu workspace. Shoot that page for categories, and open the Import
    // CSV modal (the blade's `$dispatch('menu-import')`) for a distinct
    // import/export shot. soft: a theme without the modal just keeps the page.
    const menuUrl = `/${locale}/stores/${rid}/menu`;
    await shoot(dir, 'menu-categories', menuUrl, { gated: true, soft: true });
    await shoot(dir, 'menu-import-export', menuUrl, {
      gated: true,
      soft: true,
      prep: () => page.evaluate(() => window.dispatchEvent(new CustomEvent('menu-import'))),
    });
    // ai-menu-generator: the "AI generate" modal on the same workspace
    // (blade `$dispatch('menu-ai')`).
    await shoot(dir, 'ai-menu-generator', menuUrl, {
      gated: true,
      soft: true,
      prep: () => page.evaluate(() => window.dispatchEvent(new CustomEvent('menu-ai'))),
    });
    // Re-shoot only the guides themepack couldn't render (plan-gated there).
    // CONTEXT_MAP guides are hard-gated; EXTRA guides stay soft (a route that
    // walls/500s on the flagship too just keeps the doc text).
    for (const slug of gatedOnThemepack) {
      const url = guideUrl[slug] || extraUrl[slug];
      if (!url) continue;
      await shoot(dir, slug, url(rid, locale), { gated: true, soft: !guideUrl[slug] });
    }
    // POS chrome ignores the URL locale — PosController::menuLanguages() calls
    // app()->setLocale() from the store's language, picking the `Quick_user_lang_code`
    // cookie when the store has ≥2 menu languages (crave-demo is ar+en). Set that
    // cookie to the target locale so the register renders EN for the EN guide and
    // AR for the AR guide. No platform brand chrome, so nothing to scrub.
    if (locale === 'ar' || locale === 'en') {
      const host = new URL(cfg.baseUrl).hostname;
      await page.setCookie({ name: 'Quick_user_lang_code', value: locale, domain: host, path: '/' }).catch(() => {});
    }
    await shoot(dir, 'pos', `/${locale}/pos?store=${rid}`, { scrub: false });
  }

  log(`docs-screenshots: wrote ${total} screenshots across locales [${locales.join(', ')}]`);
  // A slug walled on themepack (Phase A) AND again on the flagship (Phase B) is
  // in `skipped` for both phases — count ≥ locales*2 means no image ships anywhere
  // and the doc keeps just its text. Recovered slugs appear only for Phase A.
  const counts = skipped.reduce((m, s) => ((m[s] = (m[s] || 0) + 1), m), {});
  const stillGated = gatedOnThemepack.filter((s) => (counts[s] || 0) > locales.length);
  if (stillGated.length) {
    log(`docs-screenshots: STILL GATED after flagship (no image) — ${stillGated.join(', ')}`);
  }
};
