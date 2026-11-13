<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Verticals\Http\Controllers\User;

use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Verticals\Models\SkinProfile;
use Juwri\Modules\Verticals\Services\Skin\SkinScanProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public endpoints for the lumiere (cosmetic) theme's "Scan Your Face" +
 * routine persistence. Scan delegates to the bound SkinScanProvider (mock
 * today — plausible metrics + product picks from the store's own catalog);
 * profile saves the routine/metrics to a per-viewer skin_profiles row. The
 * theme also keeps a localStorage copy, so profile-save is best-effort.
 */
class SkinController extends Controller
{
    /** P6-71: largest accepted skin/profile request body, in bytes. */
    public const MAX_PROFILE_BYTES = 16384;

    private function store(string $slug): Store
    {
        return Store::where('slug', $slug)->firstOrFail();
    }

    /** POST {slug}/skin/scan — returns flat metrics the theme reads directly. */
    public function scan(string $slug, Request $request, SkinScanProvider $provider): JsonResponse
    {
        $store = $this->store($slug);
        $r = $provider->analyze($request->input('image'), (int) $store->id);
        $m = $r['metrics'];

        return response()->json([
            'skin_age' => $r['skin_age'],
            'acne' => $m['acne'] ?? 0,
            'oily' => $m['oily'] ?? 0,
            'wrinkles' => $m['wrinkles'] ?? 0,
            'dryness' => $m['dryness'] ?? 0,
            'recommendation' => $r['recommendation'],
            'recommended_menu_ids' => $r['recommended_menu_ids'],
        ]);
    }

    /** POST {slug}/skin/profile — persist the viewer's routine/metrics. */
    public function profile(string $slug, Request $request): JsonResponse
    {
        $store = $this->store($slug);
        // P6-71: public endpoint — refuse an oversized body (the routine is free-form JSON) before it reaches the database.
        if (strlen($request->getContent()) > self::MAX_PROFILE_BYTES) {
            return response()->json(['message' => 'Profile payload too large (max 16 KB).', 'code' => 'payload_too_large'], 422);
        }
        $token = substr((string) $request->session()->getId(), 0, 64) ?: null;
        // P6-72: public endpoint — keep only the numeric scan metrics, never the raw post.
        $payload = [];
        foreach (['skin_age', 'skinAge', 'acne', 'oily', 'wrinkles', 'dryness'] as $k) {
            if (is_numeric($request->input($k))) {
                $payload[$k] = (float) $request->input($k);
            }
        }

        SkinProfile::updateOrCreate(
            ['store_id' => $store->id, 'session_token' => $token, 'customer_id' => null],
            [
                'skin_age' => $request->input('skinAge', $request->input('skin_age')),
                'metrics' => $payload,
                'routine' => $request->input('routine'),
            ]
        );

        return response()->json(['ok' => true]);
    }
}
