<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Controllers\Api\V1\Concerns;

use Juwri\Core\Models\Store;
use Illuminate\Http\Request;

/**
 * Shared helpers for owner-token API controllers. Every lookup is scoped to the
 * token owner so one restaurant can never read or mutate another's data.
 */
trait OwnerScoped
{
    protected function ownerId(Request $request): int
    {
        return (int) $request->attributes->get('_api_user_id');
    }

    /** The restaurant (Post) if it belongs to the token owner, else 404. */
    protected function ownedRestaurant(Request $request, int $id): Store
    {
        return Store::where('user_id', $this->ownerId($request))->findOrFail($id);
    }
}
