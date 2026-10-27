<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use Juwri\Modules\Menu\Models\Menu;
use App\Models\Order;
use App\Models\OrderItem;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * PARITY-RUN follow-up #1: pins `parity:modifier-undercharge`.
 *
 * The command reconstructs lost money from a FORMATTED STRING, which is fragile
 * by nature — a locale that groups thousands, a line with two paid options, or a
 * quantity greater than one each change the answer. Those are exactly the cases
 * that must not drift, because the number this prints is the one an owner will
 * use to decide whether to repair history.
 */
class ModifierUnderchargeMeasureTest extends TestCase
{
    use RefreshDatabase;

    private function store(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'Measured', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A pre-fix line: picks in the note, `options` still NULL. */
    private function legacyLine(Store $r, string $note, int $qty = 1, array $orderAttrs = []): Order
    {
        $order = Order::create(array_merge([
            'store_id' => $r->id, 'type' => 'takeaway', 'status' => 'new',
            'track_token' => Str::random(32), 'created_at' => now(),
        ], $orderAttrs));

        $menu = Menu::create(['store_id' => $r->id, 'name' => 'Pizza', 'price' => 10, 'active' => '1']);
        OrderItem::create([
            'order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => $qty, 'order_note' => $note,
        ]);

        return $order;
    }

    public function test_it_reports_nothing_when_no_line_carries_an_upcharge(): void
    {
        $r = $this->store();
        $this->legacyLine($r, 'size: Mid');          // a free option writes no (+N)
        $this->legacyLine($r, 'no onions please');   // a plain customer note

        $this->artisan('parity:modifier-undercharge')
            ->expectsOutputToContain('No affected orders found')
            ->assertExitCode(0);
    }

    public function test_it_sums_every_paid_option_on_a_line_and_multiplies_by_quantity(): void
    {
        $r = $this->store();
        // Two paid options on one line, quantity 3 → (2.00 + 1.50) × 3 = 10.50.
        // The pre-fix code did `$amount += $optPrice * $qty` while writing the UNIT
        // price into the note, so the multiplication belongs here.
        $this->legacyLine($r, "size: Large (+2.00)\nextras: Cheese (+1.50)", 3);

        $this->artisan('parity:modifier-undercharge')
            ->expectsOutputToContain('10.50')
            ->assertExitCode(0);
    }

    public function test_it_parses_a_thousands_separated_amount(): void
    {
        $r = $this->store();
        // number_format() on a 3-decimal currency store, or simply a large upcharge.
        $this->legacyLine($r, 'catering: Whole tray (+1,250.00)');

        $this->artisan('parity:modifier-undercharge')
            ->expectsOutputToContain('1,250.00')
            ->assertExitCode(0);
    }

    public function test_a_line_fixed_after_wv_v_is_not_counted_twice(): void
    {
        $r = $this->store();
        $order = $this->legacyLine($r, 'size: Large (+2.00)');

        // Post-fix rows carry the snapshot; the accessor already charges them, so
        // counting the note again would double-report money that is not missing.
        OrderItem::where('order_id', $order->id)->update([
            'options' => [['group_id' => 1, 'title' => 'size', 'selected' => [['option' => 'Large', 'price' => 2.0]]]],
        ]);

        $this->artisan('parity:modifier-undercharge')
            ->expectsOutputToContain('No affected orders found')
            ->assertExitCode(0);
    }

    public function test_it_separates_money_never_taken_from_money_taken_but_misrecorded(): void
    {
        $r = $this->store();
        $this->legacyLine($r, 'size: Large (+2.00)');                                   // no gateway → LOST
        $this->legacyLine($r, 'size: Large (+5.00)', 1, ['payment_gateway' => 'stripe']); // gateway → MISMATCH

        // The two lanes mean opposite things and must never be summed: one is
        // revenue that was never collected, the other is revenue that WAS collected
        // while the order record understates it.
        $this->artisan('parity:modifier-undercharge')
            ->expectsOutputToContain('LOST      1 order(s), 2.00')
            ->expectsOutputToContain('MISMATCH  1 order(s), 5.00')
            ->assertExitCode(0);
    }

    public function test_the_store_filter_scopes_the_measurement(): void
    {
        $a = $this->store();
        $b = $this->store();
        $this->legacyLine($a, 'size: Large (+2.00)');
        $this->legacyLine($b, 'size: Large (+9.00)');

        $this->artisan('parity:modifier-undercharge', ['--store' => $a->id])
            ->expectsOutputToContain('2.00')
            ->doesntExpectOutputToContain('9.00')
            ->assertExitCode(0);
    }

    public function test_it_writes_nothing(): void
    {
        $r = $this->store();
        $order = $this->legacyLine($r, 'size: Large (+2.00)');
        $before = OrderItem::where('order_id', $order->id)->first()->toArray();

        $this->artisan('parity:modifier-undercharge')->assertExitCode(0);

        // The whole point of the command is that it measures and does not repair.
        $this->assertSame($before, OrderItem::where('order_id', $order->id)->first()->toArray());
        $this->assertNull(OrderItem::where('order_id', $order->id)->first()->options);
    }
}
