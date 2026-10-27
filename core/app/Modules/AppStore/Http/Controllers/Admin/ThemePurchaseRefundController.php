<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\AppStore\Http\Controllers\Admin;

use App\Services\ThemePurchaseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\AppStore\Models\ThemePurchase;

/** P6-243: admin "Refund purchase" for a theme licence (reason required; app installs refund through RefundService). */
class ThemePurchaseRefundController extends Controller
{
    public function refund(Request $request, int $id, ThemePurchaseService $purchases): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $purchase = ThemePurchase::findOrFail($id);

        if (! $purchases->refund($purchase, (int) auth()->id(), $data['reason'])) {
            return back()->with('error', ___('This theme purchase was already refunded.'));
        }

        return back()->with('success', ___('Theme purchase refunded.'));
    }
}
