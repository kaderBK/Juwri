<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Policies;

use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;

/**
 * P0-8: ownership authorization for restaurant MANAGEMENT actions (dashboard
 * view/edit/delete). This is the manage-side gate — it does not touch the
 * public storefront, which is intentionally unauthenticated.
 */
class StorePolicy
{
    /** Superadmins bypass ownership checks. */
    public function before(User $user, string $ability): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function manage(User $user, Store $store): bool
    {
        return (int) $store->user_id === (int) $user->id;
    }

    public function view(User $user, Store $store): bool
    {
        return $this->manage($user, $store);
    }

    public function update(User $user, Store $store): bool
    {
        return $this->manage($user, $store);
    }

    public function delete(User $user, Store $store): bool
    {
        return $this->manage($user, $store);
    }
}
