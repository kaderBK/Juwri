<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Models\Concerns;

use Juwri\Core\Models\Store;

/**
 * S13: consolidated owner-scoping for models that belong to a tenant via their
 * RESTAURANT (a `store_id` foreign key on the Post), replacing
 * the ~23 hand-rolled private `ownerRestaurantIds()` helpers scattered across
 * the owner-facing controllers (BranchController, SplitBillController,
 * StaffController, Inventory/Kds/Reservation/Waste…).
 *
 * For models keyed DIRECTLY on `user_id` (e.g. Post itself) use
 * {@see BelongsToTenant} instead — that is the owner column, not a restaurant
 * foreign key.
 *
 * Deliberately NOT a global scope: restaurants and their menus are
 * public-by-design (the QR storefront loads any restaurant by slug for
 * unauthenticated diners), so auto-scoping every query would break public
 * viewing. This is an opt-in, audited filter for owner-facing queries.
 *
 * Override the tenant foreign key per-model with a `$tenantForeignKey`
 * property (defaults to `store_id`).
 */
trait ScopesToOwner
{
    /** Column on this model that references the owning restaurant (Post) id. */
    public function tenantForeignKey(): string
    {
        return property_exists($this, 'tenantForeignKey') && is_string($this->tenantForeignKey)
            ? $this->tenantForeignKey
            : 'store_id';
    }

    /** Restaurant (Post) ids owned by a specific user. */
    public static function restaurantIdsForOwner(?int $ownerId): array
    {
        if (function_exists('owner_store_ids')) {
            return owner_store_ids($ownerId);
        }

        if (! $ownerId) {
            return [];
        }

        return Store::where('user_id', $ownerId)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Restaurant ids in the CURRENT dashboard scope. Staff-aware: collapses a
     * staff session to the single restaurant they belong to, an owner to all of
     * theirs (mirrors dashboard_store_ids()).
     */
    public static function currentTenantRestaurantIds(): array
    {
        if (function_exists('dashboard_store_ids')) {
            return array_map('intval', dashboard_store_ids());
        }

        return static::restaurantIdsForOwner(auth()->id());
    }

    /** Filter to rows whose restaurant is owned by $ownerId (all their stores). */
    public function scopeForOwner($query, ?int $ownerId)
    {
        return $query->whereIn(
            $this->getTable().'.'.$this->tenantForeignKey(),
            static::restaurantIdsForOwner($ownerId)
        );
    }

    /** Filter to rows within the current dashboard owner/staff scope. */
    public function scopeForCurrentOwner($query)
    {
        return $query->whereIn(
            $this->getTable().'.'.$this->tenantForeignKey(),
            static::currentTenantRestaurantIds()
        );
    }

    /** Does this row belong to $ownerId's tenant (any of their restaurants)? */
    public function belongsToOwner(?int $ownerId): bool
    {
        return in_array(
            (int) $this->{$this->tenantForeignKey()},
            static::restaurantIdsForOwner($ownerId),
            true
        );
    }

    /** Does this row fall within the current dashboard owner/staff scope? */
    public function belongsToCurrentOwner(): bool
    {
        return in_array(
            (int) $this->{$this->tenantForeignKey()},
            static::currentTenantRestaurantIds(),
            true
        );
    }
}
