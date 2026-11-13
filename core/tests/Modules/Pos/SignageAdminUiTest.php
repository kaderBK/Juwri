<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Pos\Models\SignageScreen;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GAPS-RUN-2 B6-P2: signage owner config UI.
 */
class SignageAdminUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        config(['settings.admin_allow_digital_signage' => true]);
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

    public function test_index_renders_with_nav(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $this->withoutMiddleware();
        $res = $this->actingAs($owner)->get(route('stores.signage.index').'?store_id='.$r->id)->assertOk();
        $res->assertSee('Add a screen');
        $res->assertSeeHtml('signage');
    }

    public function test_create_screen_generates_token(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $this->withoutMiddleware();

        $this->actingAs($owner)->post(route('stores.signage.store').'?store_id='.$r->id, [
            'kind' => 'menu', 'name' => 'Counter TV',
        ])->assertRedirect();

        $screen = SignageScreen::where('store_id', $r->id)->first();
        $this->assertNotNull($screen);
        $this->assertSame('menu', $screen->kind);
        $this->assertNotEmpty($screen->token);
    }

    public function test_regenerate_revokes_old_token(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $screen = SignageScreen::create(['store_id' => $r->id, 'kind' => 'menu', 'token' => SignageScreen::newToken(), 'active' => true]);
        $old = $screen->token;
        $this->withoutMiddleware();

        $this->actingAs($owner)->post(route('stores.signage.regenerate', $screen->id).'?store_id='.$r->id)->assertRedirect();

        $this->assertNotSame($old, $screen->fresh()->token);
        // Old token no longer resolves.
        $this->assertNull(SignageScreen::resolve($old));
    }

    public function test_screen_ops_are_tenant_scoped(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        [$other, $otherR] = $this->ownerRestaurant();
        $foreign = SignageScreen::create(['store_id' => $otherR->id, 'kind' => 'menu', 'token' => SignageScreen::newToken(), 'active' => true]);
        $this->withoutMiddleware();

        $this->actingAs($owner)->post(route('stores.signage.regenerate', $foreign->id).'?store_id='.$r->id)->assertNotFound();
    }
}
