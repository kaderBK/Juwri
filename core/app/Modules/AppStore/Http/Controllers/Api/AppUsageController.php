<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\AppStore\Http\Controllers\Api;

use Juwri\Core\Http\Controllers\Controller;
use App\Models\App;
use App\Models\AppInstallation;
use Juwri\Modules\AppStore\Services\AppBillingService;
use Illuminate\Http\Request;

/**
 * P3-4: partner-facing usage metering. Server-to-server: the app calls with
 * its OAuth access token (P3-1); the app identity comes from the TOKEN's
 * client — a partner can only ever meter its own app, for the tenant that
 * granted the token. Idempotent by the partner's `ref`.
 */
class AppUsageController extends Controller
{
    public function store(Request $request, AppBillingService $billing)
    {
        $request->validate([
            'quantity' => 'required|numeric|gt:0',
            'ref' => 'nullable|string|max:120',
        ]);

        /** @var \Laravel\Passport\AccessToken<mixed>|null $token Passport 13: bearer tokens are AccessToken (attributes + Token fallback) */

        $token = $request->user('api')?->token();
        $app = $token ? App::where('oauth_client_id', $token->client_id)->first() : null;
        if (! $app) {
            return response()->json(['message' => 'Token does not belong to a registered app.'], 403);
        }

        $installation = AppInstallation::where('app_id', $app->id)
            ->where('user_id', $request->user('api')->id)
            ->where('status', 'active')
            ->first();
        if (! $installation) {
            return response()->json(['message' => 'App is not installed for this account.'], 403);
        }

        try {
            $record = $billing->recordUsage($installation, (float) $request->input('quantity'), $request->input('ref'));
        } catch (\RuntimeException $e) {
            \App\Services\PlatformMetrics::record('app.payment_fail', $app->slug); // P5-3

            return response()->json(['message' => $e->getMessage()], 402); // payment required
        }

        return response()->json([
            'ok' => true,
            'id' => $record->id,
            'charged' => (float) $record->charged,
            'unit' => $record->unit,
        ], 201);
    }
}
