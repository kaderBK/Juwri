<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Marketing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GAPS-RUN-2 B2-P3: Automations owner UI (owner UI).
 */
class MarketingAutomationUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        config(['settings.admin_allow_marketing_automation' => true]);
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
        $this->withoutMiddleware();
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

    public function test_index_renders_with_nav_entry(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $res = $this->actingAs($owner)->get(route('stores.automations.index').'?store_id='.$r->id)->assertOk();
        $res->assertSee(___('Cart recovery'));
        $res->assertSee(___('Win-back'));
        $res->assertSeeHtml('automations'); // nav link URL
    }

    public function test_update_persists_flags_and_values(): void
    {
        [$owner, $r] = $this->ownerRestaurant();

        $this->actingAs($owner)->post(route('stores.automations.update').'?store_id='.$r->id, [
            'customer_cart_email' => '1',
            'winback_enabled' => '1',
            'winback_after_days' => '45',
            'winback_reward_percentage' => '20',
        ])->assertRedirect();

        $this->assertSame('1', (string) store_options($r->id, 'customer_cart_email'));
        $this->assertSame('0', (string) store_options($r->id, 'customer_cart_whatsapp')); // unchecked → 0
        $this->assertSame('1', (string) store_options($r->id, 'winback_enabled'));
        $this->assertSame('45', (string) store_options($r->id, 'winback_after_days'));
        $this->assertSame('20', (string) store_options($r->id, 'winback_reward_percentage'));
    }

    public function test_update_rejects_out_of_range_discount(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $this->actingAs($owner)->post(route('stores.automations.update').'?store_id='.$r->id, [
            'winback_reward_percentage' => '150',
        ])->assertSessionHasErrors('winback_reward_percentage');
    }

    public function test_index_is_tenant_scoped(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        [$other, $otherR] = $this->ownerRestaurant();
        $this->actingAs($owner)->get(route('stores.automations.index').'?store_id='.$otherR->id)->assertForbidden();
    }
}
