<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Http\Controllers\Admin;

use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Services\WalletService;
use Illuminate\Http\Request;

class WalletAdminController extends Controller
{
    public function grantCredit(Request $request, User $user)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01|max:9999',
            'reason' => 'nullable|string|max:120',
        ]);

        $newBalance = WalletService::credit(
            $user,
            (float) $validated['amount'],
            $validated['reason'] ?? 'Admin grant',
            'admin-' . uniqid(),
            auth()->id()
        );

        log_activity('wallet.admin_grant', $user, [
            'amount'     => $validated['amount'],
            'new_balance'=> $newBalance,
            'admin_id'   => auth()->id(),
        ]);

        return back()->with('success', ___(':amount credit added to :name (balance: :balance)', [
            'amount'  => price_symbol_format((float) $validated['amount']),
            'name'    => $user->name,
            'balance' => price_symbol_format($newBalance),
        ]));
    }
}
