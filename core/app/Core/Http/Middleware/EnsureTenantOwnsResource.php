<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Juwri\Core\Models\Concerns\ScopesToOwner;
use Juwri\Core\Models\Store;
use Juwri\Core\Policies\TenantPolicy;

/**
 * S13: `tenant.owns` — defense-in-depth cross-tenant guard.
 *
 * Resolves the route-bound owned model and aborts 403 unless it falls within
 * the acting dashboard scope (owner → all their restaurants; staff → their one
 * restaurant). This backstops the per-controller owner-scoped findOrFail so an
 * IDOR cannot silently regress if a controller path forgets to scope.
 *
 * Only guards routes that actually resolve a bound Eloquent model. A route
 * whose parameter is a scalar id (manual find in the controller) is left to the
 * controller's own owner scoping — this middleware never fails those closed, it
 * simply passes through (nothing to resolve), so attaching it is always safe.
 *
 * Superadmins bypass (mirrors PostPolicy::before).
 *
 * Usage: `->middleware('tenant.owns')` (auto-detects the first bound model) or
 * `->middleware('tenant.owns:order')` to pin a specific route parameter.
 */
class EnsureTenantOwnsResource
{
    public function handle(Request $request, Closure $next, ?string $param = null)
    {
        $user = $request->user();

        if ($user && method_exists($user, 'isAdmin') && $user->isAdmin()) {
            return $next($request);
        }

        $model = $this->resolveModel($request, $param);

        // Nothing bound to check (scalar id / no such param): defer to the
        // controller's own owner scoping. Never fail an unresolved route closed.
        if (! $model instanceof Model) {
            return $next($request);
        }

        // P1-04 slice 5: account-level check first (the model's account = the subject's), then the S13 owner/store check.
        abort_unless(TenantPolicy::allows($model) && $this->owns($model), 403);

        return $next($request);
    }

    /** Pull the bound model: an explicit param, else the first Eloquent binding. */
    private function resolveModel(Request $request, ?string $param): ?Model
    {
        if ($param !== null) {
            $bound = $request->route($param);

            return $bound instanceof Model ? $bound : null;
        }

        foreach ((array) $request->route()?->parameters() as $value) {
            if ($value instanceof Model) {
                return $value;
            }
        }

        return null;
    }

    /** Does $model belong to the current dashboard owner/staff scope? */
    private function owns(Model $model): bool
    {
        $storeIds = array_map('intval', function_exists('dashboard_store_ids') ? dashboard_store_ids() : []);
        $ownerId = function_exists('dashboard_owner_id') ? dashboard_owner_id() : auth()->id();

        // Models that opt into the shared trait resolve themselves.
        if (
            in_array(ScopesToOwner::class, class_uses_recursive($model), true)
            && method_exists($model, 'belongsToCurrentOwner')
        ) {
            return $model->belongsToCurrentOwner();
        }

        // The restaurant itself.
        if ($model instanceof Store) {
            return in_array((int) $model->getKey(), $storeIds, true)
                || ((int) $model->user_id === (int) $ownerId);
        }

        // Resolve the model's tenant by its most specific ownership column.
        if (isset($model->store_id)) {
            return in_array((int) $model->store_id, $storeIds, true);
        }

        if (isset($model->store_id)) {
            return in_array((int) $model->store_id, $storeIds, true);
        }

        if (isset($model->owner_id)) {
            return (int) $model->owner_id === (int) $ownerId;
        }

        if (isset($model->user_id)) {
            return (int) $model->user_id === (int) $ownerId;
        }

        // No recognisable ownership column → fail closed.
        return false;
    }
}
