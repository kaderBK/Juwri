<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Http\Controllers\User;

use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Storefront\Services\ThemeUpdateService;

/**
 * P4-7: owner-facing theme updates — list restaurants with a newer published
 * version of their marketplace theme, and move one when the owner chooses
 * (settings survive by design; optional semver pins an older published one).
 */
class ThemeUpdatesController extends Controller
{
    public function index(ThemeUpdateService $updates)
    {
        return response()->json(['updates' => $updates->updatesAvailable((int) auth()->id())]);
    }

    public function apply(Request $request, int $storeId, ThemeUpdateService $updates)
    {
        $store = Store::where('id', $storeId)->where('user_id', auth()->id())->firstOrFail();

        try {
            $template = $updates->updateRestaurant($store, $request->input('version'));
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'template' => $template]);
    }
}
