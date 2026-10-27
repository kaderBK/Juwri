<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Middleware\Installed;
use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * D8 (integrations pack): the customer track page shows one "Ordered via <channel>"
 * line for an aggregator order, and nothing for a native order.
 */
class TrackOrderedViaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1, 'position' => 1]);
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([
            LaravelLocalizationRedirectFilter::class,
            Installed::class,
            ThrottleRequests::class,
        ]);
    }

    private function order(string $source): Order
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999, 'allow_ordering' => 1]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', 'crave');
        StoreOption::updateStoreOption($store->id, 'default_language', 'en');

        return Order::create([
            'store_id' => $store->id, 'status' => 'pending', 'type' => 'delivery',
            'customer_name' => 'Ada', 'track_token' => Str::random(32), 'locale' => 'en',
            'source' => $source, 'created_at' => now(),
        ]);
    }

    public function test_aggregator_order_shows_channel_line(): void
    {
        $order = $this->order('deliverect');

        $this->get(route('store.orderTrack', $order->track_token))->assertOk()->assertSeeHtml('ordered-via')
            ->assertSee('Deliverect');
    }

    public function test_native_order_has_no_channel_line(): void
    {
        $order = $this->order('online');

        $this->get(route('store.orderTrack', $order->track_token))->assertOk()->assertDontSeeHtml('ordered-via');
    }

    public function test_channel_label_accessor_maps_known_and_native_sources(): void
    {
        $this->assertSame('Deliverect', $this->order('deliverect')->channelLabel());
        $this->assertSame('Uber Eats', $this->order('uber_eats')->channelLabel());
        $this->assertNull($this->order('online')->channelLabel());
        $this->assertNull($this->order('pos')->channelLabel());
    }
}
