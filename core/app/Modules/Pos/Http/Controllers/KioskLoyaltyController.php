<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Http\Controllers;

use App\Models\Customer;
use App\Services\FeatureAccess;
use App\Support\KioskContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Pos\Services\Kiosk\KioskLoyalty;

/**
 * Item 26: loyalty at the kiosk (see KioskLoyalty for the rules). Every route answers 404 unless this browser session is a
 * live kiosk of the store (the kiosk route marked it) and the owner turned "loyalty at the kiosk" on.
 */
class KioskLoyaltyController extends Controller
{
    public function __construct(private readonly KioskLoyalty $loyalty) {}

    private function kiosk(Request $request, string $slug): Store
    {
        $store = Store::query()->idOrSlug($slug)->firstOrFail();
        abort_unless(KioskContext::on((int) $store->id, $request->session()) && KioskLoyalty::enabled((int) $store->id), 404);

        return $store;
    }

    /** Step 1: the customer opts in and types their phone. Same answer whether or not the phone is a customer here. */
    public function code(Request $request, string $slug): JsonResponse
    {
        $store = $this->kiosk($request, $slug);
        $data = $request->validate(['phone' => ['required', 'string', 'max:20'], 'opt_in' => ['accepted']]);
        $this->loyalty->requestCode($store, $data['phone']);

        return response()->json(['ok' => true, 'message' => ___('If this number has an account here, we sent it a code.')]);
    }

    /** Step 2: the code from their phone. Only now is anything about them shown. */
    public function verify(Request $request, string $slug): JsonResponse
    {
        $store = $this->kiosk($request, $slug);
        $data = $request->validate(['phone' => ['required', 'string', 'max:20'], 'code' => ['required', 'string', 'max:10']]);
        if (! $this->loyalty->verifyCode($store, $data['phone'], $data['code'], $request->session())) {
            return response()->json(['ok' => false, 'message' => ___('That code did not work.')], 422);
        }

        return response()->json(['ok' => true]);
    }

    /** Or: the pass QR from the customer's own signed-in phone (opt-in on the kiosk too). */
    public function pass(Request $request, string $slug): JsonResponse
    {
        $store = $this->kiosk($request, $slug);
        $data = $request->validate(['token' => ['required', 'string', 'max:400'], 'opt_in' => ['accepted']]);
        if (! $this->loyalty->redeemPass($store, $data['token'], $request->session())) {
            return response()->json(['ok' => false, 'message' => ___('That code did not work.')], 422);
        }

        return response()->json(['ok' => true]);
    }

    public function me(Request $request, string $slug): JsonResponse
    {
        $store = $this->kiosk($request, $slug);
        $customer = $this->loyalty->current($store, $request->session());
        if ($customer === null) {
            return response()->json(['recognised' => false], 404)->header('Cache-Control', 'no-store, private');
        }

        return response()->json(['recognised' => true] + $this->loyalty->summary($store, $customer))->header('Cache-Control', 'no-store, private');
    }

    public function reorder(Request $request, string $slug): JsonResponse
    {
        $store = $this->kiosk($request, $slug);
        $data = $request->validate(['order' => ['required', 'integer', 'min:1']]);
        $customer = $this->loyalty->current($store, $request->session());
        abort_if($customer === null, 401);
        $lines = $this->loyalty->reorder($store, $customer, (int) $data['order']);
        abort_if($lines === null, 404);

        return response()->json($lines)->header('Cache-Control', 'no-store, private');
    }

    /** "Not me" / done: the kiosk forgets the customer. */
    public function forget(Request $request, string $slug): JsonResponse
    {
        $store = $this->kiosk($request, $slug);
        $this->loyalty->forget((int) $store->id, $request->session());

        return response()->json(['ok' => true]);
    }

    /** On the customer's OWN phone (signed in to this store): a 5-minute, single-use pass to show the kiosk as a QR. */
    public function issuePass(Request $request, string $slug): JsonResponse
    {
        $store = Store::query()->idOrSlug($slug)->firstOrFail();
        abort_unless(FeatureAccess::storeAllowsCached((int) $store->id, 'kiosk') && KioskLoyalty::enabled((int) $store->id), 404);
        abort_if(KioskContext::active((int) $store->id, $request->session()), 404); // never from the kiosk itself
        $customer = auth('customer')->user();
        abort_unless($customer instanceof Customer && (int) $customer->store_id === (int) $store->id, 401);

        return response()->json(['token' => $this->loyalty->issuePass($store, $customer), 'expires_in' => KioskLoyalty::PASS_TTL_SECONDS])
            ->header('Cache-Control', 'no-store, private');
    }
}
