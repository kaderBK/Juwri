<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

use App\Models\Customer;
use App\Models\Order;
use App\Services\Crm\AutoTagService;
use App\Services\Crm\RfmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Customers\Services\Crm\GuestTimeline;
use Tests\Support\TestPlans;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * SYSTEMS-RUN C-P1: unified guest profile, timeline, RFM, auto-tags.
 */
class CrmProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        config(['settings.admin_allow_crm_suite' => true]);
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

    public function test_timeline_merges_orders_and_is_scoped(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $customer = Customer::create(['name' => 'C', 'store_id' => $r->id, 'phone' => UniquePhone::next('55-')]);
        Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'pending', 'customer_id' => $customer->id]);

        $events = new GuestTimeline()->for($customer);
        $this->assertNotEmpty($events);
        $this->assertSame('order', $events[0]['type']);
    }

    public function test_profile_renders_and_tenant_scoped(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        [$other, $otherR] = $this->ownerRestaurant();
        $customer = Customer::create(['name' => 'C', 'store_id' => $r->id, 'phone' => UniquePhone::next('55-')]);
        $foreign = Customer::create(['name' => 'X', 'store_id' => $otherR->id, 'phone' => UniquePhone::next('55-')]);
        $this->withoutMiddleware();

        $this->actingAs($owner)->get(route('crm.profile', $customer->id))->assertOk()->assertSeeHtml(___('Timeline'));
        $this->actingAs($owner)->get(route('crm.profile', $foreign->id))->assertNotFound();
    }

    public function test_auto_tags_are_idempotent(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $c = Customer::create(['name' => 'Big', 'store_id' => $r->id, 'phone' => UniquePhone::next('55-'),
            'total_spent' => 5000, 'orders_count' => 10, 'last_order_at' => now()]);
        $svc = new AutoTagService;
        $svc->recompute($r->id);
        $tags = $c->fresh()->system_tags;
        $this->assertContains('big_spender', $tags);
        $this->assertContains('regular', $tags);

        $svc->recompute($r->id);
        $this->assertSame($tags, $c->fresh()->system_tags);
    }

    public function test_rfm_buckets(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        for ($i = 1; $i <= 5; $i++) {
            Customer::create(['name' => "C$i", 'store_id' => $r->id, 'phone' => '55-'.$i,
                'total_spent' => $i * 100, 'orders_count' => $i, 'last_order_at' => now()->subDays(6 - $i)]);
        }
        $rfm = new RfmService()->compute($r->id);
        $this->assertCount(5, $rfm['scores']);
        $this->assertNotEmpty($rfm['segments']);
    }

    public function test_rfm_page_renders(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $this->withoutMiddleware();
        $this->actingAs($owner)->get(route('crm.rfm').'?store_id='.$r->id)->assertOk()->assertSeeHtml(___('RFM'));
    }
}
