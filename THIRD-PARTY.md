# Third-party components

Every third-party library, font, icon set and media pack bundled in this repository (tracked files under
`assets/`, `core/resources`, `core/plugins`; there is no `core/public`), with version, licence, upstream source and
the verdict for an AGPL-3.0 core.

- **OSS-compatible**: OSI licence compatible with AGPL-3.0 (MIT, BSD, ISC, Apache-2.0, MPL-2.0, OFL-1.1 for fonts). Kept; its licence text must ship with it.
- **removed (unused)**: audited and not loaded anywhere, so the file is no longer in the repository.

No commercial or non-OSI component is bundled; every bundled component listed below carries an OSI-approved licence.
Versions marked "unknown (no banner)" had no header, licence file or version string; the Notes give the best
evidence. "Used by" counts are approximate (`git grep` over views, app code, plugins and assets).

**Own code (not listed as libraries):** `assets/owner-ui`, `assets/owner-app/bridge.*`, `assets/pos/pos-*`, `assets/marketplace/*`,
`assets/marketplace-themes/*/css`, `assets/store_templates/*/{css,js,theme.json,theme-info.txt}` (all say "Author: juwri"),
`assets/store_templates/_shared/js`, `assets/theme-studio`, `assets/liquid-library`, `assets/commerce-runtime`, `assets/brand`,
`assets/global/stories-entries`, `assets/js/{notify-sounds,agent-chat-widget}.js`, `assets/global/pdfjs/render-pdf.js`,
`assets/global/css/external-redirect.css`, `assets/builder/{runtime,templates}` (html-mode builder runtime + template gallery),
`assets/locked-templates/_runtime/{kit,html,editor*,lt-styles.css}`, `assets/locked-templates/starter` (open sample template),
`assets/docs/img` (screenshots), and all of `core/resources` (views, bio css, liquid-themes, liquid-library, docs, fixtures,
studio thumbs). No view inlines minified vendor code: no line in `core/resources` or `core/app` is longer than 3,000 chars.

**Package-managed deps:** `core/composer.json` and `core/package.json`, plus `desktop/`, `kiosk/`, `storefront-starter/` and
`integrations/{make-app,n8n-nodes-juwri,zapier-app}/`. They are not listed below. No `core/public` dir, and no vendored code
at repo root, `mobile/`, `kiosk/`, `driver-app/` or `integrations/`.

**CDN (not bundled, for reference):** Google Fonts CSS (96 ref files), and in
`core/resources/views/store_templates/layout.blade.php` a `popper.js` cdnjs link and a commented-out Font Awesome 5.15.4
cdnjs link.

---

## assets/global

| Library | Version | Licence | Source | Path(s) | Used by | Verdict | Notes |
|---|---|---|---|---|---|---|---|
| jQuery | 3.7.1 | MIT (`assets/global/js/LICENSES/jquery.txt`) | https://jquery.com · npm `jquery@3.7.1` (integrity `sha512-m4avr8yL…kpLfg==`, see `assets/global/js/SOURCE.txt`) | `assets/global/js/jquery.min.js` | 5 | OSS-compatible | Verified byte-identical to the official `dist/jquery.min.js` of the npm release; licence + SOURCE added. |
| jQuery UI | 1.13.3 | MIT (`assets/global/js/LICENSES/jquery-ui.txt`) | https://jqueryui.com · npm `jquery-ui@1.13.3` (integrity `sha512-D2YJfswS…pRwA==`) | `assets/global/js/jquery-ui.min.js` | 1 (`admin/includes/scripts.blade.php`; `admin-ajax.js` uses `.sortable`) | OSS-compatible | The earlier 1.13.2 file was replaced by the official `dist/jquery-ui.min.js` of 1.13.3 (a patch release with the same widget set), unmodified. The unreferenced CSS theme `assets/global/css/jquery-ui/**` (css + 6 png) was deleted. |
| jquery.cookie | 1.4.1 | MIT | https://github.com/carhartl/jquery-cookie | ~~`assets/global/js/jquery.cookie.min.js`~~ | 0 | removed (unused) | Unused plugin. |
| jQuery Form Plugin | 4.3.0 | MIT (dual MIT / LGPL-2.1+; MIT chosen, `assets/global/js/LICENSES/jquery-form.txt`) | https://github.com/jquery-form/form · npm `jquery-form@4.3.0` (integrity `sha512-q3uaVCEW…HHLGnQ==`) | `assets/global/js/jquery.form.js` | 1 (`admin/includes/scripts.blade.php`; `admin-ajax.js` `ajaxSubmit` ×2) | OSS-compatible | The earlier 3.51.0 source was replaced by the official minified `dist/jquery.form.min.js` of 4.3.0, unmodified, served under the old file name so the view does not change. Options used (`url`, `headers`, `dataType`, `success`, `error`) are unchanged in 4.x. |
| jquery.lazyload | unknown (no banner) | unknown (no banner) | likely https://github.com/tuupola/jquery_lazyload | ~~`assets/global/js/jquery.lazyload.min.js`~~ | 0 | removed (unused) | |
| jquery.payment | 3.0.0 | MIT (`assets/global/js/LICENSES/jquery.payment.txt`, © 2014 Stripe) | https://github.com/stripe-archive/jquery.payment · npm `jquery.payment@3.0.0` (integrity `sha512-b02U7bf5…mhOJA==`) | `assets/global/js/jquery.payment.js` | 1 (`owner/billing/gateways/two-checkout.blade.php`: `formatCardNumber`, `formatCardCVC`, `formatCardExpiry`, `cardExpiryVal`) | OSS-compatible (upstream archived; retire when the 2Checkout form is rebuilt) | The earlier CoffeeScript 1.7.1 build was replaced by the official `lib/jquery.payment.min.js` of 3.0.0 (the last release), unmodified, same file name. The four calls behave the same. |
| jQuery UI Touch Punch | 0.2.3 | MIT OR GPL-2.0 | https://github.com/furf/jquery-ui-touch-punch | ~~`assets/global/js/jquery.ui.touch-punch.min.js`~~ | 0 | removed (unused) | |
| JavaScript-MD5 | unknown (no banner) | unknown (no banner) | likely https://github.com/blueimp/JavaScript-MD5 | ~~`assets/global/js/md5.min.js`~~ | 0 | removed (unused) | |
| Tippy.js (global duplicate) | 2.5.2 | MIT | https://atomiks.github.io/tippyjs/ | ~~`assets/global/js/tippy.all.min.js`~~ | 0 | removed (unused duplicate) | |
| Bootstrap | 4.3.1 | MIT | https://getbootstrap.com | ~~`assets/global/bootstrap/{css,js}/*`~~ | 0 | removed (unused) | |
| StPageFlip (page-flip) | 2.0.7 | MIT (`assets/global/page-flip/LICENSE`, © Nodlik) | https://github.com/Nodlik/StPageFlip · npm `page-flip@2.0.7` (integrity `sha512-96lQFUUz…pFxwGA==`) | `assets/global/page-flip/page-flip.browser.js`, `VERSION` | 2 (Folio theme) | OSS-compatible | The `VERSION` file records the source URL and sha256. Byte-identical to the package's `dist/js/page-flip.browser.js`; LICENSE added from the same tarball. |
| PDF.js | 3.11.174 | Apache-2.0 (`assets/global/pdfjs/LICENSE`) | https://github.com/mozilla/pdf.js · npm `pdfjs-dist@3.11.174` (integrity `sha512-TdTZPf1t…fnd8dA==`) | `assets/global/pdfjs/pdf.min.js`, `pdf.worker.min.js` | 2 / 1 (via `render-pdf.js`: 5) | OSS-compatible | Apache licence header present. Both files byte-identical to the package's `build/`; the Apache-2.0 LICENSE added from the same tarball (pdfjs-dist ships no NOTICE file). `render-pdf.js` is own code. |
| Legacy icon-font bundle (Themify Icons, Material Icons, Feather, Line Awesome, Linea, Pe-icon-7-stroke, Simple Line Icons, Weather Icons) | unknown (no banner) | mixed (OFL, Apache-2.0, MIT, Icons8 Good Boy, CC BY 4.0, Pixeden "free with attribution") | legacy icon-font bundle | ~~`assets/global/css/{icons,new-icons}.css`, `assets/global/css/fonts/**`~~ | 0 | removed (unused) | No storefront view, theme script or app string used its classes. The only rule still used, `.sr-only`/`.sr-only-focusable` (layout skip link), now lives in `assets/global/css/ui-icon.css` (own). |
| Feather icon font | unknown (no banner) (css says `?v=1.0`) | MIT (banner in the removed `assets/admin/icons/css/feather-icon.css`) | https://feathericons.com | ~~`assets/admin/icons/{css/feather-icon.css,fonts/feather/*}`~~ | 0 | removed (unused) | The shells use kit SVG glyphs (Tabler, a Feather fork). |
| Notification sounds | n/a | own (juwri) | synthesised (sine partials, exponential decay): `assets/global/audio/generate-sounds.py` (stdlib Python + ffmpeg) for the 21 picker sounds, ffmpeg commands in `assets/global/audio/SOURCES.txt` for the 3 legacy ones | `assets/global/audio/*.mp3`, `message.ogg` (24 files) | `Helper.php` defaults (`order_new`, `order_ready`, `review`, `dispute`, `waitlist`), the sound picker, `track-notify.blade.php` (guest order chime), `assets/js/notify-sounds.js` | OSS-compatible (own) | `call-waiter.mp3`, `message.mp3`, `message.ogg` were third-party audio and were replaced with own chimes. The other 21 files (a third-party pack under a non-OSI licence) were replaced with own generated sounds under the same file names, so every stored `notification_sounds` id and the five defaults keep resolving; no third-party audio remains. |

## assets/js, assets/pos, assets/vendor, assets/owner-app/vendor

| Library | Version | Licence | Source | Path(s) | Used by | Verdict | Notes |
|---|---|---|---|---|---|---|---|
| howler.js | 2.2.4 | MIT | https://howlerjs.com | `assets/js/howler.min.js` | 5 (ui shell scripts, staff-app, POS register, …) | OSS-compatible | Banner present. |
| Alpine.js | 3.15.0 | MIT | https://alpinejs.dev | `assets/pos/alpine.min.js` | 1 (`staff/pos/register.blade.php`) | OSS-compatible | No banner. Version comes from `version:"3.15.0"`. Duplicates the shell copy (3.14.9); consolidate. |
| Alpine.js | 3.14.9 | MIT | https://alpinejs.dev | `assets/vendor/alpinejs/alpine.min.js` (+ `LICENSE`, `SOURCE.txt`) | 3 (shell, reseller panel, site-pages editor) | OSS-compatible | cdn build, minified. Duplicates `assets/pos/alpine.min.js` (3.15.0); consolidate. |
| Turbo | 8.0.23 | MIT | https://turbo.hotwired.dev | `assets/vendor/turbo/turbo.min.js` (+ `LICENSE`, `SOURCE.txt`) | 1 (shell legacy-styles) | OSS-compatible | Banner present. |
| Tabler Icons (SVG, 112 icons: 109 outline + 3 filled) | 3.48.0 | MIT (`assets/vendor/tabler-icons/LICENSE`) | https://tabler.io/icons · npm `@tabler/icons@3.48.0` (`npm pack`, integrity `sha512-lQk06gVH…p8eXQ==`) | `assets/vendor/tabler-icons/{outline,filled}/*.svg`, `LICENSE`, `SOURCE.txt` | `<x-ui.icon>` fallback (`core/resources/views/components/ui/icon.blade.php`) | OSS-compatible | Files copied unmodified from the pinned package version; only the icons the UI uses are present. |
| GrapesJS | 0.23.6 | BSD-3-Clause (`assets/vendor/grapesjs/LICENSE`) | https://grapesjs.com · npm `grapesjs@0.23.6` (integrity `sha512-I8M/sPWj…SKV1mrQ==`, see `SOURCE.txt`) | `assets/vendor/grapesjs/{grapes.min.js,grapes.min.css,LICENSE,SOURCE.txt,locale/{ar,de,es,fr,pt,ru,tr,zh}.mjs}` | `core/resources/views/builder/editor.blade.php` (reseller pages, CMS pages, biolink, reseller emails, custom blocks) | OSS-compatible | Files copied unmodified from the package. Bundles CodeMirror (MIT) for its code viewer. Uses `new Function` in 2 places (dashboard CSP already allows `unsafe-eval`). |
| ApexCharts | 5.0.0 | MIT | https://apexcharts.com | `assets/vendor/apexcharts/apexcharts.min.js` (+ `LICENSE`, `SOURCE.txt`) | 1 (owner shell legacy-scripts: dashboard + reports charts) | OSS-compatible | Banner present. |
| Leaflet | 1.9.4 | BSD-2-Clause (`assets/vendor/leaflet/LICENSE`) | https://leafletjs.com · npm `leaflet@1.9.4` (integrity `sha512-nxS1ynzJ…FA74PA==`, see `assets/vendor/leaflet/SOURCE.txt`) | `assets/vendor/leaflet/{leaflet.js,leaflet.css,images/*}` | admin/fusion delivery + zone editor, marketplace directory/store/track/address sheet, owner marketplace settings, `dir-map.js`, `mk-discovery.js`, and the 18 storefront themes with a map (`store_templates/{aurora,crave,crumb,dough,ember,feast,glow,lime,matcha,orbit,picnic,plate,scoop,soiree,velora,velvet,vivid,zevoa}/index.blade.php`), `_partials/account-extras.blade.php`, `_partials/map-picker.blade.php` | OSS-compatible | Banner present; files byte-identical to the npm `dist/`. The 19 identical copies under `assets/store_templates/*/vendor/leaflet/` (18 themes + `_shared`, 95 files) were deleted and every theme view now loads this one copy (`leafletImgPath` config points at `assets/vendor/leaflet/images`). |
| Leaflet.markercluster | 1.5.3 | MIT (`assets/vendor/leaflet/markercluster/LICENSE`) | https://github.com/Leaflet/Leaflet.markercluster · npm `leaflet.markercluster@1.5.3` (integrity `sha512-vPTw/Bnd…fCSeA==`) | `assets/vendor/leaflet/markercluster/{leaflet.markercluster.js,MarkerCluster.css,MarkerCluster.Default.css}` | 3 | OSS-compatible | Byte-identical to the package `dist/` (so the version is confirmed); licence added. |
| Hotwire Native Bridge | 1.2.2 | MIT | https://github.com/hotwired/hotwire-native-bridge | `assets/owner-app/vendor/hotwire-native-bridge-1.2.2.js` + `LICENSE-hotwire-native-bridge.txt` | 3 | OSS-compatible | Licence file is present. One local import-path edit is documented in the header. |
| Stimulus | 3.2.2 (banner says 3.2.1) | MIT | https://github.com/hotwired/stimulus | `assets/owner-app/vendor/stimulus-3.2.2.js` + `LICENSE-stimulus.md` | 2 | OSS-compatible | Licence file is present. |

## assets/fonts (platform fonts)

| Library | Version | Licence | Source | Path(s) | Used by | Verdict | Notes |
|---|---|---|---|---|---|---|---|
| Inter (latin subset 400/600/800) | unknown (no banner) | OFL-1.1 (`assets/fonts/INTER-OFL.txt`) | https://github.com/rsms/inter | `assets/fonts/inter-latin-{400,600,800}.woff2` | 1 (`core/config/theme-fonts.php`) | OSS-compatible | Licence text (with the family's copyright line) added from the matching `@fontsource/*@5.3.0` npm package (integrity verified), which ships the upstream OFL file. |
| IBM Plex Sans Arabic (400/700) | unknown (no banner) | OFL-1.1 (`assets/fonts/IBM-PLEX-SANS-ARABIC-OFL.txt`) | https://github.com/IBM/plex | `assets/fonts/plex-arabic-{400,700}.woff2` | 1 | OSS-compatible | Licence text (with the family's copyright line) added from the matching `@fontsource/*@5.3.0` npm package (integrity verified), which ships the upstream OFL file. |
| Syne (latin + latin-ext) | unknown (no banner) | OFL-1.1 (`assets/fonts/SYNE-OFL.txt`) | https://github.com/bonjourmonde/syne | `assets/fonts/syne-latin{,-ext}.woff2` | 1 (`assets/owner-ui/tokens.css`) | OSS-compatible | Licence text (with the family's copyright line) added from the matching `@fontsource/*@5.3.0` npm package (integrity verified), which ships the upstream OFL file. |
| Instrument Sans (latin + latin-ext, normal + italic) | unknown (no banner) | OFL-1.1 (`assets/fonts/INSTRUMENT-SANS-OFL.txt`) | https://github.com/Instrument/instrument-sans | `assets/fonts/instrument-sans-latin{,-ext}.woff2`, `instrument-sans-italic-latin{,-ext}.woff2` | 1 (`assets/owner-ui/site.css`, marketing site) | OSS-compatible | Licence text (with the family's copyright line) added from the matching `@fontsource/*@5.3.0` npm package (integrity verified), which ships the upstream OFL file. |
| Alexandria (variable) | unknown (no banner) | OFL-1.1 (`assets/fonts/ALEXANDRIA-OFL.txt`) | https://github.com/Gue3bara/Alexandria | `assets/fonts/alexandria-var.woff2` | 1 (`assets/fonts/fonts.css`, site typography) | OSS-compatible | The OFL text carries the family's copyright line. |
| Geist Mono (variable) | unknown (no banner) | OFL-1.1 (`assets/fonts/GEIST-MONO-OFL.txt`) | https://github.com/vercel/geist-font | `assets/fonts/geist-mono-var.woff2` | 1 (`core/config/theme-fonts.php`) | OSS-compatible | File unchanged. |
| Saudi Riyal Font (400/700) | unknown (no banner) | OFL-1.1 (`assets/fonts/SAUDI-RIYAL-OFL.txt`, © Emran Alhaddad) | https://github.com/EmranAlhadda (Saudi Riyal font) | `assets/fonts/saudi-riyal-{400,700}.{ttf,woff2}` | 2 | OSS-compatible | Licence file is present. |
| AED Dirham symbol (U+20C3 subset) | dirham npm 1.5.3 | MIT (`assets/fonts/AED-DIRHAM-LICENSE.txt`) | https://github.com/pooyagolchian/dirham | `assets/fonts/aed-dirham-400.woff2` | 2 | OSS-compatible | The licence file records provenance. The glyph shape is issued by the CBUAE. |

## assets/locked-templates (engine runtime + open sample)

Locked marketing templates are an add-on that is not part of this repository. juwri keeps the template engine
(`App\Services\LockedTemplates`, models, admin + reseller screens, `_runtime/{kit,html,editor*,lt-styles.css}`)
and ships one open sample, `starter` (`assets/locked-templates/starter/v1/{style.css,media/hero.svg}` +
`core/database/locked-templates/starter/v1.json`): own markup and CSS on the owner-ui kit tokens, no third-party
content. Pages pinned to a withdrawn template fall back to their published blocks
(`LockedTemplateVersion::assetsAvailable`).

| Library | Version | Licence | Source | Path(s) | Used by | Verdict | Notes |
|---|---|---|---|---|---|---|---|
| Lenis | 1.3.8 | MIT | https://github.com/darkroomengineering/lenis | `assets/locked-templates/_runtime/vendor/lenis.min.js` | `kit.js` (templates that list it) | OSS-compatible (engine copy) | No banner; version from embedded strings. The `starter` sample does not load it. |
| Swiper | 11.2.10 | MIT | https://swiperjs.com | `assets/locked-templates/_runtime/vendor/swiper-bundle.min.js` | `kit.js` (templates that list it) | OSS-compatible (engine copy) | The `starter` sample does not load it. |
| Lucide icon set (JSON) | "lucide-1.47.0" (as recorded in the file) | ISC (`assets/locked-templates/_runtime/icons/LICENSE`) | https://lucide.dev | `assets/locked-templates/_runtime/icons/icons.json` | 2 (`App\Services\LockedTemplates\Icons`, editor) | OSS-compatible (engine) | Licence file is present. Stays in place (`assets/locked-templates` is kept for the engine). |

## assets/marketplace-themes, assets/store_templates

| Library | Version | Licence | Source | Path(s) | Used by | Verdict | Notes |
|---|---|---|---|---|---|---|---|
| Fraunces, Plus Jakarta Sans, IBM Plex Sans Arabic (Crave marketplace theme) | unknown (no banner) | OFL-1.1 (`assets/marketplace-themes/crave/1/fonts/{FRAUNCES,PLUS-JAKARTA-SANS,IBM-PLEX-SANS-ARABIC}-OFL.txt`) | Google Fonts | `assets/marketplace-themes/crave/1/fonts/{fraunces-latin,jakarta-latin,plexar-400-arabic,plexar-700-arabic}.woff2` | 1 each (crave `style.css`) | OSS-compatible | Licence text (with the family's copyright line) added from the matching `@fontsource/*@5.3.0` npm package (integrity verified), which ships the upstream OFL file. |

---

## Summary

No commercial or non-OSI component is bundled. Every bundled component listed above carries an OSI-approved licence,
and every one ships with its licence text.

- Every OFL font in `assets/fonts` and the Crave marketplace theme ships its OFL text.
- The files listed as "removed (unused)" are no longer in the repository.
