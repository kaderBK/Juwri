<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Loyalty\Http\Controllers\User;

use App\Models\LoyaltyAccount;
use App\Models\LoyaltyProgram;
use App\Services\FeatureAccess;
use App\Services\Loyalty\LoyaltyClawbackNotice;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;

class LoyaltyController extends Controller
{
    private function ownerRestaurant(int $storeId): Store
    {
        return Store::where('id', $storeId)
            ->where('user_id', auth()->id())
            ->whereIn('id', dashboard_store_ids()) // BL-036: a staff session is bound to its own store
            ->firstOrFail();
    }

    // Owner: view/edit loyalty program settings
    public function settings(int $storeId)
    {
        $store = $this->ownerRestaurant($storeId);
        $program = LoyaltyProgram::forRestaurant($storeId);

        // Top 20 accounts by balance
        $accounts = LoyaltyAccount::where('store_id', $storeId)
            ->orderByDesc('balance')
            ->limit(20)
            ->get();

        return view('owner.marketing.loyalty', compact('store', 'program', 'accounts'));
    }

    public function updateSettings(Request $request, int $storeId)
    {
        $this->ownerRestaurant($storeId);

        $validated = $request->validate([
            'type' => 'required|in:stamps,points',
            'earn_rate' => 'required|integer|min:1|max:1000',
            'reward_threshold' => 'required|integer|min:1|max:9999',
            'reward_description' => 'nullable|string|max:200',
            'reward_discount' => 'nullable|numeric|min:0',
            'active' => 'nullable|boolean',
            'tiers' => 'nullable|array|max:10',
            'tiers.*.name' => 'nullable|string|max:40',
            'tiers.*.min' => 'nullable|integer|min:0|max:1000000',
        ]);

        // Keep only named tiers; normalise + sort by threshold.
        $tiers = collect($request->input('tiers', []))
            ->map(fn ($t) => ['name' => trim((string) ($t['name'] ?? '')), 'min' => max(0, (int) ($t['min'] ?? 0))])
            ->filter(fn ($t) => $t['name'] !== '')
            ->sortBy('min')->values()->all();
        unset($validated['tiers']);

        LoyaltyProgram::updateOrCreate(
            ['store_id' => $storeId],
            array_merge($validated, ['active' => $request->boolean('active'), 'tiers' => $tiers ?: null])
        );

        log_activity('loyalty.settings', null, ['store_id' => $storeId], $storeId);

        return back()->with('success', ___('Loyalty program updated.'));
    }

    // Customer-facing: balance and stamp card (shown in aurora via AJAX)
    public function customerBalance(Request $request, int $storeId)
    {
        $customer = auth('customer')->user();
        if (! $customer) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }
        // Customers belong to one store: never open a loyalty account for them elsewhere.
        if ((int) $customer->store_id !== $storeId) {
            return response()->json(['active' => false], 404);
        }

        // Gate on the STORE's entitlement, not the customer's — loyalty belongs to the
        // restaurant's plan. A downgrade doesn't flip LoyaltyProgram.active, so without
        // this an de-entitled store still leaks balance/tier/reward data here. Mirrors
        // the checkout strip + redeem gate (FeatureAccess::storeAllows, per the
        // feature-gate subject rule — the store is the subject on this auth:customer route).
        if (! FeatureAccess::storeAllows($storeId, 'loyalty')) {
            return response()->json(['active' => false]);
        }

        $program = LoyaltyProgram::where('store_id', $storeId)->where('active', true)->first();
        if (! $program) {
            return response()->json(['active' => false]);
        }

        $account = LoyaltyAccount::forCustomer($customer->id, $storeId);
        $ledger = $account->ledger()->orderByDesc('created_at')->limit(10)->get();

        // This route is not a storefront render, so nothing has pointed the currency
        // formatters at this store yet: reward_discount_display below would come back
        // in the platform currency and the checkout strip would offer "$10.00 off" on
        // a store whose every other price is in dirhams.
        storefront_apply_store_currency($storeId);

        return response()->json([
            'active' => true,
            'type' => $program->type,
            'balance' => (int) $account->balance,
            'reward_threshold' => $program->reward_threshold,
            'reward_description' => $program->reward_description,
            'reward_discount' => $program->reward_discount,
            // K3: tier name for the current balance + the reward value formatted in
            // the store's currency (3-decimal-safe) for the checkout redeem strip.
            'tier' => $program->tierFor((int) $account->balance),
            'reward_discount_display' => (float) $program->reward_discount > 0
                ? price_symbol_format((float) $program->reward_discount)
                : null,
            'progress_pct' => $account->progress_pct,
            'ready_to_redeem' => $account->balance >= $program->reward_threshold,
            // P6-253 (J46): a balance below zero (points from a refunded order were already spent) is
            // explained in plain words; redemption stays blocked until it is back above the reward cost.
            'negative' => (int) $account->balance < 0,
            'banner' => (int) $account->balance < 0
                ? LoyaltyClawbackNotice::negativeText((int) $account->balance, (int) $program->reward_threshold)
                : null,
            'ledger' => $ledger->map(fn ($l) => [
                'type' => $l->type,
                'delta' => $l->delta,
                // A refund claw-back row carries its order in redeemed_order_id with a negative delta.
                'text' => ($l->type === 'adjustment' && (int) $l->delta < 0 && $l->redeemed_order_id)
                    ? LoyaltyClawbackNotice::lineText((int) $l->redeemed_order_id, abs((int) $l->delta))
                    : null,
                'note' => $l->note,
                'created_at' => $l->created_at->diffForHumans(),
            ]),
        ]);
    }

    // Customer: redeem reward
    public function redeem(Request $request, int $storeId)
    {
        $customer = auth('customer')->user();
        abort_unless($customer, 401);
        abort_unless((int) $customer->store_id === $storeId, 404);

        // Store-entitlement gate (see customerBalance): a plan downgrade leaves the
        // program row 'active', so without this a crafted POST could still debit points
        // + return a reward on a store that no longer pays for loyalty.
        if (! FeatureAccess::storeAllows($storeId, 'loyalty')) {
            return response()->json(['error' => ___('Loyalty is not available here.')], 404);
        }

        $program = LoyaltyProgram::where('store_id', $storeId)->where('active', true)->firstOrFail();
        $account = LoyaltyAccount::forCustomer($customer->id, $storeId);

        // A2 fix: the balance check + debounce + debit are one atomic, row-locked
        // step (redeemForReward). The old split — balance/recency read here, then
        // redeem() — let two concurrent requests both pass the recency gate and
        // double-debit when the balance covered 2x the threshold.
        $status = $account->redeemForReward((int) $program->reward_threshold, 'Reward redeemed');
        if ($status === 'insufficient') {
            return response()->json(['error' => ___('Not enough points to redeem.')], 422);
        }
        if ($status === 'recent') {
            return response()->json(['error' => ___('You just redeemed a reward — please wait a moment.')], 429);
        }

        log_activity('loyalty.redeem', null, ['customer_id' => $customer->id, 'discount' => $program->reward_discount], $storeId);

        return response()->json([
            'success' => true,
            'new_balance' => $account->balance,
            'reward_discount' => $program->reward_discount,
            'reward_description' => $program->reward_description,
        ]);
    }
}
