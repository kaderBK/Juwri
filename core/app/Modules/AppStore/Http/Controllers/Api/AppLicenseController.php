<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\AppStore\Http\Controllers\Api;

use Juwri\Core\Http\Controllers\Controller;
use App\Models\App;
use App\Models\AppInstallation;
use Illuminate\Http\Request;

/**
 * P4-6 (feature-platform): GET /api/v1/app/license — the partner app asks
 * "is this workspace licensed, and for what?". Same server-to-server auth as
 * usage metering: the app identity comes from the OAuth token's client, so a
 * partner can only ever read its own license for the tenant that granted the
 * token.
 */
class AppLicenseController extends Controller
{
    public function show(Request $request)
    {
        /** @var \Laravel\Passport\AccessToken<mixed>|null $token Passport 13: bearer tokens are AccessToken (attributes + Token fallback) */
        $token = $request->user('api')?->token();
        $app = $token ? App::where('oauth_client_id', $token->client_id)->first() : null;
        if (! $app) {
            return response()->json(['message' => 'Token does not belong to a registered app.'], 403);
        }

        $installation = AppInstallation::where('app_id', $app->id)
            ->where('user_id', $request->user('api')->id)
            ->first();

        if (! $installation || $installation->status === 'uninstalled') {
            return response()->json(['active' => false, 'status' => $installation->status ?? 'not_installed']);
        }

        return response()->json([
            'active' => $installation->status === 'active',
            'status' => $installation->status,                     // active | past_due | suspended
            'billing_type' => $app->billing_type,
            'is_trial' => (bool) ($installation->trial_ends_at?->isFuture()),
            'trial_ends_at' => $installation->trial_ends_at?->toIso8601String(),
            'renews_at' => $installation->current_period_ends_at?->toIso8601String(),
            'grace_ends_at' => $installation->grace_period_ends_at?->toIso8601String(),
            'granted_scopes' => $installation->granted_scopes,
            'granted_features' => $this->grantedFeatures($app, $installation),
        ]);
    }

    /**
     * FIX-6 F6-2 (gap audit): `granted_features` used to echo `$app->grants_features`
     * raw, so the endpoint kept advertising features that were not actually in
     * force. A partner polling this API was told "yes" while the platform said
     * "no" — the license API was the only place the two-lane model leaked.
     *
     * Two filters, in the order the model requires:
     *
     *  1. INSTALL STATUS. Only a status that still entitles the owner reports
     *     features. `active` grants; `past_due` ALSO grants — it is the PAID
     *     grace window opened by AppBillingService::renewDue (3 days) during
     *     which the app keeps working, and no revoke has run. `suspended` is
     *     grace EXHAUSTED: renewDue calls syncFeatureGrants, the overrides are
     *     already gone, so reporting them here would be a straight lie.
     *     `uninstalled` never reaches this method (short-circuited above). The
     *     status list is not re-encoded here — it is the same constant
     *     `entitlements:reconcile` uses to decide what counts as an orphan, so
     *     the API and the reconciler can never disagree.
     *
     *  2. KILL-SWITCH VETO. A superadmin kill (admin_allow_<key> = 0) turns the
     *     capability off platform-wide and beats every commercial grant, so a
     *     killed key drops out of the list even for an active install. This is
     *     the same veto `EnsureFeatureEnabled` and `FeatureAccess` apply — the
     *     API now speaks the same vocabulary as the web.
     *
     * Deliberately NOT filtered: the owner's plan entitlement. An app grant IS
     * an entitlement source (source_type='app_installation'), so intersecting
     * with the resolver would be circular and would hide grants the install
     * legitimately created.
     *
     * @return array<int, string> re-indexed so it serializes as a JSON array.
     */
    private function grantedFeatures(App $app, AppInstallation $installation): array
    {
        $entitling = AppInstallation::ENTITLING_STATUSES;

        if (! in_array($installation->status, $entitling, true)) {
            return [];
        }

        $keys = array_filter(array_map('strval', (array) ($app->grants_features ?? [])), 'strlen');

        return array_values(array_filter(
            array_unique($keys),
            fn (string $key) => \App\Services\FeatureRegistry::isEnabled($key),
        ));
    }
}
