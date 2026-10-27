<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Delivery;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Delivery\Services\Analytics\DeliveryInsightsService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GAPS-RUN-2 B5-P1: delivery insights aggregation + owner UI.
 */
class DeliveryInsightsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        config(['settings.admin_allow_demand_forecast' => true]);
        $settings = new class(['theme_color' => '#111', 'site_title' => 'T', 'meta_description' => 'T', 'social_share_image' => 'd.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'f.png'])
        {
            public function __construct(private array $d) {}

            public function __get($k): mixed
            {
                return $this->d[$k] ?? null;
            }

            public function __set($k, $v): void
            {
                $this->d[$k] = $v;
            }

            public function __isset($k): bool
            {
                return array_key_exists($k, $this->d);
            }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect()]);
    }

    private function ownerRestaurant(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$owner, $r];
    }

    private function deliveryOrder(Store $r, Carbon $at, ?string $address = null): Order
    {
        $o = Order::create(['store_id' => $r->id, 'type' => 'delivery', 'status' => 'new', 'address' => $address]);
        $o->forceFill(['created_at' => $at])->save();

        return $o;
    }

    public function test_heat_grid_buckets_by_weekday_and_hour(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        // Two orders in the same weekday+hour, one in a different slot.
        $slotA = Carbon::create(2026, 7, 6, 19, 0, 0); // a Monday 19:00
        $this->deliveryOrder($r, $slotA->copy());
        $this->deliveryOrder($r, $slotA->copy()->addMinutes(20));
        $this->deliveryOrder($r, $slotA->copy()->addDays(1)->setHour(12)); // Tue 12:00
        // A pickup order must be ignored.
        Order::create(['store_id' => $r->id, 'type' => 'pickup', 'status' => 'new'])
            ->forceFill(['created_at' => $slotA->copy()])->save();

        // Freeze "now" just after the slots so the 8-week window covers them.
        Carbon::setTestNow($slotA->copy()->addDay());
        $heat = app(DeliveryInsightsService::class)->heatGrid($r->id);
        Carbon::setTestNow();

        $this->assertSame(3, $heat['total']);
        $this->assertSame(2, $heat['max']);
        $this->assertSame(2, $heat['grid'][$slotA->dayOfWeek][19]);
    }

    public function test_top_areas_normalizes_and_ranks_tokens(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $now = Carbon::now();
        $this->deliveryOrder($r, $now->copy(), '12 Maple Street, Riverside');
        $this->deliveryOrder($r, $now->copy(), '9 Oak Road, Riverside');
        $this->deliveryOrder($r, $now->copy(), '3 Maple Lane, Downtown');

        $areas = app(DeliveryInsightsService::class)->topAreas($r->id);
        $tokens = array_column($areas, 'token');

        $this->assertContains('riverside', $tokens);
        $this->assertContains('maple', $tokens);
        // "street"/"road" are stop tokens — excluded.
        $this->assertNotContains('street', $tokens);
        $riverside = collect($areas)->firstWhere('token', 'riverside');
        $this->assertSame(2, $riverside['count']);
    }

    public function test_page_renders_for_owner_and_is_tenant_scoped(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        [$other, $otherR] = $this->ownerRestaurant();
        $this->withoutMiddleware();

        $this->actingAs($owner)->get(route('stores.deliveryInsights.index').'?store_id='.$r->id)
            ->assertOk()->assertSee(___('Demand heat grid'));

        $this->actingAs($owner)->get(route('stores.deliveryInsights.index').'?store_id='.$otherR->id)
            ->assertForbidden();
    }
}
