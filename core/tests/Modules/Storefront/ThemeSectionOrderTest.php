<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use App\Services\ThemeCustomizerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Customizer v2 — WI-L2: section drag-reorder.
 *
 * `sections._order` is a validated list of the theme's own section ids, and
 * theme_sections() returns the sections in that order — the storefront reads it,
 * so a reorder in the editor moves the blocks on the page.
 */
class ThemeSectionOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    public function test_a_valid_order_is_accepted_and_a_bogus_one_is_rejected(): void
    {
        $schema = ThemeCustomizerService::schemaFor('crave');

        $this->assertSame([], ThemeCustomizerService::validate($schema, [
            'sections._order' => ['menu', 'daily_specials', 'catbar'],
        ]));

        // An id the theme does not have cannot be in the order.
        $errors = ThemeCustomizerService::validate($schema, [
            'sections._order' => ['menu', 'not-a-section'],
        ]);
        $this->assertArrayHasKey('sections._order', $errors);

        // Nor may it be anything but a list.
        $this->assertArrayHasKey('sections._order', ThemeCustomizerService::validate($schema, [
            'sections._order' => 'menu',
        ]));
    }

    public function test_theme_sections_follows_the_saved_order(): void
    {
        [$owner, $store] = $this->storeFixture('crave');

        // Default: the schema order.
        $this->assertSame(
            ['brand_row', 'daily_specials', 'catbar', 'menu'],
            array_column(theme_sections($store->id), 'id')
        );

        // Publish a new order, and the storefront helper follows it.
        $this->actingAs($owner)->postJson(route('theme.customizer.draft', $store->id), [
            'values' => ['sections._order' => ['menu', 'daily_specials', 'catbar']],
        ])->assertOk();
        $this->actingAs($owner)->postJson(route('theme.customizer.publish', $store->id))->assertOk();

        $this->assertSame(
            ['menu', 'daily_specials', 'catbar', 'brand_row'],
            array_column(theme_sections($store->id), 'id')
        );
    }

    public function test_an_order_that_omits_a_section_still_renders_it_last(): void
    {
        [$owner, $store] = $this->storeFixture('crave');

        $this->actingAs($owner)->postJson(route('theme.customizer.draft', $store->id), [
            'values' => ['sections._order' => ['menu']], // names only one
        ])->assertOk();
        $this->actingAs($owner)->postJson(route('theme.customizer.publish', $store->id))->assertOk();

        // The named one leads; the rest keep their schema order after it — nothing
        // silently disappears just because the order did not mention it.
        $this->assertSame(
            ['menu', 'brand_row', 'daily_specials', 'catbar'],
            array_column(theme_sections($store->id), 'id')
        );
    }

    /** @return array{0:User,1:Store} */
    private function storeFixture(string $template = 'crave'): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999], 'status' => 1,
        ]);
        $owner = User::factory()->create([
            'group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(),
        ]);
        $title = 'R '.Str::random(5);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', $template);

        return [$owner, $store];
    }
}
