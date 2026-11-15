<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http\Controllers\User\Concerns;

use App\Models\StoreTable;
use App\Models\StoreView;
use App\Services\Entitlements;
use Illuminate\Http\RedirectResponse;
use Juwri\Core\Contracts\Hotel\RoomCharge;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\ScanDedupe;
use Juwri\Core\Services\TableKeyGuard;
use Juwri\Modules\Tables\Services\TableMergeService;

/**
 * QR scan deduplication/recording and table/room-token resolution for public storefronts.
 *
 * Extracted from StoreController to keep the controller under its size ceiling.
 */
trait HandlesStorefrontQrScan
{
    /**
     * Record one QR scan (device, language and a sanitised `src`/`utm_source`), at most once per client network per
     * store per abuse.scan_dedupe_seconds (a qr-id is not secret, so a script must not burn the monthly cap), then
     * redirect to the menu without the token so a reload is not counted. Returns null when the owner's monthly scan
     * cap (#67: add-ons included) is used up: the menu is then served as a plain visit, diners are not sent away.
     */
    private function countQrScan(Store $store): ?RedirectResponse
    {
        if (ScanDedupe::first((int) $store->id)) {
            $cap = effective_limit('scan', $store->user);
            if (! Entitlements::isUnlimitedSentinel($cap) // P6-1
                && StoreView::where('store_id', $store->id)->where('date', '>=', now()->startOfMonth())->count() >= $cap) {
                return null;
            }
            $this->recordQrScan($store);
        }

        // BL-020: keep every param except the scan token (table, src, rt for room QRs, utm_*…), so a reload is not
        // counted again and a room QR keeps its room token.
        $kept = request()->query();
        unset($kept['qr-id']);
        $qs = http_build_query($kept, '', '&', PHP_QUERY_RFC3986);

        return redirect(route('publicView', $store->slug).($qs !== '' ? '?'.$qs : ''));
    }

    private function recordQrScan(Store $store): void
    {
        $request = request();
        $source = $request->get('src') ?? $request->get('utm_source');
        StoreView::create([
            'store_id' => $store->id,
            'ip' => $request->ip(),
            'date' => date('Y-m-d H:i:s'),
            'device' => self::scanDevice((string) $request->header('User-Agent', '')),
            // Two-letter code from Accept-Language (short safe list), else the app locale.
            'lang' => substr($request->getPreferredLanguage(['en', 'ar', 'fr', 'es', 'de', 'it', 'tr', 'ru']) ?: app()->getLocale(), 0, 2),
            // Guard against array-valued ?src[]=x (substr on an array TypeErrors).
            'source' => is_string($source) && $source !== ''
                ? substr(preg_replace('/[^a-zA-Z0-9_\-]/', '', $source), 0, 64)
                : null,
        ]);
    }

    private static function scanDevice(string $userAgent): string
    {
        if (preg_match('/(tablet|ipad|playbook|silk)|(android(?!.*mobi))/i', $userAgent)) {
            return 'Tablet';
        }
        if (preg_match('/Mobile|iP(hone|od)|Android|BlackBerry|IEMobile|Kindle|Silk-Accelerated|(hpw|web)OS|Opera M(obi|ini)/', $userAgent)) {
            return 'Mobile';
        }

        return 'Desktop';
    }

    /**
     * The table row behind a `?table=` scan (plan entitlement table_qr, P6-2).
     * B3: the signed room token (`rt=`) is remembered for the charge-to-room
     * check. PARITY-RUN T4: a merged secondary table resolves to the party's
     * primary one, so the order joins the open bill (best effort).
     */
    private function scannedTable(Store $store): ?StoreTable
    {
        // One canonical read: the query string only (never the body), a string only. Every route into this page
        // (/{slug}, /{numericId}, the old /restaurant/{slug}/… URL) passes here, so the key guard covers them all.
        // Without the table_qr entitlement only hotel rooms resolve (same guard), so a hotel keeps its room panel.
        $key = request()->query->all()['table'] ?? null;
        if ($key === null || $key === '') {
            return null;
        }
        $tablesEntitled = Entitlements::has($store->user, 'table_qr');
        if (! $tablesEntitled && ! app(RoomCharge::class)->enabled($store->id)) {
            return null;
        }
        if (! is_string($key)) {
            TableKeyGuard::miss((int) $store->id); // ?table[]=… is never a key

            return null;
        }
        if (TableKeyGuard::blocked((int) $store->id)) {
            return null; // too many unknown keys: "no table" without a lookup, so the answer reveals nothing
        }

        $table = StoreTable::where(['store_id' => $store->id, 'key' => $key])
            ->when(! $tablesEntitled, fn ($q) => $q->where('area_type', 'room'))
            ->first();
        if (! $table) {
            TableKeyGuard::miss((int) $store->id);
        }

        $roomToken = request()->query->all()['rt'] ?? null; // query only, string only (?rt[]= is ignored)
        if (is_string($roomToken) && $roomToken !== '') {
            session(['hotel_room_token' => $roomToken]);
        }

        if ($table) {
            try {
                $table = TableMergeService::resolvePrimaryTable($table);
            } catch (\Throwable $e) {
                // keep the scanned table
            }
        }

        return $table;
    }

    /**
     * SF2: the visitor holds the token of the stay that is active in this room now (session `hotel_stay_token`,
     * stashed by HotelGuestController::folio). A previous stay's token, or no token, is false.
     */
    private function currentStayTokenHeld(Store $store, ?StoreTable $table): bool
    {
        $held = session('hotel_stay_token');
        if (! $table || ! is_string($held) || strlen($held) < 20) {
            return false;
        }
        try {
            $stay = app(RoomCharge::class)->stayForGuest((int) $store->id, (int) $table->id, null, $held); // BL-050
            $token = $stay ? (string) ($stay->getAttribute('token') ?? '') : '';

            return $token !== '' && hash_equals($token, $held);
        } catch (\Throwable) {
            return false;
        }
    }

    /** The visitor's stashed room token (`rt` from the printed room QR) verifies for this store's scanned room. */
    private function roomTokenVerified(Store $store, ?StoreTable $table): bool
    {
        if (! $table || $table->area_type !== 'room' || (int) $table->store_id !== (int) $store->id) {
            return false;
        }
        $token = session('hotel_room_token');
        try {
            return is_string($token) && $token !== '' && app(RoomCharge::class)->verifyToken($table, $token);
        } catch (\Throwable) {
            return false;
        }
    }
}
