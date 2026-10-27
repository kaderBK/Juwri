<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * #39 Theme customizer (public_theme_mode, store_template store_options)
 * #43 Custom font upload (woff2 → StoreOption)
 * #44 Section ordering (menu_sections_json → StoreOption)
 *
 * All three features share the PUT stores/{store} endpoint.
 */
class ThemeCustomizerTest extends TestCase
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

    private function basePayload(Store $store): array
    {
        return [
            'color' => '#ff5500',
            'title' => $store->title,
            'description' => $store->description,
            'address' => $store->address,
            'store_template' => 'aurora',
            'currency_code' => 'USD',
            'currency_sign' => '$',
            'currency_pos' => '1',
        ];
    }

    // ── #39 Theme customizer ──

    /** public_theme_mode (light/dark/auto) saves as StoreOption */
    public function test_public_theme_mode_persists(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        $this->actingAs($owner)
            ->put(route('stores.update', $store), array_merge($this->basePayload($store), [
                'public_theme_mode' => 'dark',
            ]))
            ->assertRedirect();

        $this->assertEquals('dark', StoreOption::getStoreOption($store->id, 'public_theme_mode'));
    }

    /** store_template saved as StoreOption (Aurora for public menus) */
    public function test_restaurant_template_saves_aurora(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        $this->actingAs($owner)
            ->put(route('stores.update', $store), $this->basePayload($store))
            ->assertRedirect();

        $this->assertEquals('aurora', StoreOption::getStoreOption($store->id, 'store_template'));
    }

    /** Tenant isolation: other owner cannot update restaurant settings */
    public function test_tenant_isolation_update(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        $this->actingAs($other)
            ->put(route('stores.update', $store), $this->basePayload($store))
            ->assertNotFound(); // Controller abort(404) when user_id mismatch

        // store_template NOT saved (owner wasn't the actor)
        $this->assertFalse(StoreOption::getStoreOption($store->id, 'store_template'));
    }

    // ── #44 Section ordering ──

    /** menu_sections_json saves to menu_sections StoreOption */
    public function test_section_ordering_persists(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        $sections = json_encode(['specials', 'mains', 'desserts']);

        $this->actingAs($owner)
            ->put(route('stores.update', $store), array_merge($this->basePayload($store), [
                'menu_sections_json' => $sections,
            ]))
            ->assertRedirect();

        // getStoreOption auto-decodes JSON → compare as decoded array
        $this->assertEquals(json_decode($sections), StoreOption::getStoreOption($store->id, 'menu_sections'));
    }

    // ── #43 Custom font upload ──

    /** woff2 font upload saves filename to StoreOption */
    public function test_custom_font_upload_saves_post_option(): void
    {
        // Don't use Storage::fake — the controller calls $request->file->move() to public_path
        // Instead, verify the StoreOption is set when extension + size pass validation
        // We use a minimal woff2 fake and check the DB row
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        $fontFile = UploadedFile::fake()->createWithContent('custom.woff2', 'wOF2'.str_repeat('a', 200));

        $this->actingAs($owner)
            ->put(route('stores.update', $store), array_merge($this->basePayload($store), [
                'menu_font_file' => $fontFile,
            ]))
            ->assertRedirect();

        $saved = StoreOption::getStoreOption($store->id, 'menu_font_file');
        $this->assertNotEmpty($saved, 'menu_font_file StoreOption should be saved after woff2 upload');
    }

    /** remove_menu_font_file=1 clears the StoreOption */
    public function test_remove_font_clears_post_option(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        // Seed an existing font option
        StoreOption::updateStoreOption($store->id, 'menu_font_file', 'existing_font.woff2');

        $this->actingAs($owner)
            ->put(route('stores.update', $store), array_merge($this->basePayload($store), [
                'remove_menu_font_file' => '1',
            ]))
            ->assertRedirect();

        $saved = StoreOption::getStoreOption($store->id, 'menu_font_file');
        $this->assertEmpty($saved);
    }
}
