<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use Juwri\Modules\Ordering\Models\Cart;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use App\Services\MultiVendor\CrossCartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Marketplace P0-3: DB carts survive session rotation via the guest cookie.
 */
class CartPersistenceTest extends TestCase
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
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
        ]);
        $menu = Menu::create(['store_id' => $r->id, 'name' => 'Dish', 'price' => 10, 'active' => 1]);

        return [$r, $menu];
    }

    public function test_cart_survives_new_session_with_same_cookie(): void
    {
        [$r, $menu] = $this->store();

        $this->svc->addItem($r->id, $menu->id, 2, 'no onions');
        $token = $this->svc->token();
        $this->assertNotSame('', $token);
        $this->assertDatabaseHas('carts', ['guest_token' => $token, 'store_id' => $r->id]);

        // Browser restart sim: brand-new session id, same cookie on the request.
        session()->flush();
        session()->regenerate();
        request()->cookies->set(CrossCartService::TOKEN_COOKIE, $token);

        $cart = $this->svc->cart();
        $this->assertArrayHasKey($r->id, $cart);
        $this->assertSame(2, $cart[$r->id][0]['quantity']);
        $this->assertSame('no onions', $cart[$r->id][0]['note']);
    }

    public function test_new_cookie_means_empty_cart_no_leak(): void
    {
        [$r, $menu] = $this->store();

        $this->svc->addItem($r->id, $menu->id, 1);

        // Different visitor: no cookie, fresh session → must NOT see the first cart.
        session()->flush();
        request()->cookies->remove(CrossCartService::TOKEN_COOKIE);

        $this->assertSame([], $this->svc->cart());
    }

    public function test_expired_cart_is_invisible(): void
    {
        [$r, $menu] = $this->store();

        $this->svc->addItem($r->id, $menu->id, 1);
        Cart::query()->update(['expires_at' => now()->subDay()]);

        $this->assertSame([], $this->svc->cart());
    }

    public function test_clear_removes_rows(): void
    {
        [$r, $menu] = $this->store();

        $this->svc->addItem($r->id, $menu->id, 1);
        $this->assertSame(1, Cart::count());

        $this->svc->clear();
        $this->assertSame(0, Cart::count());
        $this->assertSame(0, \Juwri\Modules\Ordering\Models\CartLine::count());
    }
}
