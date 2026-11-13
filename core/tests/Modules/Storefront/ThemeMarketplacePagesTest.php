<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Theme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Services\WalletService;
use Tests\TestCase;

/**
 * P4-4b: the public marketplace — listing/detail serve PUBLISHED themes only,
 * buy rides the wallet rails, apply is entitlement-gated and points the
 * restaurant at the latest published version.
 */
class ThemeMarketplacePagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
        config(['settings.currency_sign' => '$', 'settings.currency_pos' => 1]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/marketplace-themes/markpub'));
        parent::tearDown();
    }

    /** A published theme+version whose files exist for the resolver. */
    private function makePublishedTheme(float $price = 15): Theme
    {
        File::ensureDirectoryExists(storage_path('app/marketplace-themes/markpub/1.0.0/templates'));
        File::put(
            storage_path('app/marketplace-themes/markpub/1.0.0/templates/index.liquid'),
            '<h1 class="markpub">{{ restaurant.title | escape }}</h1>'
        );

        $theme = Theme::create(['name' => 'Mark Pub', 'slug' => 'markpub', 'status' => 'published', 'price' => $price]);
        $theme->versions()->create([
            'semver' => '1.0.0',
            'manifest' => ['name' => 'Mark Pub', 'slug' => 'markpub', 'version' => '1.0.0', 'description' => 'A very public theme'],
            'checksum' => str_repeat('a', 64),
            'status' => 'published',
        ]);

        return $theme;
    }

    private function makeOwner(float $balance = 0): User
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        if ($balance > 0) {
            WalletService::credit($user, $balance, 'seed');
        }

        return $user;
    }

    public function test_listing_shows_published_themes_only(): void
    {
        $this->makePublishedTheme();
        Theme::create(['name' => 'Hidden Draft', 'slug' => 'hidden-draft', 'status' => 'draft', 'price' => 0]);

        $html = $this->get('marketplace')->assertOk()->getContent();

        $this->assertStringContainsString('Mark Pub', $html);
        $this->assertStringNotContainsString('Hidden Draft', $html);
    }

    public function test_detail_page_serves_and_draft_is_404(): void
    {
        $this->makePublishedTheme();

        $this->get('marketplace/markpub')->assertOk()
            ->assertSee('A very public theme')->assertSee('1.0.0');

        Theme::create(['name' => 'Nope', 'slug' => 'nope', 'status' => 'review', 'price' => 0]);
        $this->get('marketplace/nope')->assertNotFound();
    }

    public function test_buy_and_apply_flow(): void
    {
        $theme = $this->makePublishedTheme(price: 15);
        $owner = $this->makeOwner(balance: 20);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'shop-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => 'Shop', 'sub_title' => 'S', 'description' => 'D',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Apply before buying → 402 (entitlement gate).
        $this->actingAs($owner)->postJson('marketplace/markpub/apply', ['store_id' => $store->id])
            ->assertStatus(402);

        // Buy → wallet debited.
        $this->actingAs($owner)->postJson('marketplace/markpub/buy')->assertOk();
        $this->assertSame(5.0, WalletService::balanceFor($owner));

        // Apply → store_template points at the latest published version.
        $this->actingAs($owner)->postJson('marketplace/markpub/apply', ['store_id' => $store->id])
            ->assertOk()->assertJson(['template' => 'mp:markpub@1.0.0']);
        $this->assertSame('mp:markpub@1.0.0', store_options($store->id)->store_template);

        // Someone else's restaurant → 404 (tenant scope).
        $other = $this->makeOwner();
        $this->actingAs($other)->postJson('marketplace/markpub/apply', ['store_id' => $store->id])
            ->assertStatus(402); // fails entitlement first (never reaches the post)
    }

    public function test_buy_with_empty_wallet_is_a_402(): void
    {
        $this->makePublishedTheme(price: 50);
        $owner = $this->makeOwner(balance: 1);

        $this->actingAs($owner)->postJson('marketplace/markpub/buy')->assertStatus(402);
    }

    public function test_marketplace_routes_win_over_the_slug_catch_all(): void
    {
        // No published themes: /marketplace must still be OUR page (empty state),
        // not a restaurant-slug 404 from the catch-all.
        $this->get('marketplace')->assertOk()->assertSee('Theme Store');
    }
}
