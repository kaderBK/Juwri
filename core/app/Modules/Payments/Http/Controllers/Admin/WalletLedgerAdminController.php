<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Http\Controllers\Admin;

use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WalletLedgerAdminController extends Controller
{
    /**
     * Top-up ledger rows are written by CheckoutController::creditWalletTopup()
     * with reason ___('Wallet top-up') (locale-dependent at write time) and the
     * locale-proof idempotency ref 'topup-txn-{transaction_id}' — match either.
     */
    private const TOPUP_REF_PREFIX = 'topup-txn-';
    /** 'verify_topup' is a legacy reason from an earlier top-up flow; rows still exist. */
    private const TOPUP_REASONS    = ['Wallet top-up', 'verify_topup'];

    public function index(Request $request)
    {
        $query = WalletTransaction::query();

        if (in_array($request->get('type'), ['credit', 'debit'], true)) {
            $query->where('type', $request->get('type'));
        }

        if ($request->filled('reason')) {
            $query->where('reason', $request->get('reason'));
        }

        if ($request->filled('user')) {
            $term = trim((string) $request->get('user'));
            $userIds = User::where(function ($q) use ($term) {
                \App\Support\SearchTerm::apply($q, ['name', 'email'], $term);
            })->pluck('id');
            $query->whereIn('user_id', $userIds);
        }

        $transactions = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(50);

        // Map owners + granting admins for the current page in one query (no N+1).
        $users = User::whereIn('id', $transactions->pluck('user_id')
                ->merge($transactions->pluck('granted_by'))
                ->filter()->unique()->values())
            ->get(['id', 'name', 'email'])
            ->keyBy('id');

        $since = now()->subDays(30);
        $major = DB::raw(WalletTransaction::minorMoneySql('amount')); // P1-05: per-row exponent
        $credits30d = (float) WalletTransaction::where('type', 'credit')->where('created_at', '>=', $since)->sum($major);
        $debits30d = abs((float) WalletTransaction::where('type', 'debit')->where('created_at', '>=', $since)->sum($major));

        $stats = [
            'credits30d' => $credits30d,
            'debits30d'  => $debits30d,
            'net30d'     => $credits30d - $debits30d,
            'topups30d'  => WalletTransaction::where('created_at', '>=', $since)
                ->where(function ($q) {
                    $q->whereLike('ref', self::TOPUP_REF_PREFIX . '%')
                        ->orWhereIn('reason', self::TOPUP_REASONS);
                })->count(),
        ];

        $reasons = WalletTransaction::query()
            ->whereNotNull('reason')->where('reason', '!=', '')
            ->distinct()->orderBy('reason')->pluck('reason');

        return view('admin.wallet.index', compact('transactions', 'users', 'stats', 'reasons'));
    }
}
