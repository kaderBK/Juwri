<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Http\Controllers\User;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Services\StarterMenuService;

/**
 * C7 — one-click starter menu. Owner-only (the empty-state banner only shows the owner);
 * an unknown preset is a 422 (the `Rule::in` whitelist), and a store that already has menus
 * is refused with a friendly notice by the service. All the work lives in StarterMenuService.
 */
class StarterMenuController extends Controller
{
    public function __construct(protected StarterMenuService $service) {}

    public function seed(Request $request, Store $store): RedirectResponse
    {
        abort_unless((int) $store->user_id === (int) auth()->id(), 403);

        $data = $request->validate([
            'preset' => ['required', Rule::in(StarterMenuService::PRESETS)],
        ]);

        $result = $this->service->seed($store, $data['preset']);

        if (empty($result['seeded'])) {
            return back()->with('starter_error', ___('This menu already has items, so the template was not added.'));
        }

        return back()->with('starter_success', ___('Your starter menu is ready — edit anything you like.'));
    }
}
