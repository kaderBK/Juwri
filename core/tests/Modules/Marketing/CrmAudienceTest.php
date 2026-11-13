<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Marketing;

use App\Models\Audience;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Crm\AttributionService;
use App\Services\Crm\AudienceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * SYSTEMS-RUN C-P2: audience builder + attribution.
 */
class CrmAudienceTest extends TestCase
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

    public function test_audience_rules_filter_customers(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        Customer::create(['name' => 'Big', 'store_id' => $r->id, 'phone' => UniquePhone::next('1'), 'total_spent' => 5000, 'orders_count' => 8]);
        Customer::create(['name' => 'Small', 'store_id' => $r->id, 'phone' => UniquePhone::next('2'), 'total_spent' => 50, 'orders_count' => 1]);

        $rules = ['match' => 'all', 'conditions' => [['field' => 'total_spent', 'op' => 'gte', 'value' => 1000]]];
        $this->assertSame(1, new AudienceService()->count($r->id, $rules));
    }

    public function test_preview_and_store_and_scope(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        Customer::create(['name' => 'Big', 'store_id' => $r->id, 'phone' => UniquePhone::next('1'), 'total_spent' => 5000, 'orders_count' => 8]);
        $this->withoutMiddleware();

        $this->actingAs($owner)->postJson(route('crm.audiences.preview').'?store_id='.$r->id, [
            'match' => 'all', 'conditions' => [['field' => 'orders_count', 'op' => 'gte', 'value' => 5]],
        ])->assertOk()->assertJson(['count' => 1]);

        $this->actingAs($owner)->post(route('crm.audiences.store').'?store_id='.$r->id, [
            'name' => 'VIPs', 'match' => 'all', 'conditions' => [['field' => 'total_spent', 'op' => 'gte', 'value' => 1000]],
        ])->assertRedirect();
        $this->assertSame(1, Audience::where('store_id', $r->id)->count());
    }

    public function test_attribution_window_math(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $c = Customer::create(['name' => 'C', 'store_id' => $r->id, 'phone' => UniquePhone::next('3')]);
        $campaign = Campaign::create(['store_id' => $r->id, 'channel' => 'email', 'subject' => 'X', 'body' => 'b', 'segment' => 'all', 'status' => 'sent']);
        CampaignRecipient::create(['campaign_id' => $campaign->id, 'customer_id' => $c->id, 'status' => 'sent', 'sent_at' => now()->subDays(2)]);

        // In-window paid order (attributed) + out-of-window order (not).
        Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'pending', 'customer_id' => $c->id, 'is_paid' => 1, 'created_at' => now()->subDay()]);
        Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'pending', 'customer_id' => $c->id, 'is_paid' => 1, 'created_at' => now()->subDays(30)]);

        $res = new AttributionService()->forCampaign($campaign);
        $this->assertSame(1, $res['orders']);
    }

    public function test_audiences_page_renders(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $this->withoutMiddleware();
        $this->actingAs($owner)->get(route('crm.audiences').'?store_id='.$r->id)->assertOk()->assertSeeHtml(___('Audiences'));
    }
}
