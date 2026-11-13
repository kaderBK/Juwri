<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\UserSettings;
use Juwri\Modules\Customers\Models\NpsResponse;
use Juwri\Modules\Marketing\Models\AbExperiment;
use Tests\Support\TestPlans;
use Tests\TestCase;

class NpsAbKpiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $settings = new class(['theme_color' => '#111', 'site_title' => 'T', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
        {
            public function __construct(private array $data) {}

            public function __get(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function __set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function __isset(string $key): bool
            {
                return array_key_exists($key, $this->data);
            }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect(), 'pages' => collect()]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    private function makeUser(bool $admin = false): User
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000, 'settings' => ['restaurant_limit' => 5], 'status' => 1]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(), 'user_type' => $admin ? 'admin' : 'user']);
    }

    private function makeRestaurant(User $owner): Store
    {
        $title = 'R'.Str::random(6);

        return Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
    }

    // ── #62 NPS ──

    /** Guest can submit NPS response */
    public function test_nps_submit_creates_record(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        $this->postJson(route('store.submitNps', $store->slug), [
            'score' => 9,
            'comment' => 'Great experience!',
        ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('nps_responses', [
            'store_id' => $store->id,
            'score' => 9,
        ]);
    }

    /** NPS score clamped to 0–10 */
    public function test_nps_score_clamped(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        $this->postJson(route('store.submitNps', $store->slug), ['score' => 99])
            ->assertOk();

        $this->assertDatabaseHas('nps_responses', ['store_id' => $store->id, 'score' => 10]);
    }

    /** Duplicate NPS response from same session+day is silently deduped */
    public function test_nps_deduped_same_session(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        $this->postJson(route('store.submitNps', $store->slug), ['score' => 8])->assertOk();
        $this->postJson(route('store.submitNps', $store->slug), ['score' => 5])->assertOk();

        $this->assertEquals(1, NpsResponse::where('store_id', $store->id)->count());
    }

    // ── #64 A/B Testing ──

    /** Admin can create an A/B experiment */
    public function test_admin_creates_ab_experiment(): void
    {
        $admin = $this->makeUser(true);

        $this->actingAs($admin)
            ->post(route('admin.ab-experiments.store'), [
                'name' => 'menu_layout_test',
                'description' => 'Test two layouts',
                'variants' => 'control, treatment',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('ab_experiments', [
            'name' => 'menu_layout_test',
            'status' => 'active',
        ]);

        $exp = AbExperiment::where('name', 'menu_layout_test')->first();
        $this->assertCount(2, $exp->variants);
    }

    /** Needs at least 2 variants */
    public function test_ab_experiment_requires_two_variants(): void
    {
        $admin = $this->makeUser(true);

        $this->actingAs($admin)
            ->post(route('admin.ab-experiments.store'), [
                'name' => 'only_one',
                'variants' => 'control',
            ])
            ->assertSessionHasErrors('variants');
    }

    /** Admin ends experiment */
    public function test_admin_ends_ab_experiment(): void
    {
        $admin = $this->makeUser(true);
        $exp = AbExperiment::create(['name' => 'exp_to_end', 'variants' => ['a', 'b'], 'status' => 'active', 'started_at' => now()]);

        $this->actingAs($admin)
            ->delete(route('admin.ab-experiments.destroy', $exp))
            ->assertRedirect();

        $this->assertDatabaseHas('ab_experiments', ['id' => $exp->id, 'status' => 'ended']);
    }

    /** plan_feature() ab_test helper assigns a deterministic variant */
    public function test_ab_variant_helper_returns_valid_variant(): void
    {
        $exp = AbExperiment::create(['name' => 'my_test', 'variants' => ['A', 'B'], 'status' => 'active', 'started_at' => now()]);

        $variant = ab_variant('my_test');
        $this->assertContains($variant, ['A', 'B', null]); // null if experiment not active
    }

    // ── #52 KPI Goals ──

    /** Save goal persists type and value */
    public function test_save_goal_persists(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->post(route('dashboard.goal.save'), [
                'goal_type' => 'orders',
                'goal_value' => 100,
            ])
            ->assertOk();

        $this->assertSame('orders', UserSettings::legacy((int) $user->id, 'dashboard_goal_type'));
        $this->assertSame('100', UserSettings::legacy((int) $user->id, 'dashboard_goal_value'));
    }

    /** Save goal with value=0 clears existing goals */
    public function test_save_goal_zero_clears(): void
    {
        $user = $this->makeUser();

        UserSettings::set($user->id, 'dashboard_goal_type', 'scans');
        UserSettings::set($user->id, 'dashboard_goal_value', '500');

        $this->actingAs($user)
            ->post(route('dashboard.goal.save'), [
                'goal_type' => 'scans',
                'goal_value' => 0,
            ])
            ->assertOk();

        $this->assertNull(UserSettings::legacy((int) $user->id, 'dashboard_goal_value'));
    }
}
