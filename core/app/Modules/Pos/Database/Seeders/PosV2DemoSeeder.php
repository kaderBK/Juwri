<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Database\Seeders;

use App\Models\Coupon;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreTable;
use App\Services\CustomerWalletService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariantOption;

/**
 * POS-V2 P0-5 — demo data for the register verification matrix on one store
 * (legacy dev DB: #2695 Pizza Paradise; otherwise the themepack-demo store, or E2E_POS_STORE_SLUG). Idempotent (safe to re-run) and guarded to non-production
 * so it can never touch a live DB. It creates/normalises: a customer with store
 * credit + loyalty points, an active coupon, ≥6 tables across statuses and a held
 * order. The choice-group item already exists (Margherita: priced variants +
 * multi-select extras), so it is asserted, not created.
 */
class PosV2DemoSeeder extends Seeder
{
    /** Legacy dev-DB id of Pizza Paradise (kept so the original dev database still seeds the same store). */
    private const LEGACY_RID = 2695;

    /** Fresh/sqlite databases have no such id: the themepack demo store (the one the staff manager belongs to). */
    private const DEFAULT_SLUG = 'themepack-demo';

    /** The store being seeded; resolved in run() (id 2695 where it exists, else by slug). */
    private int $rid = 0;

    /** E2E_POS_STORE_SLUG wins; else the legacy id when present; else the themepack demo store. */
    private function resolveStore(): ?Store
    {
        $slug = (string) (getenv('E2E_POS_STORE_SLUG') ?: '');
        if ($slug !== '') {
            return Store::where('slug', $slug)->first();
        }

        return Store::find(self::LEGACY_RID) ?? Store::where('slug', self::DEFAULT_SLUG)->first();
    }

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('PosV2DemoSeeder skipped: refusing to run in production.');

            return;
        }

        $store = $this->resolveStore();
        if (! $store) {
            $this->command?->warn('PosV2DemoSeeder skipped: no store to seed (E2E_POS_STORE_SLUG, legacy #'.self::LEGACY_RID.' or '.self::DEFAULT_SLUG.').');

            return;
        }
        $this->rid = (int) $store->id;

        $branchId = Branch::where('store_id', $this->rid)->orderBy('id')->value('id');

        // ── choice-group item (already seeded) + a PLU for the scanner (T1-6) ──
        $item = Menu::where('store_id', $this->rid)->has('variants')->with('variants')->first();
        if ($item && Schema::hasColumn('items', 'plu') && empty($item->plu)) {
            $item->forceFill(['plu' => '1001'])->save();
        }
        // T6-3: proper choice-groups (variant-options) on the item — a required
        // single-select (min1/max1) + a multi-select (0–3) with option_prices.
        if ($item && Schema::hasTable('modifier_groups')) {
            $gSize = MenuVariantOption::updateOrCreate(
                ['store_id' => $this->rid, 'title' => 'POS Size'],
                ['is_global' => false, 'menu_id' => $item->id, 'options' => ['Small', 'Medium', 'Large'],
                    'option_prices' => [0, 2, 4], 'required' => true, 'min_select' => 1, 'max_select' => 1, 'active' => 1]
            );
            $gTop = MenuVariantOption::updateOrCreate(
                ['store_id' => $this->rid, 'title' => 'POS Toppings'],
                ['is_global' => false, 'menu_id' => $item->id, 'options' => ['Cheese', 'Olives', 'Mushroom'],
                    'option_prices' => [1, 1, 2], 'required' => false, 'min_select' => 0, 'max_select' => 3, 'active' => 1]
            );
            $item->attachedVariantOptions()->syncWithoutDetaching([
                $gSize->id => ['active' => true, 'position' => 0],
                $gTop->id => ['active' => true, 'position' => 1],
            ]);
        }

        // ── customer + store credit + loyalty ───────────────────────────────
        $customer = Customer::updateOrCreate(
            ['store_id' => $this->rid, 'phone' => '0500000001'],
            ['name' => 'POS Demo Diner', 'email' => 'posdemo@example.test', 'source' => 'seed']
        );
        if (CustomerWalletService::balanceFor($customer) < 0.01) {
            CustomerWalletService::credit($customer, 50.00, 'POS-V2 demo seed', 'posv2-demo:wallet:'.$customer->id);
        }
        $loyalty = LoyaltyAccount::firstOrNew(['customer_id' => $customer->id, 'store_id' => $this->rid]);
        if ((int) ($loyalty->balance ?? 0) < 120) {
            $loyalty->forceFill([
                'customer_id' => $customer->id,
                'store_id' => $this->rid,
                'balance' => 120,
                'lifetime_earned' => max(120, (int) ($loyalty->lifetime_earned ?? 0)),
                'lifetime_redeemed' => (int) ($loyalty->lifetime_redeemed ?? 0),
            ])->save();
        }

        // ── active coupon ───────────────────────────────────────────────────
        $coupon = Coupon::updateOrCreate(
            ['code' => 'POSV2DEMO'],
            [
                'user_id' => $store->user_id,
                'percentage' => 10,
                'limit' => 1000,
                'used' => 0,
                'expiry_at' => Carbon::now()->addYear(),
                'applies_to' => 'all',
            ]
        );

        // ── ≥6 tables across statuses ───────────────────────────────────────
        $tableSpec = [
            [1, 2, 'free'], [2, 4, 'occupied'], [3, 4, 'reserved'],
            [4, 6, 'cleaning'], [5, 2, 'free'], [6, 8, 'free'],
        ];
        $tableIds = [];
        foreach ($tableSpec as [$no, $seats, $status]) {
            $t = StoreTable::firstOrNew(['store_id' => $this->rid, 'table_no' => $no]);
            $t->forceFill([
                'store_id' => $this->rid,
                'branch_id' => $branchId,
                'table_no' => $no,
                'label' => 'T'.$no,
                'seats' => $seats,
                'status' => $status,
            ])->save();
            $tableIds['T'.$no] = $t->id;
        }

        // ── held order (T4 resume/history surface) ──────────────────────────
        $held = Order::firstOrNew(['store_id' => $this->rid, 'client_uuid' => 'posv2-demo-held']);
        if (! $held->exists) {
            $held->forceFill([
                'store_id' => $this->rid,
                'branch_id' => $branchId,
                'type' => 'takeaway',
                'status' => 'new',
                'is_paid' => 0,
                'customer_name' => 'POS Demo Diner',
                'customer_id' => $customer->id,
                'held_at' => Carbon::now(),
                'client_uuid' => 'posv2-demo-held',
            ])->save();
            if ($item) {
                OrderItem::create([
                    'order_id' => $held->id,
                    'item_id' => $item->id,
                    'quantity' => 2,
                    'variation' => $item->variants->first()->id ?? null,
                    'order_note' => 'demo held line',
                    'status' => 'new',
                ]);
            }
        }

        $this->command?->info(json_encode([
            'choice_group_item' => $item?->id,
            'customer' => $customer->id,
            'wallet_balance' => CustomerWalletService::balanceFor($customer),
            'loyalty_points' => LoyaltyAccount::where('customer_id', $customer->id)->value('balance'),
            'coupon' => $coupon->code,
            'tables' => $tableIds,
            'held_order' => $held->id,
        ], JSON_PRETTY_PRINT));
    }
}
