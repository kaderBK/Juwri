# Payment-page scripts (PCI DSS 4.0, requirements 6.4.3 and 11.6.1)

Every page that hosts payment entry, or sends the buyer to it, has an authorised list of scripts. The list is code:
`core/config/payment_scripts.php` (read by `Juwri\Core\Pci\PaymentScriptInventory`). An installed edition package may add
its own pages and scripts in a `payment_scripts` manifest file.

* `pages`: the payment pages (kind `entry` = payment entry, `choice` = the gateway choice that leads to it), the views that
  render them and the shell (layout) that draws them.
* `scripts`: the authorised scripts. A first-party `src` is a path from the site root (the `?v=` cache buster is ignored);
  a third-party `src` is a full `https://` URL.
* `excluded`: scripts of a shared layout that are switched off on payment pages.

`tests/Feature/Pci` fails when a payment page can load a script that is not listed, and each page's enforced CSP only allows
the listed hosts.

## Adding a script to a payment page

1. Prefer not adding it. A payment page needs a business reason for every script.
2. Self-host it when you can (copy the file under `public/assets/...`, pin the version, list the path). Self-hosted files are
   hashed by the weekly check, so a changed file is detected.
3. Add an entry under `scripts` with `src`, `pages` (page keys, `shell:storefront|bare|dashboard`, or `*`) and a `reason`.
4. A third-party script needs one of:
   * `integrity` (`sha384-...`) and the same hash in the `<script integrity="...">` tag (a test compares them); or
   * `sri_exempt` with the reason an SRI hash is impossible (see the list below). Use it only when the vendor changes the file
     in place and publishes no hash. The page CSP then allows only that exact host.
5. Add the hosts it needs to the entry's `csp` (`script-src`, `connect-src`, `frame-src`, ...). Inline scripts need the CSP nonce.
6. Run `php artisan test tests/Feature/Pci`.

## Scripts exempt from SRI

SRI is impossible for these because the vendor serves a living file. They are pinned by URL/host through the page CSP and
watched by the weekly check. Each must keep a written reason in `sri_exempt`; `PaymentScriptInventoryTest` fails otherwise,
and also fails when this list and the config differ.

<!-- sri-exempt-list:start -->
* `google-tag` (gtag.js): Google generates it per measurement id and changes it without notice.
* `google-tag-manager` (gtm.js): generated per container; only Google's tag host is allowed.
* `meta-pixel` (fbevents.js): Meta updates it in place and loads per-pixel config from the same host.
* `midtrans-snap` (snap.js): updated in place, no SRI hash published; pinned to the exact URL.
* `midtrans-snap-sandbox` (sandbox snap.js): same as Midtrans, sandbox mode.
* `paystack-inline` (inline.js): a living file without SRI support; pinned to the exact URL.
* `razorpay-checkout` (checkout.js): updated in place; Razorpay asks merchants not to pin or self-host it.
* `two-checkout-2co` (2co.min.js): updated in place; its token call is a script from the same host, so the host is allowed.
<!-- sri-exempt-list:end -->

## The weekly check (requirement 11.6.1)

`php artisan pci:check-payment-pages` runs weekly (default `30 4 * * 1`; `PCI_MONITOR_CRON`, `PCI_MONITOR_ENABLED`). It
renders the built-in probe pages and the configured `PCI_MONITOR_TARGETS` as a customer's browser receives them. For each page
it hashes the script set (each external script with its SRI hash, plus the file content hash of first-party and SRI-pinned
scripts) and the security headers, and compares them with the page's accepted baseline. The first check of a page becomes its
baseline.

A difference, a script that is not in the inventory, a browser-reported CSP violation, or a page that cannot be fetched is
stored in `payment_page_checks`, makes the command fail, and sends a `pci_payment_page` alert to the platform admins through
`AlertDispatcher`. After reviewing a change that is authorised (and listing any new script), run
`php artisan pci:accept-baseline <page>` (or `--all`) to record the page as it is now.
