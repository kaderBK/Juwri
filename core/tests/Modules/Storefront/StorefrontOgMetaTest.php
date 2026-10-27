<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * CC run — platform-wide share cards. The shared layout (all 17 modern themes)
 * turns a shared link into a photo card: og:image = the cover when one exists,
 * twitter:card = summary_large_image, and the store's subtitle wins the
 * description. A store with no cover and no subtitle renders the same head as
 * before — pure source-priority, no regression.
 */
class StorefrontOgMetaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    /** @param  array<string,mixed>  $attrs */
    private function store(array $attrs = [], string $theme = 'crave'): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'allow_ordering' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create(array_merge([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => 'Crave House', 'sub_title' => '', 'description' => 'PlainDescriptionText',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ], $attrs));
        StoreOption::updateStoreOption($store->id, 'store_template', $theme);

        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => '1']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Club Sandwich', 'price' => 42.00, 'active' => '1']);

        return $store;
    }

    private function render(Store $store): string
    {
        return $this->get('/'.$store->slug)->assertOk()->getContent();
    }

    public function test_a_cover_store_gets_a_photo_share_card(): void
    {
        $store = $this->store([
            'cover_image' => 'showcase2-diner-interior.jpg',
            'sub_title' => 'American grill and late night cravings',
        ]);

        $html = $this->render($store);

        $this->assertStringContainsString('property="og:image" content="', $html);
        $this->assertStringContainsString('store/cover/showcase2-diner-interior.jpg', $html);
        $this->assertStringContainsString('name="twitter:card" content="summary_large_image"', $html);
        $this->assertStringContainsString('property="og:description" content="American grill and late night cravings', $html);
    }

    public function test_a_coverless_store_keeps_the_logo_summary_card(): void
    {
        // No cover, no subtitle: the head must match the pre-CC behaviour.
        $store = $this->store(['cover_image' => 'default.png', 'sub_title' => '']);

        $html = $this->render($store);

        $this->assertStringContainsString('name="twitter:card" content="summary"', $html);
        $this->assertStringNotContainsString('summary_large_image', $html);
        $this->assertStringContainsString('store/logo/', $html);
        $this->assertStringNotContainsString('store/cover/', $html);
        // Description still falls back to the store description.
        $this->assertStringContainsString('PlainDescriptionText', $html);
    }

    public function test_subtitle_wins_the_description_even_without_a_cover(): void
    {
        $store = $this->store(['cover_image' => 'default.png', 'sub_title' => 'SubtitleWins']);

        $html = $this->render($store);

        $this->assertStringContainsString('property="og:description" content="SubtitleWins', $html);
        $this->assertStringNotContainsString('PlainDescriptionText', $html);
        // A subtitle alone (no cover) does not upgrade the card type.
        $this->assertStringContainsString('name="twitter:card" content="summary"', $html);
    }

    public function test_the_og_upgrade_is_platform_wide_not_crave_only(): void
    {
        // Proves the change lives in the shared layout: a different modern theme
        // gets the same photo card.
        $store = $this->store([
            'cover_image' => 'showcase2-diner-interior.jpg',
            'sub_title' => 'Neighbourhood bakery',
        ], 'aurora');

        $html = $this->render($store);

        $this->assertStringContainsString('store/cover/showcase2-diner-interior.jpg', $html);
        $this->assertStringContainsString('name="twitter:card" content="summary_large_image"', $html);
    }
}
