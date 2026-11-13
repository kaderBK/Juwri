<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\ActivityLog;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * C1 — the superadmin surface: a per-store redirect editor (same field set as the
 * owner's) and a "↪ redirect" badge on the restaurants index. Saves route through
 * the shared ExternalRedirect::saveFromRequest(), so the ActivityLog actor is the
 * acting admin.
 */
class ExternalRedirectAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        // Admin chrome reads many optional settings keys (e.g. $settings->
        // site_admin_logo in admin/includes/sidebar.blade.php), so the strict
        // array_to_object() stdClass shareLayoutGlobals() produces 500s on the first
        // undefined property. The null-returning double is the legitimate pattern
        // here — same as AdminDashboardTest / AdminAnalyticsPageTest (ViewComposer-
        // ContractTest owner-decision cap). Only this admin-chrome file needs it; the
        // owner / reseller / storefront render tests use shareLayoutGlobals().
        $settings = new class(['theme_color' => '#111', 'site_title' => 'TestApp', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
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
        view()->share([
            'settings' => $settings,
            'admin_languages' => collect(),
            'notifications' => collect(),
            'totalUnreadNotifications' => 0,
            'totalUnapprovedComments' => 0,
        ]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    public function test_admin_editor_renders_the_field_set(): void
    {
        $admin = $this->makeAdmin();
        $store = $this->makeStore();

        $response = $this->actingAs($admin)->get(route('admin.stores.externalRedirect.edit', $store->id));

        $response->assertStatus(200);
        $response->assertSeeHtml('name="external_redirect_url"');
        $response->assertSeeHtml('name="bio_external_redirect_url"');
        $response->assertSeeHtml('name="external_redirect_interstitial"');
    }

    public function test_admin_save_persists_and_audits_as_admin(): void
    {
        $admin = $this->makeAdmin();
        $store = $this->makeStore();

        $this->actingAs($admin)->post(route('admin.stores.externalRedirect.update', $store->id), [
            'external_redirect_enabled' => '1',
            'external_redirect_url' => 'https://legacy.example.com/{slug}{query}',
            'external_redirect_interstitial' => '0',
            'bio_external_redirect_enabled' => '0',
            'bio_external_redirect_url' => '',
        ]);

        $this->assertSame('1', (string) StoreOption::getStoreOption($store->id, 'external_redirect_enabled'));
        $this->assertSame('https://legacy.example.com/{slug}{query}', (string) StoreOption::getStoreOption($store->id, 'external_redirect_url'));

        $log = ActivityLog::where('action', 'external_redirect.set')
            ->where('store_id', $store->id)->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, (int) $log->actor_user_id, 'The audit actor must be the acting admin.');
    }

    public function test_admin_save_rejects_invalid_url(): void
    {
        $admin = $this->makeAdmin();
        $store = $this->makeStore();

        $this->actingAs($admin)->from(route('admin.stores.externalRedirect.edit', $store->id))
            ->post(route('admin.stores.externalRedirect.update', $store->id), [
                'external_redirect_enabled' => '1',
                'external_redirect_url' => 'http://insecure.example.com',
                'external_redirect_interstitial' => '0',
                'bio_external_redirect_enabled' => '0',
                'bio_external_redirect_url' => '',
            ])
            ->assertRedirect(route('admin.stores.externalRedirect.edit', $store->id));

        $this->assertNotSame('1', (string) StoreOption::getStoreOption($store->id, 'external_redirect_enabled'));
    }

    public function test_index_badges_a_redirected_store(): void
    {
        $admin = $this->makeAdmin();
        $store = $this->makeStore();
        StoreOption::updateStoreOption($store->id, 'external_redirect_enabled', '1');
        StoreOption::updateStoreOption($store->id, 'external_redirect_url', 'https://legacy.example.com/menu');

        $response = $this->actingAs($admin)->get(route('admin.stores.index'));

        $response->assertStatus(200);
        $response->assertSeeHtml(route('admin.stores.externalRedirect.edit', $store->id));
        $response->assertSeeHtml('↪');
    }

    public function test_bulk_set_applies_template_to_each_selected_store_with_audit(): void
    {
        $admin = $this->makeAdmin();
        $a = $this->makeStore();
        $b = $this->makeStore();
        $other = $this->makeStore(); // not selected — must stay untouched

        $response = $this->actingAs($admin)->postJson(route('admin.stores.externalRedirect.bulk'), [
            'ids' => [$a->id, $b->id],
            'action' => 'set',
            'template' => 'https://legacy.example.com/{slug}{query}',
        ]);

        $response->assertStatus(200)->assertJson(['success' => true, 'applied' => 2]);

        foreach ([$a, $b] as $store) {
            $this->assertSame('1', (string) StoreOption::getStoreOption($store->id, 'external_redirect_enabled'));
            $this->assertSame('https://legacy.example.com/{slug}{query}', (string) StoreOption::getStoreOption($store->id, 'external_redirect_url'));
            // Per-store audit row (proves updateStoreOption path, not a raw bulk update).
            $this->assertNotNull(
                ActivityLog::where('action', 'external_redirect.set')->where('store_id', $store->id)->first()
            );
        }
        $this->assertNotSame('1', (string) StoreOption::getStoreOption($other->id, 'external_redirect_enabled'));
    }

    public function test_bulk_clear_turns_off_selected_stores(): void
    {
        $admin = $this->makeAdmin();
        $a = $this->makeStore();
        StoreOption::updateStoreOption($a->id, 'external_redirect_enabled', '1');
        StoreOption::updateStoreOption($a->id, 'external_redirect_url', 'https://legacy.example.com/menu');

        $this->actingAs($admin)->postJson(route('admin.stores.externalRedirect.bulk'), [
            'ids' => [$a->id],
            'action' => 'clear',
        ])->assertStatus(200)->assertJson(['success' => true]);

        $this->assertSame('0', (string) StoreOption::getStoreOption($a->id, 'external_redirect_enabled'));
        // URL kept.
        $this->assertSame('https://legacy.example.com/menu', (string) StoreOption::getStoreOption($a->id, 'external_redirect_url'));
    }

    public function test_bulk_set_scope_filter_targets_all_matching_not_just_selection(): void
    {
        $admin = $this->makeAdmin();
        $m1 = $this->makeStore('Pizza Palace');
        $m2 = $this->makeStore('Pizza Hub');
        $other = $this->makeStore('Burger Barn');

        // scope=filter with q=Pizza — no ids sent; the server resolves all matching.
        $this->actingAs($admin)->postJson(route('admin.stores.externalRedirect.bulk'), [
            'scope' => 'filter',
            'action' => 'set',
            'template' => 'https://legacy.example.com/{slug}{query}',
            'filters' => ['q' => 'Pizza'],
        ])->assertStatus(200)->assertJson(['success' => true, 'applied' => 2]);

        $this->assertSame('1', (string) StoreOption::getStoreOption($m1->id, 'external_redirect_enabled'));
        $this->assertSame('1', (string) StoreOption::getStoreOption($m2->id, 'external_redirect_enabled'));
        $this->assertNotSame('1', (string) StoreOption::getStoreOption($other->id, 'external_redirect_enabled'));
    }

    public function test_bulk_set_rejects_an_invalid_template_and_changes_nothing(): void
    {
        $admin = $this->makeAdmin();
        $a = $this->makeStore();

        $this->actingAs($admin)->postJson(route('admin.stores.externalRedirect.bulk'), [
            'ids' => [$a->id],
            'action' => 'set',
            'template' => 'http://insecure.example.com/{slug}', // not https
        ])->assertStatus(422)->assertJson(['success' => false]);

        $this->assertNotSame('1', (string) StoreOption::getStoreOption($a->id, 'external_redirect_enabled'));
    }

    private function makeAdmin(): User
    {
        return User::factory()->create(['user_type' => 'admin', 'status' => 1, 'email_verified_at' => now()]);
    }

    private function makeStore(?string $title = null): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4),
            'description' => 'T',
            'monthly_price' => 10,
            'annual_price' => 100,
            'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = $title ?: 'R'.Str::random(6);

        return Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
    }
}
