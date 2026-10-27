<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Models\Order;
use App\Models\StoreOption;
use App\Services\OrderApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P1-7: orders:backfill-totals (alias of orders:snapshot-totals) fills the
 * subtotal/tax_total/grand_total snapshot columns on historical orders using
 * the SAME derivation as the ->total accessor (tax on the PRE-discount
 * subtotal — theme-totals contract). Idempotent, chunked, with --dry-run.
 */
class BackfillTotalsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function storeFixture(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1']),
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Seed orders (items + tax + one discounted), then null their snapshots quietly. */
    private function seedLegacyOrders(Store $r): array
    {
        StoreOption::updateStoreOption($r->id, 'restaurant_taxx_charge', '10');
        StoreOption::updateStoreOption($r->id, 'restaurant_tax_type', 'percentage');
        $item = Menu::create(['store_id' => $r->id, 'name' => 'Dish', 'price' => 12.5, 'active' => 1]);

        $ids = [];
        foreach ([[2, 0.0], [1, 3.0], [4, 0.0]] as [$qty, $discount]) {
            $o = OrderApiService::create((int) $r->user_id, $r->id, [
                'type' => 'takeaway',
                'items' => [['item_id' => $item->id, 'quantity' => $qty]],
            ]);
            if ($discount > 0) {
                $o->refresh();
                $o->discount_amount = $discount;
                $o->save();
            }
            $ids[] = $o->id;
        }

        Order::withoutEvents(function () use ($ids) {
            Order::whereIn('id', $ids)->update(['subtotal_minor' => null, 'tax_total_minor' => null, 'grand_total_minor' => null]);
        });

        return $ids;
    }

    public function test_backfill_alias_fills_snapshots_matching_the_accessor(): void
    {
        $r = $this->storeFixture();
        $ids = $this->seedLegacyOrders($r);

        $this->assertSame(count($ids), Order::whereIn('id', $ids)->whereNull('grand_total_minor')->count());

        $this->artisan('orders:backfill-totals')->assertExitCode(0);

        $this->assertSame(0, Order::whereIn('id', $ids)->whereNull('grand_total_minor')->count());

        // Stored snapshot == live accessor for EVERY row — compared via
        // ->get() model reads, never pluck (pluck bypasses accessors).
        foreach (Order::whereIn('id', $ids)->get() as $order) {
            $this->assertEqualsWithDelta(
                (float) $order->total,
                (float) $order->grand_total,
                0.001,
                "Order #{$order->id}: stored grand_total must equal the accessor."
            );
            $money = $order->computeMoney();
            $this->assertEqualsWithDelta($money['subtotal'], (float) $order->subtotal, 0.001);
            $this->assertEqualsWithDelta($money['tax'], (float) $order->tax_total, 0.001);
        }
    }

    public function test_dry_run_writes_nothing_but_reports_the_pending_count(): void
    {
        $r = $this->storeFixture();
        $ids = $this->seedLegacyOrders($r);

        $this->artisan('orders:backfill-totals', ['--dry-run' => true])
            ->expectsOutputToContain('Would snapshot '.count($ids).' of '.count($ids))
            ->assertExitCode(0);

        $this->assertSame(count($ids), Order::whereIn('id', $ids)->whereNull('grand_total_minor')->count(), 'dry-run must not write');
    }

    public function test_backfill_is_idempotent(): void
    {
        $r = $this->storeFixture();
        $ids = $this->seedLegacyOrders($r);

        $this->artisan('orders:backfill-totals')->assertExitCode(0);
        $after = Order::whereIn('id', $ids)->get()->map(fn ($o) => (string) $o->grand_total);

        // Second run touches nothing (only NULL rows are selected) …
        $this->artisan('orders:backfill-totals')
            ->expectsOutputToContain('Snapshotted 0 of 0')
            ->assertExitCode(0);
        // … and values are unchanged.
        $this->assertEquals($after, Order::whereIn('id', $ids)->get()->map(fn ($o) => (string) $o->grand_total));
    }
}
