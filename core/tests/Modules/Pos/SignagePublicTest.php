<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use App\Http\Middleware\Installed;
use App\Models\MenuCategory;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Pos\Models\SignageScreen;
use Tests\TestCase;

/**
 * GAPS-RUN-2 B6-P1: public signage boards (token-secured).
 */
class SignagePublicTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.admin_allow_digital_signage' => true]);
        $this->withoutMiddleware(Installed::class);
    }

    private function storeFixture(): Store
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $user->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'Diner', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function screen(Store $r, string $kind): SignageScreen
    {
        return SignageScreen::create([
            'store_id' => $r->id, 'kind' => $kind, 'token' => SignageScreen::newToken(), 'active' => true,
        ]);
    }

    public function test_menu_board_renders_active_instock_items(): void
    {
        $r = $this->storeFixture();
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Drinks', 'position' => 1, 'active' => 1]);
        Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Cola', 'price' => 2, 'active' => 1, 'is_sold_out' => 0]);
        Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'SoldOut', 'price' => 2, 'active' => 1, 'is_sold_out' => 1]);
        $screen = $this->screen($r, 'menu');

        $this->get('/signage/'.$screen->token)->assertOk();

        $res = $this->getJson('/signage/'.$screen->token.'/state')->assertOk();
        $res->assertJsonPath('kind', 'menu');
        $res->assertJsonPath('categories.0.name', 'Drinks');
        $names = collect($res->json('categories.0.items'))->pluck('name');
        $this->assertContains('Cola', $names);
        $this->assertNotContains('SoldOut', $names); // sold-out hidden
    }

    public function test_pickup_board_matches_statuses(): void
    {
        $r = $this->storeFixture();
        Order::create(['store_id' => $r->id, 'status' => 'preparing', 'type' => 'takeaway', 'customer_name' => 'Alice Wong']);
        Order::create(['store_id' => $r->id, 'status' => 'ready', 'type' => 'takeaway', 'customer_name' => 'Bob Smith']);
        Order::create(['store_id' => $r->id, 'status' => 'completed', 'type' => 'takeaway', 'customer_name' => 'Carol']);
        $screen = $this->screen($r, 'pickup');

        $res = $this->getJson('/signage/'.$screen->token.'/state')->assertOk();
        $res->assertJsonPath('kind', 'pickup');
        $this->assertCount(1, $res->json('preparing'));
        $this->assertCount(1, $res->json('ready'));
        $this->assertSame('Alice', $res->json('preparing.0.name')); // first name only
    }

    public function test_invalid_and_inactive_token_404(): void
    {
        $this->get('/signage/nope-nope')->assertNotFound();

        $r = $this->storeFixture();
        $screen = $this->screen($r, 'menu');
        $screen->update(['active' => false]);
        $this->get('/signage/'.$screen->token)->assertNotFound();
        $this->getJson('/signage/'.$screen->token.'/state')->assertNotFound();
    }

    public function test_killswitch_off_404s_the_boards(): void
    {
        $r = $this->storeFixture();
        $screen = $this->screen($r, 'menu');
        config(['settings.admin_allow_digital_signage' => false]);

        $this->get('/signage/'.$screen->token)->assertNotFound();
        $this->getJson('/signage/'.$screen->token.'/state')->assertNotFound();
    }

    public function test_token_is_tenant_scoped(): void
    {
        $a = $this->storeFixture();
        $b = $this->storeFixture();
        Order::create(['store_id' => $b->id, 'status' => 'ready', 'type' => 'takeaway', 'customer_name' => 'Zed']);
        $screenA = $this->screen($a, 'pickup');

        // Screen A must not surface restaurant B's order.
        $res = $this->getJson('/signage/'.$screenA->token.'/state')->assertOk();
        $this->assertCount(0, $res->json('ready'));
    }
}
