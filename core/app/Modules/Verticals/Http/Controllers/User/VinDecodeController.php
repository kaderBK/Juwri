<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Verticals\Http\Controllers\User;

use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use App\Services\Verticals\VerticalRegistry;
use Juwri\Modules\Verticals\Services\Vin\VinDecodeProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public "Scan your VIN" endpoint for the torque (car-parts) storefront theme.
 * Returns enabled:false (200, not 404) for any non-fitment store so a stray
 * client call from a mis-cached page degrades quietly — same contract as
 * FitmentPublicController. The decode itself is delegated to the bound
 * VinDecodeProvider (mock today; swap later without touching this controller).
 */
class VinDecodeController extends Controller
{
    public function decode(string $slug, Request $request, VinDecodeProvider $provider): JsonResponse
    {
        $store = Store::where('slug', $slug)->firstOrFail();

        if (! app(VerticalRegistry::class)->has($store->store_type, 'fitment')) {
            return response()->json(['enabled' => false, 'found' => false, 'vehicle' => null]);
        }

        $vin = (string) $request->input('vin', '');
        if (trim($vin) === '') {
            return response()->json(['enabled' => true, 'found' => false, 'vehicle' => null], 422);
        }

        $vehicle = $provider->decode($vin, $store->id);

        return response()->json([
            'enabled' => true,
            'found' => $vehicle !== null,
            'vehicle' => $vehicle,
        ]);
    }
}
