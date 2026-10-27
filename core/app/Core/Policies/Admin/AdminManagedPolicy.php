<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Policies\Admin;

use Juwri\Core\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Records that only the platform superadmin manages from the admin panel
 * (catalogues, site content, billing set-up). Each managed model gets its own
 * small subclass so a model can later widen access without touching others.
 *
 * Abilities: the resource set (viewAny, view, create, update, delete) plus
 * `deleteAny` (bulk delete) and `reorder` (drag-and-drop position).
 */
abstract class AdminManagedPolicy
{
    protected function manages(User $user): bool
    {
        return $user->isAdmin();
    }

    public function viewAny(User $user): bool
    {
        return $this->manages($user);
    }

    public function view(User $user, Model $record): bool
    {
        return $this->manages($user);
    }

    public function create(User $user): bool
    {
        return $this->manages($user);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->manages($user);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->manages($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->manages($user);
    }

    public function reorder(User $user): bool
    {
        return $this->manages($user);
    }
}
