<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P1-G (NFR concurrency) — the storefront checkout must not turn one customer
 * intent into two orders.
 *
 * The API order endpoint has carried a `client_uuid` unique index for a long
 * time (OrderIdempotencyTest), but the storefront's own send-order POST had no
 * equivalent: a double-tapped "Place order", a browser retry on a flaky
 * connection, or a bfcache re-POST each produced a second real order — two
 * kitchen tickets, two totals, and on a prepaid flow two charges. The
 * `restaurant-order` rate limit is 10/minute, so two clicks a second apart both
 * pass it.
 */
class StorefrontOrderDoubleSubmitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        Mail::fake();
        Notification::fake();
        Queue::fake();

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function storeFixture(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'allow_ordering' => '1'], 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function item(Store $r): Menu
    {
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Cat']);

        return Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Item', 'price' => 10.00, 'active' => 1]);
    }

    private function send(Store $r, Menu $item, array $extra = []): TestResponse
    {
        return $this->post(route('store.submitOrder', $r), array_merge([
            'items' => json_encode([['id' => $item->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []]]),
            'name' => 'Test Guest',
            'ordering-type' => 'takeaway',
            'phone-number' => '+16505551234',
            'pay_via' => 'cash',
            'total' => '10.00',
        ], $extra));
    }

    public function test_the_same_submission_token_creates_one_order_not_two(): void
    {
        $r = $this->storeFixture();
        $item = $this->item($r);
        $token = (string) Str::uuid();

        $first = $this->send($r, $item, ['client_uuid' => $token]);
        $first->assertJson(['success' => true]);

        $second = $this->send($r, $item, ['client_uuid' => $token]);
        $second->assertJson(['success' => true]); // the guest still sees success

        $this->assertSame(
            1,
            Order::where('store_id', $r->id)->count(),
            'A repeated submission with the same client_uuid must not create a second order.'
        );
    }

    public function test_the_replay_returns_the_original_order_not_a_new_one(): void
    {
        $r = $this->storeFixture();
        $item = $this->item($r);
        $token = (string) Str::uuid();

        $this->send($r, $item, ['client_uuid' => $token]);
        $original = Order::where('store_id', $r->id)->firstOrFail();

        $this->send($r, $item, ['client_uuid' => $token]);

        $this->assertSame(
            1,
            Order::where('store_id', $r->id)->count(),
            'The replay must resolve to the original order.'
        );
        $this->assertSame($original->id, Order::where('store_id', $r->id)->firstOrFail()->id);
    }

    /** Two genuinely separate orders (no token, or different tokens) must both land. */
    public function test_distinct_submissions_still_create_distinct_orders(): void
    {
        $r = $this->storeFixture();
        $item = $this->item($r);

        $this->send($r, $item, ['client_uuid' => (string) Str::uuid()]);
        $this->send($r, $item, ['client_uuid' => (string) Str::uuid()]);
        $this->send($r, $item); // legacy client with no token at all

        $this->assertSame(3, Order::where('store_id', $r->id)->count());
    }

    /**
     * Unified pipeline: a replayed submission must not re-run the once-per-order side
     * effects (loyalty redemption + CRM orders_count/total_spent). The legacy branch
     * returned early on its unique-index throw; the unified branch used to fall through
     * to the shared tail and double-apply them.
     */
    public function test_unified_pipeline_replay_does_not_double_apply_side_effects(): void
    {
        config(['settings.storefront_order_pipeline' => 'unified']);

        $r = $this->storeFixture();
        StoreOption::updateStoreOption($r->id, 'restaurant_takeaway_order', '1');
        $item = $this->item($r);
        $token = (string) Str::uuid();

        $this->send($r, $item, ['client_uuid' => $token])->assertJson(['success' => true]);
        $this->send($r, $item, ['client_uuid' => $token])->assertJson(['success' => true, 'duplicate' => true]);

        $this->assertSame(1, Order::where('store_id', $r->id)->count());

        $customer = Customer::where('store_id', $r->id)->first();
        $this->assertNotNull($customer, 'guest capture should have created one customer');
        $this->assertSame(1, (int) $customer->orders_count, 'orders_count must count the order once, not twice.');
    }

    /** The same token used against a different store is a different order. */
    public function test_the_same_token_in_two_stores_does_not_collide(): void
    {
        $a = $this->storeFixture();
        $b = $this->storeFixture();
        $token = (string) Str::uuid();

        $this->send($a, $this->item($a), ['client_uuid' => $token]);
        $this->send($b, $this->item($b), ['client_uuid' => $token]);

        $this->assertSame(1, Order::where('store_id', $a->id)->count());
        $this->assertSame(1, Order::where('store_id', $b->id)->count());
    }
}
