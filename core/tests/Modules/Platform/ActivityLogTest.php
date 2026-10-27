<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\ActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $settings = new class(['theme_color' => '#111', 'site_title' => 'Test', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
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

    private function makeUser(): User
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000, 'settings' => ['restaurant_limit' => 5], 'status' => 1]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function makeRestaurant(User $owner): Store
    {
        $title = 'R'.Str::random(6);

        return Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** log_activity() writes a row */
    public function test_log_activity_writes_row(): void
    {
        $user = $this->makeUser();
        $store = $this->makeRestaurant($user);

        $this->actingAs($user);
        log_activity('test.action', $store, ['meta_key' => 'meta_val'], $store->id);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'test.action',
            'actor_user_id' => $user->id,
            'store_id' => $store->id,
        ]);
    }

    /** log_activity() never throws (try/catch) */
    public function test_log_activity_never_throws(): void
    {
        // No user, no restaurant, broken subject — should not throw
        log_activity('test.safe', null, [], null);
        $this->assertTrue(true); // reached here = no throw
    }

    /** Activity log index loads and shows owner's own logs */
    public function test_index_shows_own_logs(): void
    {
        $user = $this->makeUser();
        $store = $this->makeRestaurant($user);

        ActivityLog::create([
            'actor_user_id' => $user->id,
            'store_id' => $store->id,
            'action' => 'menu.add',
        ]);

        $response = $this->actingAs($user)
            ->get(route('activity-log.index'))
            ->assertOk();

        // action_label for 'menu.add' = "Menu add" (ucfirst + str_replace)
        $this->assertStringContainsString('Menu add', $response->getContent());
    }

    /** Tenant isolation: logs for another owner's restaurant not shown */
    public function test_index_excludes_other_owners_logs(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser();
        $otherRestaurant = $this->makeRestaurant($other);

        ActivityLog::create([
            'actor_user_id' => $other->id,
            'store_id' => $otherRestaurant->id,
            'action' => 'secret.action',
        ]);

        $response = $this->actingAs($owner)
            ->get(route('activity-log.index'))
            ->assertOk();

        // action_label for 'secret.action' = "Secret action"
        $this->assertStringNotContainsString('Secret action', $response->getContent());
    }

    /** Restaurant filter scopes logs correctly */
    public function test_index_restaurant_filter(): void
    {
        $user = $this->makeUser();
        $r1 = $this->makeRestaurant($user);
        $r2 = $this->makeRestaurant($user);

        // Use known mapped actions with distinct labels
        ActivityLog::create(['actor_user_id' => $user->id, 'store_id' => $r1->id, 'action' => 'menu.create']);
        ActivityLog::create(['actor_user_id' => $user->id, 'store_id' => $r2->id, 'action' => 'category.create']);

        $response = $this->actingAs($user)
            ->get(route('activity-log.index', ['store_id' => $r1->id]))
            ->assertOk();

        $content = $response->getContent();
        $this->assertStringContainsString('Created menu item', $content);
        $this->assertStringNotContainsString('Created category', $content);
    }
}
