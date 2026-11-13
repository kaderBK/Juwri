<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Middleware;

use App\Models\Driver;
use Juwri\Core\Models\Feature;
use Juwri\Core\Models\User;
use App\Services\Entitlements;
use Juwri\Core\Contracts\Billing\FeatureCommerce;
use App\Services\FeatureRegistry;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/**
 * GAPS-RUN-2 B0-P2: superadmin global kill-switch gate.
 *
 * Applied as `feature:<key>` on a feature's entry-point route. When the
 * superadmin turns the feature off in Feature Controls (admin_allow_<key> = 0,
 * or the registry default is OFF), the route refuses with 403 — regardless of
 * plan entitlement. Platform OFF always wins.
 *
 * FIX-1 F1-1 (gap audit): the SAME alias now also covers owner-token REST v1
 * routes. Placed AFTER `ApiTokenAuth` on a route, the subject becomes the
 * token's owner (there is no session user on an API request) and every refusal
 * is a JSON 403 — never the HTML redirect the dashboard gets. That closes the
 * bypass where a non-entitled owner minted a token and drove a plan-gated
 * capability through the API. One vocabulary, two transports.
 */
class EnsureFeatureEnabled
{
    public function handle(Request $request, Closure $next, string $key)
    {
        /* FIX-6 F6-6: a marketplace FLEET driver is platform-owned — no
           merchant, no merchant subscription, and by the standing decision at
           routes/web.php (fleet shell: "Platform-level ... so no per-store
           feature gate") the `driver_app` SKU does not describe them at all.
           Both lanes are merchant lanes, so both are skipped; their real guard
           is DriverTokenAuth plus per-driver offer scoping. A store driver is
           NEVER exempt — that is the bypass this row closes. */
        if (self::platformOwned($request)) {
            return $next($request);
        }

        $api = self::isApiRequest($request);

        if (! FeatureRegistry::isEnabled($key)) {
            return $api
                ? response()->json(['error' => 'This feature is currently unavailable.'], 403)
                : abort(403, ___('This feature is currently unavailable.'));
        }

        /* Premium bundle (feature-platform): a plan_gated key ALSO requires
           plan entitlement — the owner's "sold per plan" flip. Kill stays the
           veto above; ungated keys keep today's kill-only behavior exactly. */
        if (self::planGated($key)) {
            $owner = self::subject($request);
            if (! $owner || ! Entitlements::allows($owner, $key)) {
                /* API callers get a machine-readable 403 with the key, so a
                   partner can tell "not entitled" from "bad token". */
                if ($api) {
                    return response()->json([
                        'error' => 'This feature is not included in your current plan.',
                        'feature' => $key,
                    ], 403);
                }
                /* FIX-3 (gap audit): redirect for EVERY key the catalog can sell,
                   not just the two pilot keys — a gated key with no buy path shown
                   was a dead 403. Non-GET/JSON callers still get the 403. */
                if ($request->isMethod('GET')
                    && ! $request->expectsJson()
                    && Route::has('feature.locked')
                    && app(FeatureCommerce::class)->sellable($key)) {
                    return redirect()->route('feature.locked', $key);
                }
                abort(403, ___('This feature is not included in your current plan.'));
            }
        }

        /* WI-B1 (upsell-visibility): the request has passed the gate, so this
           page IS a paid feature the owner holds. Sharing the key lets the
           layout render the "included in your plan" ribbon on every gated page
           at once — the alternative was editing twenty-odd views by hand and
           silently missing the ones added next. Read-only, and only for a
           browser GET; API callers have no layout to render it into. */
        if (! $api && ! $request->expectsJson() && $request->isMethod('GET')) {
            view()->share('pageFeatureKey', $key);

            /* WI-B5: opening a gated page IS using the feature. Stamped here so
               dormancy is measured from real use rather than from a checkbox
               somebody ticked once. Collect only — the owner decision is that
               this never becomes a "you aren't using this" nudge. */
            app(FeatureCommerce::class)->touch(self::subject($request), $key);
        }

        return $next($request);
    }

    /**
     * Whose entitlement decides. On an owner-token API request that is the
     * TOKEN OWNER (set by ApiTokenAuth as a server-controlled request
     * attribute, never client input); everywhere else the dashboard owner.
     */
    private static function subject(Request $request): ?User
    {
        if ($id = $request->attributes->get('_api_user_id')) {
            return User::find((int) $id);
        }

        /* FIX-6 F6-6: driver-token transport. DriverTokenAuth stamps `_driver_id`
           (not `_api_user_id`), so the subject is the driver's MERCHANT — the
           party who bought, or did not buy, `driver_app`. Same question the
           dashboard asks, asked of the right person. */
        if ($id = $request->attributes->get('_driver_id')) {
            return self::driverOwner((int) $id);
        }

        $owner = function_exists('dashboard_owner') ? dashboard_owner() : auth()->user();

        return $owner instanceof User ? $owner : null;
    }

    /** The merchant who owns a store driver, or null for a platform fleet driver. */
    private static function driverOwner(int $driverId): ?User
    {
        $ownerId = (int) Driver::where('id', $driverId)->value('owner_id');

        return $ownerId ? User::find($ownerId) : null;
    }

    /**
     * Is this caller PLATFORM-owned rather than merchant-owned? True only for a
     * marketplace fleet driver (`is_fleet`, `owner_id` NULL), who works the
     * platform's own dispatch queue. Deliberately narrow: it reads a
     * server-stamped request attribute and a server-owned column, never client
     * input, and it can only ever be true on the driver transport.
     */
    private static function platformOwned(Request $request): bool
    {
        $id = $request->attributes->get('_driver_id');

        if (! $id) {
            return false;
        }

        return ! Driver::where('id', (int) $id)->value('owner_id');
    }

    /**
     * API transport = an owner-token request (ApiTokenAuth ran and stamped the
     * owner). Deliberately NARROW: `expectsJson()` requests keep flowing
     * through `abort(403)`, which Laravel's handler already renders as JSON —
     * widening it here would silently reshape every existing dashboard-AJAX
     * 403 body.
     */
    private static function isApiRequest(Request $request): bool
    {
        return $request->attributes->has('_api_user_id')
            || $request->attributes->has('_driver_id'); // FIX-6: driver app is JSON-only
    }

    /** P6-60: cross-request app-cache key for the plan-gated key list (busted by FeatureRegistry::clearCache()). */
    public const CACHE_KEY = 'features.plan_gated.v1';

    /**
     * Per-request memo of plan-gated keys over a cross-request app cache (hot
     * path: zero queries once warm). The memo lives on the container, not in a
     * class static, so Octane never carries it between requests; any Feature
     * write drops both layers (Feature::booted -> FeatureRegistry::clearCache).
     */
    public static function planGated(string $key): bool
    {
        if (! app()->bound('features.plan_gated')) {
            try {
                $set = Cache::remember(
                    self::CACHE_KEY,
                    3600,
                    fn () => Feature::where('plan_gated', true)->pluck('key')->flip()->all()
                );
                app()->instance('features.plan_gated', $set);
                // Remember the last known-good gated set so a transient read
                // failure can't fling every gate open (see the catch below).
                try {
                    Cache::put('features.plan_gated.lkg', array_keys($set), now()->addDay());
                } catch (\Throwable $e) {
                    // cache write best-effort — never block the request on it
                }
            } catch (\Throwable $e) {
                /* DB read failed (mid-migration or a transient error). An EMPTY
                   set means "nothing is plan-gated" → every paid capability
                   degrades to kill-switch-only, i.e. a non-entitled store gets
                   all paid features for the duration (fail OPEN). Prefer the
                   last known-good snapshot; only when none exists (e.g. the very
                   first run, genuinely mid-migration) fall back to kill-only. */
                $lkg = [];
                try {
                    $lkg = (array) Cache::get('features.plan_gated.lkg', []);
                } catch (\Throwable $e2) {
                    $lkg = [];
                }
                app()->instance('features.plan_gated', array_flip($lkg));
            }
        }

        return isset(app('features.plan_gated')[$key]);
    }
}
