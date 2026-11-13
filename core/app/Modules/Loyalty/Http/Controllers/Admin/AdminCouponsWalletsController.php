<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Loyalty\Http\Controllers\Admin;

use Juwri\Core\Http\Controllers\Controller;
use App\Models\Coupon;
use Juwri\Modules\Loyalty\Models\CouponRedemption;
use Juwri\Modules\Payments\Models\LedgerAccount;
use Juwri\Modules\Payments\Models\LedgerEntry;
use App\Services\PlatformMetrics;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Coupons & Wallets P6-1: superadmin oversight — one place to see every wallet
 * tier's balances and every coupon tier's redemption stats, straight off the
 * unified ledger and the one Coupon table.
 */
class AdminCouponsWalletsController extends Controller
{
    public function overview()
    {
        $data = $this->buildOverview();

        // JSON for API callers (and the P6-1 test); the Blade page for the panel.
        if (request()->wantsJson()) {
            return response()->json($data);
        }

        return view('admin.coupons_wallets.overview', $data);
    }

    /** @return array{wallets: array, coupons: Collection, claimDeniedToday: int, overRedeemed: int, perCustomerDeniedToday: int, redemptionsTracked: int} */
    private function buildOverview(): array
    {
        // Wallet balances per kind (platform / reseller / merchant /
        // customer_store_credit), authoritative from the ledger entries.
        // Raw column expressions aren't run through the table-prefix wrapper.
        $p = DB::getTablePrefix();
        $balances = DB::table('ledger_entries')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.ledger_account_id')
            ->groupBy('ledger_accounts.kind')
            // P1-05: each entry at its own currency exponent (the old decimal SUM added numbers across currencies too).
            ->selectRaw("{$p}ledger_accounts.kind as kind, SUM(".LedgerEntry::minorMoneySql('amount', "{$p}ledger_entries.").') as balance')
            ->pluck('balance', 'kind');

        $accountCounts = LedgerAccount::groupBy('kind')
            ->selectRaw('kind, COUNT(*) as accounts')
            ->pluck('accounts', 'kind');

        $wallets = [];
        foreach (['platform', 'reseller', 'merchant', 'customer_store_credit'] as $kind) {
            $wallets[$kind] = [
                'accounts' => (int) ($accountCounts[$kind] ?? 0),
                'balance' => round((float) ($balances[$kind] ?? 0), 2),
            ];
        }

        // Coupon redemption stats per issuer scope.
        $coupons = Coupon::groupBy('issuer_scope')
            ->selectRaw('issuer_scope, COUNT(*) as count, SUM(used) as total_used')
            ->get()
            ->map(fn ($r) => [
                'issuer_scope' => $r->issuer_scope,
                'count' => (int) $r->count,
                'total_used' => (int) $r->total_used,
            ])->values();

        return [
            'wallets' => $wallets,
            'coupons' => $coupons,
            // C5 operator readout: today's denied claims across every rail, and the
            // pre-fix damage counter (used > limit) which can now only ever fall.
            'claimDeniedToday' => PlatformMetrics::todayCount('coupon.claim_denied'),
            'overRedeemed' => Coupon::where('limit', '>', 0)->whereColumn('used', '>', 'limit')->count(),
            // M3 operator readout: per-customer-gate health.
            'perCustomerDeniedToday' => PlatformMetrics::todayCount('coupon.per_customer_denied'),
            'redemptionsTracked' => CouponRedemption::count(),
        ];
    }
}
