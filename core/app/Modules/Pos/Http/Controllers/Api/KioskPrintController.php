<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Http\Controllers\Api;

use App\Models\Order;
use App\Services\FeatureAccess;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Pos\Services\Printing\EscPosRenderer;

/**
 * N8-P1 — ESC/POS receipt stream for the Sunmi kiosk printer. The kiosk Capacitor app
 * fetches this right after an order is placed and raw-prints it via the Sunmi plugin.
 *
 * Auth is the order's unguessable 32-char track_token (the kiosk already has it) — so the
 * endpoint is tenant-scoped by construction (a token only exists for one restaurant's
 * order) without a new credential. Returns { escpos: <base64> } or 204 when the owner has
 * kiosk auto-print disabled.
 */
class KioskPrintController extends Controller
{
    public function escpos(Request $request, string $token)
    {
        $order = Order::where('track_token', $token)->with(['items.menu', 'items.variant', 'items.itemExtras.extra'])->first();
        if (! $order) {
            return response()->json(['error' => 'Not found.'], 404);
        }

        // F6-4: the endpoint checked the `kiosk_auto_print` toggle only, so a store
        // whose kiosk entitlement had lapsed kept streaming receipts. Same two-lane
        // gate as the web kiosk (kill veto always + entitlement when plan_gated),
        // subject = the order's store owner, 60s serve-time cache. 403 rather than
        // 404/204: this is a machine API and pretending a saleable feature does not
        // exist hides the upsell (FIX-2 precedent).
        if (! FeatureAccess::storeAllowsCached((int) $order->store_id, 'kiosk')) {
            return response()->json(['error' => 'Kiosk is not enabled for this store.'], 403);
        }

        // Owner kill-switch: only kiosk-sourced orders auto-print, and only when enabled.
        if (! store_options((int) $order->store_id, 'kiosk_auto_print')) {
            return response()->noContent(); // 204 — printing disabled
        }

        $width = (int) (store_options((int) $order->store_id, 'kiosk_printer_width') ?: 48);
        $drawer = (bool) store_options((int) $order->store_id, 'kiosk_open_drawer');
        $bytes = new EscPosRenderer($width)->render($order, $drawer);

        return response()->json(['escpos' => base64_encode($bytes)]);
    }
}
