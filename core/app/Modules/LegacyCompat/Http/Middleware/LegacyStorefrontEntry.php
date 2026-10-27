<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Http\Middleware;

use App\Services\Entitlements;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\ScanDedupe;
use Juwri\Modules\LegacyCompat\Support\LegacyCompatRoutes;
use Juwri\Modules\LegacyCompat\Support\QrIdDecoder;
use Juwri\Modules\Ordering\Contracts\SalesRestrictions;
use Symfony\Component\HttpFoundation\Response;

/**
 * J52b: the old printed storefront links on the Juwri `{slug}` page (LEGACYCOMPAT-SPEC §3 rows `?qr-id=`, `?table=`,
 * `?table=&rt=`; plan §5.1-§5.3). Pushed onto the `web` group only while legacy_compat.enabled (and it re-checks the
 * switch per request); it acts only on safe requests routed to the storefront page.
 *
 * - `?qr-id=X` (GET only) decoding (old passphrases) to exactly the path segment, on a store the page would show: a
 *   302 (`Cache-Control: no-store`, never 301) to the same path with `table`, `src`, `rt` first and every other param
 *   kept; `qr-id` is dropped; nothing is added (no slug, no personal data). At most one scan per client network per
 *   store per abuse.scan_dedupe_seconds is counted ({@see ScanDedupe}, shared with the core page's own scans); the
 *   redirect happens every time. Cap reached: the menu is served (qr-id stripped), diners are not sent away.
 * - X not decoding to the path segment (another store's code, bad Base64, an array), or a HEAD request: nothing
 *   happens here; the page is served direct by the core controller and no scan is counted.
 * - `table` / `rt` are not touched here: the core page reads `table` from the query string only and guards the lookup
 *   for every route into the page (StoreController::scannedTable + TableKeyGuard); charge-to-room verifies `rt` (old
 *   key accepted by LegacyRoomCharge until the J56 end date). A qr-id match never sets a table, a room, a session
 *   flag or any privilege.
 */
final class LegacyStorefrontEntry
{
    public const QR_PARAM = 'qr-id';

    /** Params moved to the front of the clean URL, in this order (plan §5.1). */
    private const LEADING_PARAMS = ['table', 'src', 'rt'];

    public function __construct(private readonly QrIdDecoder $decoder) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! LegacyCompatRoutes::enabled() || ! $request->isMethodSafe() || ! self::isStorefrontPage($request)) {
            return $next($request);
        }

        $segment = $request->route('slug');
        if (! is_string($segment) || $segment === '') {
            return $next($request);
        }

        if (! $request->isMethod('GET') || ! $this->decoder->matches($request->query->all()[self::QR_PARAM] ?? null, $segment)) {
            return $next($request);
        }

        $store = self::findStore($segment);
        if (! $store || ! self::publiclyVisible($store)) {
            return $next($request); // the core page answers (404 for a hidden or unknown store)
        }

        // De-duplicate first, so a flood of forged codes costs one cache add each, not a monthly count() each.
        if (ScanDedupe::first((int) $store->id)) {
            if (self::capReached($store)) {
                $request->query->remove(self::QR_PARAM);

                return $next($request);
            }
            self::recordScan($request, $store);
        }

        return self::noStore(redirect()->to(self::cleanUrl($request), 302));
    }

    /** The clean URL after a counted scan: same path, qr-id removed, table/src/rt first, other params kept. */
    public static function cleanUrl(Request $request): string
    {
        $query = $request->query->all();
        unset($query[self::QR_PARAM]);

        $ordered = [];
        foreach (self::LEADING_PARAMS as $name) {
            if (array_key_exists($name, $query)) {
                $ordered[$name] = $query[$name];
                unset($query[$name]);
            }
        }
        $ordered += $query;

        $qs = http_build_query($ordered, '', '&', PHP_QUERY_RFC3986);

        return $request->url().($qs !== '' ? '?'.$qs : '');
    }

    private static function isStorefrontPage(Request $request): bool
    {
        $name = (string) $request->route()?->getName();

        return $name === 'publicView' || str_ends_with($name, '.publicView');
    }

    /** Same lookup as the storefront page: a numeric segment is a store id first, else the slug (byte-for-byte). */
    private static function findStore(string $segment): ?Store
    {
        $byId = ctype_digit($segment) ? Store::find((int) $segment) : null;

        return $byId ?? Store::query()->where('slug', $segment)->first();
    }

    /** The page's own visibility gates (active owner on an active plan, published), so no scan is counted for a 404. */
    private static function publiclyVisible(Store $store): bool
    {
        $owner = $store->user;
        if (! $owner || (int) $owner->status !== 1) {
            return false;
        }
        $plan = $owner->plan();
        if (! $plan || ! $plan->status) {
            return false;
        }

        return app(SalesRestrictions::class)->unpublishedReason($store) === null;
    }

    /** The store's monthly scan cap (plan + add-ons) is used up. */
    private static function capReached(Store $store): bool
    {
        $cap = effective_limit('scan', $store->user);

        return ! Entitlements::isUnlimitedSentinel($cap)
            && $store->views()->where('date', '>=', now()->startOfMonth())->count() >= $cap;
    }

    /** One scan row, same shape as the core page writes. */
    private static function recordScan(Request $request, Store $store): void
    {
        $source = $request->query('src') ?? $request->query('utm_source');
        $store->views()->create([
            'ip' => $request->ip(),
            'date' => now()->format('Y-m-d H:i:s'),
            'device' => self::device((string) $request->header('User-Agent', '')),
            'lang' => substr($request->getPreferredLanguage(['en', 'ar', 'fr', 'es', 'de', 'it', 'tr', 'ru']) ?: app()->getLocale(), 0, 2),
            'source' => is_string($source) && $source !== ''
                ? substr((string) preg_replace('/[^a-zA-Z0-9_\-]/', '', $source), 0, 64)
                : null,
        ]);
    }

    private static function noStore(RedirectResponse $response): RedirectResponse
    {
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    private static function device(string $userAgent): string
    {
        if (preg_match('/(tablet|ipad|playbook|silk)|(android(?!.*mobi))/i', $userAgent)) {
            return 'Tablet';
        }
        if (preg_match('/Mobile|iP(hone|od)|Android|BlackBerry|IEMobile|Kindle|Silk-Accelerated|(hpw|web)OS|Opera M(obi|ini)/', $userAgent)) {
            return 'Mobile';
        }

        return 'Desktop';
    }
}
