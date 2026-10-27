<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Models\ThemeSetting;
use Juwri\Modules\Storefront\Services\Theme\ContrastGuard;
use Juwri\Modules\Storefront\Services\Theme\Tokens;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Customizer v2 — WI-T6: the contrast guard (WARN-ONLY, DECISIONS K4).
 *
 * It must catch an unreadable pair, hand back a shade of the SAME colour that
 * passes, and — the load-bearing property — never block a publish.
 */
class ThemeContrastGuardTest extends TestCase
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

    public function test_the_ratio_math_matches_known_wcag_pairs(): void
    {
        // Black on white is the canonical 21:1; a colour on itself is 1:1.
        $this->assertEqualsWithDelta(21.0, Tokens::contrastRatio('#000000', '#ffffff'), 0.05);
        $this->assertEqualsWithDelta(1.0, Tokens::contrastRatio('#777777', '#777777'), 0.01);
        // #767676 on white is the textbook AA boundary (~4.54:1).
        $this->assertEqualsWithDelta(4.54, Tokens::contrastRatio('#767676', '#ffffff'), 0.1);
    }

    public function test_a_readable_palette_produces_no_warning(): void
    {
        $warnings = ContrastGuard::warnings('crave', [
            'colors.text' => '#f4f6f5',
            'colors.background' => '#15171a',
            'colors.surface' => '#22262b',
            'colors.primary' => '#3fd463',
            'colors.accent' => '#ffb02e',
        ]);

        $this->assertSame([], $warnings);
    }

    public function test_text_that_matches_its_background_is_flagged_with_a_passing_fix(): void
    {
        $warnings = ContrastGuard::warnings('crave', [
            'colors.text' => '#3a3a3a',
            'colors.background' => '#2c2c2c', // near-invisible grey on grey
            'colors.surface' => '#2c2c2c',
            'colors.primary' => '#3fd463',
        ]);

        $bodyOnBg = collect($warnings)->firstWhere('pair', 'Body text on background');
        $this->assertNotNull($bodyOnBg, 'grey-on-grey body text must be flagged');
        $this->assertLessThan(ContrastGuard::AA_NORMAL, $bodyOnBg['ratio']);
        $this->assertSame('colors.text', $bodyOnBg['field']);

        // The fix must be a real, passing colour — not just "try harder".
        $this->assertNotNull($bodyOnBg['suggestion']);
        $this->assertGreaterThanOrEqual(
            ContrastGuard::AA_NORMAL,
            Tokens::contrastRatio($bodyOnBg['suggestion'], '#2c2c2c')
        );
    }

    public function test_publish_never_blocks_and_carries_the_warnings(): void
    {
        [$owner, $store] = $this->storeFixture();

        // Publish a deliberately unreadable palette.
        $this->actingAs($owner)->postJson(route('theme.customizer.draft', $store->id), [
            'values' => ['colors.text' => '#2d2d2d', 'colors.background' => '#242424'],
        ])->assertOk();

        $response = $this->actingAs($owner)
            ->postJson(route('theme.customizer.publish', $store->id))
            ->assertOk(); // WARN-ONLY: a bad palette still publishes.

        $response->assertJsonPath('ok', true);
        $this->assertNotEmpty($response->json('contrast'));

        // And it really did publish — the values are live.
        $this->assertSame('#2d2d2d', ThemeSetting::where('store_id', $store->id)->first()->settings['colors.text']);
    }

    public function test_the_preview_endpoint_reports_warnings_without_publishing(): void
    {
        [$owner, $store] = $this->storeFixture();

        $response = $this->actingAs($owner)->postJson(route('theme.customizer.contrast', $store->id), [
            'values' => ['colors.text' => '#303030', 'colors.background' => '#272727'],
        ])->assertOk();

        $this->assertNotEmpty($response->json('contrast'));
        // Nothing was written — it is a read-only check.
        $this->assertDatabaseCount('theme_settings', 0);
    }

    public function test_the_contrast_check_is_scoped_to_the_owner(): void
    {
        [, $store] = $this->storeFixture();
        $stranger = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        $this->actingAs($stranger)
            ->postJson(route('theme.customizer.contrast', $store->id), ['values' => []])
            ->assertNotFound();
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
