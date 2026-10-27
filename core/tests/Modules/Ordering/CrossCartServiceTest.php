<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use Juwri\Modules\Menu\Models\Menu;
use App\Models\Order;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use App\Services\MultiVendor\CrossCartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GAPS-RUN-2 B12-P1: cross-store cart + split checkout.
 */
class CrossCartServiceTest extends TestCase
{
    use RefreshDatabase;

    private CrossCartService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.admin_allow_multivendor_cart' => true]);
        $this->svc = new CrossCartService();
    }

    private function store(): array
    {
        $plan = TestPlans::create([
            'name' => 'P' . Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-' . Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $menu = Menu::create(['store_id' => $r->id, 'name' => 'Dish', 'price' => 10, 'active' => 1]);
        return [$r, $menu];
    }

    public function test_add_groups_lines_by_store(): void
    {
        [$a, $ma] = $this->store();
        [$b, $mb] = $this->store();

        $this->svc->addItem($a->id, $ma->id, 2);
        $this->svc->addItem($a->id, $ma->id, 1); // merges → qty 3
        $this->svc->addItem($b->id, $mb->id, 1);

        $cart = $this->svc->cart();
        $this->assertCount(2, $cart); // two stores
        $this->assertSame(3, $cart[$a->id][0]['quantity']);
        // Store A's cart never contains store B's item.
        $this->assertSame([$ma->id], array_column($cart[$a->id], 'item_id'));
    }

    public function test_add_rejects_foreign_item(): void
    {
        [$a] = $this->store();
        [$b, $mb] = $this->store();
        $this->expectException(ValidationException::class);
        $this->svc->addItem($a->id, $mb->id, 1); // mb belongs to store B
    }

    public function test_checkout_splits_into_per_store_orders(): void
    {
        [$a, $ma] = $this->store();
        [$b, $mb] = $this->store();
        $this->svc->addItem($a->id, $ma->id, 2);
        $this->svc->addItem($b->id, $mb->id, 1);

        $results = $this->svc->checkout(['name' => 'Sam', 'phone' => '123']);

        $this->assertCount(2, $results);
        $this->assertTrue(collect($results)->every(fn ($r) => $r['ok']));
        $this->assertSame(1, Order::where('store_id', $a->id)->where('source', 'marketplace')->count());
        $this->assertSame(1, Order::where('store_id', $b->id)->where('source', 'marketplace')->count());
        $this->assertEmpty($this->svc->cart()); // both cleared on success
    }

    public function test_partial_failure_keeps_failed_store_lines(): void
    {
        [$a, $ma] = $this->store();
        [$b, $mb] = $this->store();
        $this->svc->addItem($a->id, $ma->id, 1);
        $this->svc->addItem($b->id, $mb->id, 1);
        // Make store B fail: sell out its item after adding to cart.
        $mb->update(['is_sold_out' => 1]);

        $results = $this->svc->checkout(['name' => 'Sam', 'phone' => '123']);

        $ok = collect($results)->firstWhere('store_id', $a->id);
        $bad = collect($results)->firstWhere('store_id', $b->id);
        $this->assertTrue($ok['ok']);
        $this->assertFalse($bad['ok']);

        $cart = $this->svc->cart();
        $this->assertArrayNotHasKey($a->id, $cart);   // succeeded → removed
        $this->assertArrayHasKey($b->id, $cart);       // failed → retained
    }

    /**
     * The nav bag badge renders this number server-side. It used to ship
     * hidden and empty on every page load and only appear once you added
     * something in that same page session, so returning to a full bag showed
     * an empty badge.
     */
    public function test_item_count_sums_quantities_across_stores(): void
    {
        [$a, $ma] = $this->store();
        [$b, $mb] = $this->store();

        $this->assertSame(0, $this->svc->itemCount());

        $this->svc->addItem($a->id, $ma->id, 2);
        $this->svc->addItem($a->id, $ma->id, 1); // merges → qty 3
        $this->svc->addItem($b->id, $mb->id, 4);

        $this->assertSame(7, $this->svc->itemCount());

        $this->svc->clear();
        $this->assertSame(0, $this->svc->itemCount());
    }

    /**
     * itemCount() runs on EVERY marketplace page render. scopeOwned() would
     * have called token(), which mints a guest token and queues a cart cookie
     * — handing a cart cookie to every visitor who never opened a cart.
     */
    public function test_item_count_does_not_mint_a_cart_token(): void
    {
        \Illuminate\Support\Facades\Cookie::getQueuedCookies(); // baseline

        $this->assertSame(0, $this->svc->itemCount());

        $this->assertNull(
            \Illuminate\Support\Facades\Cookie::queued(CrossCartService::TOKEN_COOKIE),
            'Reading the badge count must not create a cart for a visitor who has none.'
        );
    }

    public function test_gate_off_refuses_everything(): void
    {
        [$a, $ma] = $this->store();
        config(['settings.admin_allow_multivendor_cart' => false]);

        $this->expectException(ValidationException::class);
        $this->svc->addItem($a->id, $ma->id, 1);
    }
}
