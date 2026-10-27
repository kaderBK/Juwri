<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Found by the P1-12 Arabic journey: on a modern-template store the Arabic order-tracking page printed the English item
 * names ("Nachos Grande × 1") under Arabic chrome — the storefront pins the menu-content language to the page locale, the
 * track page did not. A legacy template keeps its own cookie (not pinned).
 */
class TrackPageItemLanguageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        config(['settings.include_language_code' => 1]);
        unset($_COOKIE['Quick_user_lang_code']);
    }

    protected function tearDown(): void
    {
        unset($_COOKIE['Quick_user_lang_code']);
        app()->setLocale('en');
        parent::tearDown();
    }

    private function orderFor(string $template, string $locale): Order
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => ['restaurant_limit' => 999],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'tl-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'Track Lang',
            'sub_title' => 'x', 'description' => 'x', 'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123',
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', $template);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => '1']);
        $menu = Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Nachos Grande', 'price' => 20, 'active' => '1',
            'translations' => ['ar' => ['title' => 'ناتشوز غراندي']],
        ]);
        $order = Order::create(['store_id' => $store->id, 'customer_name' => 'T', 'status' => 'pending', 'type' => 'takeaway', 'track_token' => Str::random(32), 'locale' => $locale]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 1, 'variation' => 0, 'unit_price' => 20]);

        return $order;
    }

    public function test_the_arabic_track_page_of_a_modern_template_names_the_items_in_arabic(): void
    {
        $order = $this->orderFor('crave', 'ar');

        $html = $this->get(route('store.orderTrack', $order->track_token))->assertOk()->getContent();

        $this->assertStringContainsString('ناتشوز غراندي × 1', $html);
        $this->assertStringNotContainsString('Nachos Grande', $html);
    }

    public function test_an_english_order_still_shows_the_base_names(): void
    {
        $order = $this->orderFor('crave', 'en');

        $html = $this->get(route('store.orderTrack', $order->track_token))->assertOk()->getContent();

        $this->assertStringContainsString('Nachos Grande × 1', $html);
    }
}
