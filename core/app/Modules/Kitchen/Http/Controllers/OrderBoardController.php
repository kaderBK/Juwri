<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Kitchen\Http\Controllers;

use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Modules\Kitchen\Services\OrderBoard\OrderBoardService;

/**
 * PARITY-RUN T2-2: the PUBLIC order-status board — /board/{slug} (+ ?branch=N).
 *
 * Anonymous by design: a TV in the dining room has no session and no device
 * credential, the store slug is the whole address. That is exactly why the
 * feature gate lives HERE and not in `feature:`/`entitled:` middleware — a
 * plan-gate middleware on a public route 403s every visitor (this codebase has
 * made that mistake three times; see FeatureAccess's docblock).
 *
 * Every refusal is a 404, matching the signage boards: an unknown store, a
 * killed feature and a store that never turned the board on are indistinguishable
 * from outside, which is the correct answer for an unauthenticated page.
 */
class OrderBoardController extends Controller
{
    public function show(Request $request, string $slug)
    {
        [$store, $branch] = $this->resolve($request, $slug);

        return view('board.index', [
            'store' => $store,
            'branch' => $branch,
            'data' => OrderBoardService::payload($store, $branch?->id),
            'boardUrl' => OrderBoardService::url($store, $branch?->id),
        ]);
    }

    public function state(Request $request, string $slug)
    {
        [$store, $branch] = $this->resolve($request, $slug);

        return response()->json(OrderBoardService::payload($store, $branch?->id));
    }

    /**
     * @return array{0: Store, 1: Branch|null}
     */
    private function resolve(Request $request, string $slug): array
    {
        $store = Store::where('slug', $slug)->first();
        abort_unless($store, 404);
        abort_unless(OrderBoardService::visible((int) $store->id), 404);

        $branch = null;
        $branchId = (int) $request->query('branch');
        if ($branchId) {
            // A branch from another store is simply ignored — never a cross-tenant read.
            $branch = Branch::where('id', $branchId)->where('store_id', $store->id)->first();
        }

        return [$store, $branch];
    }
}
