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
 * PARITY-RUN follow-up #2: pins `parity:modifier-repair`.
 *
 * This command rewrites historical order totals, so its SAFETY properties are
 * what these tests defend: it does nothing without --commit, it never touches
 * the MISMATCH lane, it preserves order_note, it refuses to guess an
 * unparseable note, and it never double-counts a line already covered. The
 * happy path (the total actually rises by the recovered amount) is checked too,
 * but the guards are the point.
 */
class ModifierRepairTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $this->store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'R',
            'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png',
            'address' => '1 St', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A pre-fix line: picks in the note as text, options NULL. */
    private function legacyOrder(string $note, int $qty = 1, array $orderAttrs = [], float $price = 10): Order
    {
        $order = Order::create(array_merge([
            'store_id' => $this->store->id, 'type' => 'takeaway', 'status' => 'new',
            'track_token' => Str::random(32), 'created_at' => now(),
        ], $orderAttrs));
        $menu = Menu::create(['store_id' => $this->store->id, 'name' => 'Pizza', 'price' => $price, 'active' => '1']);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => $qty, 'order_note' => $note]);

        return $order;
    }

    private function line(Order $o): OrderItem
    {
        return OrderItem::where('order_id', $o->id)->first();
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $o = $this->legacyOrder('size: Large (+2.00)');

        $this->artisan('parity:modifier-repair', ['--store' => $this->store->id])
            ->expectsOutputToContain('DRY RUN')
            ->assertExitCode(0);

        $this->assertNull($this->line($o)->fresh()->options, 'a dry run must not write options');
    }

    public function test_commit_writes_the_snapshot_and_the_total_rises(): void
    {
        $o = $this->legacyOrder('size: Large (+2.00)', qty: 2, price: 10);
        $before = (float) $o->fresh()->total; // 20.00, upcharge absent

        $this->artisan('parity:modifier-repair', ['--store' => $this->store->id, '--commit' => true])
            ->assertExitCode(0);

        $item = $this->line($o)->fresh();
        $this->assertIsArray($item->options);
        $this->assertTrue($item->options[0]['repaired']);
        $this->assertEquals(2.0, $item->options[0]['selected'][0]['price']);

        // total rose by unit-price × quantity = 2.00 × 2 = 4.00.
        $this->assertEquals($before + 4.0, (float) $o->fresh()->total);
    }

    public function test_the_note_is_preserved_as_the_audit_trail(): void
    {
        $o = $this->legacyOrder('size: Large (+2.00)');
        $this->artisan('parity:modifier-repair', ['--store' => $this->store->id, '--commit' => true])->assertExitCode(0);

        $this->assertSame('size: Large (+2.00)', $this->line($o)->fresh()->order_note);
    }

    public function test_a_gateway_order_is_never_touched(): void
    {
        // MISMATCH lane: the customer already paid the quoted amount.
        $o = $this->legacyOrder('size: Large (+2.00)', orderAttrs: ['payment_gateway' => 'stripe']);

        $this->artisan('parity:modifier-repair', ['--store' => $this->store->id, '--commit' => true])->assertExitCode(0);

        $this->assertNull($this->line($o)->fresh()->options, 'a gateway-paid order must be left alone');
    }

    public function test_it_refuses_an_unparseable_upcharge_rather_than_guess(): void
    {
        // Has a real (+N) upcharge marker but no colon, so it is NOT a clean
        // "Group: Option (+price)" — the command must skip it, not guess.
        $o = $this->legacyOrder('size Large (+2.00)');

        $this->artisan('parity:modifier-repair', ['--store' => $this->store->id, '--commit' => true])
            ->expectsOutputToContain('Skipped')
            ->assertExitCode(0);

        $this->assertNull($this->line($o)->fresh()->options, 'an unparseable line must be skipped, not guessed');
    }

    public function test_a_line_already_carrying_options_is_not_double_counted(): void
    {
        $o = $this->legacyOrder('size: Large (+2.00)');
        $this->line($o)->update(['options' => [['group_id' => 1, 'title' => 'size', 'selected' => [['option' => 'Large', 'price' => 2.0]]]]]);
        $total = (float) $o->fresh()->total;

        $this->artisan('parity:modifier-repair', ['--store' => $this->store->id, '--commit' => true])->assertExitCode(0);

        $this->assertSame($total, (float) $o->fresh()->total, 'a line with a snapshot must be skipped');
    }

    public function test_store_is_required(): void
    {
        $this->artisan('parity:modifier-repair')
            ->expectsOutputToContain('--store is required')
            ->assertExitCode(2);
    }

    public function test_it_scopes_to_the_named_store(): void
    {
        $mine = $this->legacyOrder('size: Large (+2.00)');

        // A second store with its own affected order.
        $other = Store::create([
            'user_id' => $this->store->user_id, 'slug' => 'o-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'O', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '2 St', 'phone' => '2', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherOrder = Order::create([
            'store_id' => $other->id, 'type' => 'takeaway', 'status' => 'new',
            'track_token' => Str::random(32), 'created_at' => now(),
        ]);
        $otherMenu = Menu::create(['store_id' => $other->id, 'name' => 'X', 'price' => 5, 'active' => '1']);
        OrderItem::create(['order_id' => $otherOrder->id, 'item_id' => $otherMenu->id, 'quantity' => 1, 'order_note' => 'x: y (+9.00)']);

        $this->artisan('parity:modifier-repair', ['--store' => $this->store->id, '--commit' => true])->assertExitCode(0);

        $this->assertIsArray($this->line($mine)->fresh()->options, 'the named store is repaired');
        $this->assertNull(OrderItem::where('order_id', $otherOrder->id)->first()->fresh()->options, 'another store is untouched');
    }
}
