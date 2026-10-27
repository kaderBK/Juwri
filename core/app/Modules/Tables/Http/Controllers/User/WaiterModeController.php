<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Tables\Http\Controllers\User;

use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Concerns\ResolvesCurrentStore;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Tables\Models\WaiterCall;

/**
 * SYSTEMS-RUN W: owner waiter-mode console — recent calls + a link to the staff
 * PWA waiter tab. Behind waiter_mode.
 */
class WaiterModeController extends Controller
{
    use ResolvesCurrentStore;

    public function index(Request $request)
    {
        $rid = $this->currentRestaurantId($request);

        return view('owner.orders.waiter', [
            'rid' => $rid,
            'calls' => WaiterCall::where('store_id', $rid)->orderByDesc('id')->limit(30)->get(),
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }
}
