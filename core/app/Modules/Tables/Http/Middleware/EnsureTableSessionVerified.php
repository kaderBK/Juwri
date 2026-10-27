<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Tables\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Juwri\Core\Models\Store;
use Juwri\Modules\Tables\Services\TableSessionService;

/**
 * PARITY-RUN T3 — the dine-in scan gate.
 *
 * Attached to the storefront order-submit route ONLY. Two rules govern this
 * class and neither is negotiable:
 *
 *  1. **It is order-type aware.** Delivery, takeaway and hotel room service are
 *     returned untouched on the very first branch. This feature exists to prove
 *     a guest is sitting at the table; it has nothing to say about anyone else,
 *     and a regression here would break every non-dine-in order in the product.
 *
 *  2. **It is NOT `feature:`/`entitled:` middleware.** The route it guards is
 *     reached by anonymous diners, where those aliases resolve the entitlement
 *     subject from the session and 403 everyone. The store-scoped question is
 *     asked inside {@see TableSessionService::mode()} via
 *     FeatureAccess::storeAllows(), and an unentitled store degrades to `off`.
 *
 * `staff_approve` is not a guest-side gate: the order is allowed through and a
 * request attribute tells the controller to park it as `pending_approval`.
 */
class EnsureTableSessionVerified
{
    /** Request attribute the controller reads to park an order for approval. */
    public const ATTR_PENDING_APPROVAL = 't3_pending_approval';

    /** Dine-in only. room_service has its own (hotel) flow and is left alone. */
    private const DINE_IN_TYPES = ['on-table'];

    public function handle(Request $request, Closure $next)
    {
        $type = (string) $request->input('ordering-type', '');
        if (! in_array($type, self::DINE_IN_TYPES, true)) {
            return $next($request);
        }

        $store = $this->resolveStore($request);
        if (! $store) {
            return $next($request);
        }

        $mode = TableSessionService::mode($store->id);
        if ($mode === TableSessionService::MODE_OFF) {
            return $next($request);
        }

        if ($mode === TableSessionService::MODE_STAFF_APPROVE) {
            $request->attributes->set(self::ATTR_PENDING_APPROVAL, true);

            return $next($request);
        }

        // table_code / sms_otp — the guest must already hold a verified session
        // for THIS table. The order payload carries the table NUMBER; our
        // storefront partial also forwards the QR key when the guest scanned one.
        $tableNo = trim((string) $request->input('table', ''));
        $tableKey = trim((string) $request->input('table_key', ''));

        if (TableSessionService::isVerifiedFor($request, (int) $store->id, $tableKey ?: null, $tableNo ?: null)) {
            return $next($request);
        }

        return $this->refuse($request, $store, $mode, $tableKey, $tableNo);
    }

    /**
     * Refusal. Mirrors the shape of the existing F7 checkout-OTP gate in
     * StoreController@submitOrder — HTTP 200 with `success: false` plus a machine
     * flag — because every theme's order bundle already understands that shape
     * and will surface `message` verbatim. The shared verification partial
     * listens for `table_verification_required` and re-opens its modal.
     */
    private function refuse(Request $request, Store $store, string $mode, string $tableKey, string $tableNo)
    {
        $message = $mode === TableSessionService::MODE_SMS_OTP
            ? ___('Please verify your phone number to order from this table.')
            : ___('Please enter the code shown on your table to order.');

        if ($request->expectsJson() || $request->ajax() || $request->wantsJson()) {
            return response()->json([
                'success' => false,
                'table_verification_required' => true,
                'mode' => $mode,
                'table' => $tableNo,
                'table_key' => $tableKey,
                'message' => $message,
            ]);
        }

        // No-JS fallback: the guest lands back on the menu with the gate open.
        $url = route('publicView', $store->slug)
            .($tableKey !== '' ? '?table='.urlencode($tableKey) : '')
            .($tableKey !== '' ? '&' : '?').'verify=1';

        return redirect($url)->with('table_verify_error', $message);
    }

    /** The route's bound Post, whether or not bindings have been substituted. */
    private function resolveStore(Request $request): ?Store
    {
        $param = $request->route('store');

        if ($param instanceof Store) {
            return $param;
        }

        if ($param === null || $param === '') {
            return null;
        }

        return Store::find(is_numeric($param) ? (int) $param : null)
            ?: Store::where('slug', (string) $param)->first();
    }
}
