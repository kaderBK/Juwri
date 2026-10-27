<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Kitchen;

use App\Http\Middleware\Installed;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Kitchen\Models\FoodSafetyCheck;
use Juwri\Modules\Kitchen\Models\FoodSafetyLog;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GAPS-RUN-2 B10-P2: food-safety UI + staff logging.
 */
class FoodSafetyUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        config(['settings.admin_allow_food_safety' => true]);
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

    public function test_owner_page_renders_and_adds_check(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $this->withoutMiddleware();

        $res = $this->actingAs($owner)->get(route('stores.foodsafety.index').'?store_id='.$r->id)->assertOk();
        $res->assertSee(___('Add a check'));
        $res->assertSeeHtml('food-safety'); // nav URL

        $this->actingAs($owner)->post(route('stores.foodsafety.checks.store').'?store_id='.$r->id, [
            'name' => 'Fridge', 'kind' => 'temperature', 'target_min' => '1', 'target_max' => '5', 'frequency' => 'daily',
        ])->assertRedirect();
        $this->assertSame(1, FoodSafetyCheck::where('store_id', $r->id)->count());
    }

    public function test_labels_view_renders_expiry(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $this->withoutMiddleware();

        $res = $this->actingAs($owner)->get(route('stores.foodsafety.labels').'?store_id='.$r->id.'&items=Chicken%20stock&prep=2026-07-11&days=3')->assertOk();
        $res->assertSee(___('Chicken stock'));
        $res->assertSee('2026-07-14'); // prep + 3 days
    }

    public function test_owner_scoped(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        [$other, $otherR] = $this->ownerRestaurant();
        $this->withoutMiddleware();
        $this->actingAs($owner)->get(route('stores.foodsafety.index').'?store_id='.$otherR->id)->assertForbidden();
    }

    public function test_staff_logs_reading_flags_breach(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $check = FoodSafetyCheck::create(['store_id' => $r->id, 'name' => 'Fridge', 'kind' => 'temperature', 'target_min' => 1, 'target_max' => 5, 'frequency' => 'daily', 'active' => true]);
        $staff = StaffMember::create([
            'owner_id' => $owner->id, 'store_id' => $r->id, 'name' => 'Sam', 'role' => 'kitchen',
            'email' => 's'.Str::random(4).'@x.com', 'password' => Hash::make('x'), 'active' => 1,
        ]);
        $this->withoutMiddleware(Installed::class);

        $this->actingAs($staff, 'staff')->postJson('/staff-app/food-safety/'.$check->id, ['value' => 9])
            ->assertOk()->assertJson(['ok' => true, 'breach' => true]);

        $this->assertSame(1, FoodSafetyLog::where('check_id', $check->id)->where('breach', true)->count());
    }
}
