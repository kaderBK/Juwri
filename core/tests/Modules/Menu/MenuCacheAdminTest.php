<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Services\Storefront\StorefrontMenuCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\TestCase;

/**
 * WI-M2 — the superadmin Menu Cache panel lists stores and their tokens, reports a
 * missing token WITHOUT minting one (render must not change state), lets an operator
 * rebuild, and is closed to non-admins.
 */
class MenuCacheAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $settings = new class(['theme_color' => '#111', 'site_title' => 'TestApp', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png', 'currency_sign' => '$', 'currency_pos' => 1])
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
        view()->share([
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

    private function makeAdmin(): User
    {
        return User::factory()->create(['group_id' => '1', 'status' => 1, 'email_verified_at' => now(), 'user_type' => 'admin']);
    }

    private function makeOwner(): User
    {
        return User::factory()->create(['group_id' => '2', 'status' => 1, 'email_verified_at' => now()]);
    }

    private function store(): Store
    {
        $owner = $this->makeOwner();
        $title = 'R'.Str::random(6);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)), 'color' => '#111',
            'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1',
        ]);
    }

    public function test_panel_lists_a_store_and_its_token(): void
    {
        $store = $this->store();
        StorefrontMenuCache::version($store->id); // warm the token

        $this->actingAs($this->makeAdmin())
            ->get(route('admin.menu-cache.index'))
            ->assertOk()->assertSee($store->title)->assertSeeHtml('data-menu-cache-token');
    }

    public function test_a_missing_token_is_reported_not_hidden(): void
    {
        $store = $this->store();
        Cache::forget('menu.ver:'.$store->id);

        $this->actingAs($this->makeAdmin())
            ->get(route('admin.menu-cache.index'))
            ->assertOk()
            ->assertSee(___('Not cached — rebuilt on the next storefront request'));

        // Rendering the panel must not have minted a token.
        $this->assertNull(Cache::get('menu.ver:'.$store->id));
    }

    public function test_rebuild_replaces_the_token(): void
    {
        $store = $this->store();
        $before = StorefrontMenuCache::version($store->id);

        $this->actingAs($this->makeAdmin())
            ->post(route('admin.menu-cache.bust', $store->id))
            ->assertRedirect();

        $this->assertNotSame($before, StorefrontMenuCache::version($store->id));
    }

    public function test_a_non_admin_cannot_reach_the_panel(): void
    {
        $status = $this->actingAs($this->makeOwner())
            ->get(route('admin.menu-cache.index'))
            ->getStatusCode();

        $this->assertTrue($status < 200 || $status >= 300, 'a store owner must not get a 2xx');
    }

    public function test_rebuild_404s_for_an_unknown_store(): void
    {
        $this->actingAs($this->makeAdmin())
            ->post(route('admin.menu-cache.bust', 999999))
            ->assertNotFound();
    }
}
