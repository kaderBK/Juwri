<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Http\Controllers\User;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Payments\Contracts\AbandonedCheckouts;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Support\AccountPages;

/**
 * BL-038 review: the buyer came back through the gateway's (signed) cancel link of a platform purchase — the checkout is
 * abandoned when it is provably closed (its Stripe session expired; what it held handed back), then the buyer lands on
 * the account page as before. Only the buyer's own open row; anything else is a plain redirect.
 */
class CheckoutCancelledController extends Controller
{
    public function cancelled(Request $request, string $transaction): RedirectResponse
    {
        // Signed link only; an expired link or another host just lands on the account page (never a 403).
        if (! $request->hasValidSignature()) {
            return redirect()->to(AccountPages::url());
        }
        $row = Transaction::query()->withoutGlobalScopes()->where('public_id', $transaction)
            ->where('user_id', (int) $request->user()?->id)
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', Transaction::STATUS_RECURRING_SETUP))->first();
        if ($row !== null) {
            app(AbandonedCheckouts::class)->abandon($row);
        }

        return redirect()->to(AccountPages::url());
    }
}
