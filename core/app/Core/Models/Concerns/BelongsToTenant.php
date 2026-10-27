<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Models\Concerns;

/**
 * P0-8: tenant-isolation backstop for OWNER-DASHBOARD queries.
 *
 * Deliberately NOT a global scope. Restaurants (and their menus) are
 * public-by-design — the QR storefront loads any restaurant by slug for
 * unauthenticated diners — so auto-scoping every query to the current user
 * would break public viewing. Instead this gives owner-facing controllers a
 * single audited filter to use in place of a raw where('user_id', ...), keyed
 * on the resolved dashboard owner (which already collapses staff → owner).
 */
trait BelongsToTenant
{
    /** Filter to rows owned by the current dashboard owner (owner or their staff's owner). */
    public function scopeOwnedByCurrentOwner($query)
    {
        return $query->where($this->getTable().'.user_id', static::currentOwnerId());
    }

    /** Filter to rows owned by a specific user id. */
    public function scopeOwnedBy($query, $userId)
    {
        return $query->where($this->getTable().'.user_id', $userId);
    }

    public static function currentOwnerId(): ?int
    {
        if (function_exists('dashboard_owner') && ($owner = dashboard_owner())) {
            return $owner->id;
        }

        return auth()->id();
    }
}
