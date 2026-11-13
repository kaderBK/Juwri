<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Services\Theme\Tokens;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Customizer v2 — WI-U3: the live-preview channel, server side.
 *
 * The channel is cosmetic and persists nothing, but it must not become a second,
 * looser way into the customizer. What is pinned here: it is ownership-scoped, it
 * validates against the schema exactly as a draft save does, it never writes, and
 * the values it hands back are the same derived tokens the storefront renders.
 */
class ThemePreviewChannelTest extends TestCase
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

    public function test_it_returns_the_derived_ramp_for_a_candidate_colour(): void
    {
        [$owner, $store] = $this->storeFixture();

        $vars = $this->actingAs($owner)->postJson(route('theme.customizer.tokens', $store->id), [
            'values' => ['colors.primary' => '#ff2d55'],
        ])->assertOk()->json('vars');

        $this->assertSame('#ff2d55', $vars['--tc-primary']);
        // The whole point of asking the server: the ramp comes from the same mix
        // maths the storefront renders with, so the preview cannot drift from it.
        $this->assertSame(Tokens::shade('#ff2d55', 0.12), $vars['--tc-primary-600']);
        $this->assertSame('255, 45, 85', $vars['--tc-primary-rgb']);
    }

    public function test_it_only_ever_emits_token_custom_properties(): void
    {
        [$owner, $store] = $this->storeFixture();

        $vars = $this->actingAs($owner)->postJson(route('theme.customizer.tokens', $store->id), [
            'values' => ['colors.primary' => '#123456'],
        ])->assertOk()->json('vars');

        $this->assertNotEmpty($vars);
        foreach (array_keys($vars) as $name) {
            $this->assertMatchesRegularExpression(
                '/^--t[crsf]-[a-z0-9-]+$/',
                $name,
                "[{$name}] is outside the token namespace the preview bridge accepts"
            );
        }
    }

    public function test_it_reports_invalid_values_and_drops_them_instead_of_blanking_the_preview(): void
    {
        [$owner, $store] = $this->storeFixture();

        $response = $this->actingAs($owner)->postJson(route('theme.customizer.tokens', $store->id), [
            // Mid-typing: a half-finished hex must not take the whole preview down.
            'values' => ['colors.primary' => '#ff2', 'colors.background' => 'nonsense'],
        ])->assertOk();

        $response->assertJsonPath('ok', false);
        $this->assertArrayHasKey('colors.background', $response->json('errors'));
        $this->assertNotEmpty($response->json('vars'));
    }

    public function test_an_unknown_key_is_rejected_not_rendered(): void
    {
        [$owner, $store] = $this->storeFixture();

        $response = $this->actingAs($owner)->postJson(route('theme.customizer.tokens', $store->id), [
            'values' => ['evil.key' => 'body{display:none}'],
        ])->assertOk();

        $this->assertArrayHasKey('evil.key', $response->json('errors'));
        $this->assertArrayNotHasKey('evil.key', $response->json('vars'));
    }

    public function test_it_never_writes_anything(): void
    {
        [$owner, $store] = $this->storeFixture();

        $this->actingAs($owner)->postJson(route('theme.customizer.tokens', $store->id), [
            'values' => ['colors.primary' => '#abcdef'],
        ])->assertOk();

        // Cosmetic channel: the authoritative path is still save → validate → draft.
        $this->assertDatabaseCount('theme_settings', 0);
    }

    public function test_it_is_scoped_to_the_owner(): void
    {
        [, $store] = $this->storeFixture();
        $stranger = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        $this->actingAs($stranger)
            ->postJson(route('theme.customizer.tokens', $store->id), ['values' => []])
            ->assertNotFound();
    }

    public function test_the_preview_language_applies_to_one_request_and_never_the_session(): void
    {
        [$owner, $store] = $this->storeFixture();
        Language::updateOrCreate(
            ['code' => 'en'],
            ['name' => 'English', 'direction' => 'ltr', 'active' => 1]
        );

        $this->actingAs($owner)->get('/'.$store->slug.'?preview_theme=1&preview_lang=en');

        // The whole point of a request-scoped locale: previewing the storefront in
        // another language must not flip the owner's own dashboard, which every
        // session-backed switcher in this app would do.
        $this->assertNull(session('locale'));
    }

    public function test_an_unknown_preview_language_is_ignored(): void
    {
        [$owner, $store] = $this->storeFixture();

        // The storefront itself cannot be rendered under APP_INSTALLED=false (the
        // $settings view composer is not registered), so this asserts the thing
        // that matters: an unknown code changes nothing.
        $this->actingAs($owner)->get('/'.$store->slug.'?preview_theme=1&preview_lang=zz-not-a-language');

        $this->assertNull(session('locale'));
        $this->assertNotSame('zz-not-a-language', app()->getLocale());
    }

    public function test_the_editor_is_told_the_stores_own_languages(): void
    {
        [$owner, $store] = $this->storeFixture();
        Language::updateOrCreate(
            ['code' => 'ar'],
            ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]
        );
        StoreOption::updateStoreOption($store->id, 'default_language', 'ar');

        $languages = $this->actingAs($owner)
            ->getJson(route('theme.customizer.schema', $store->id))
            ->assertOk()->json('languages');

        // DECISIONS K5: the store's own menu languages, not the eleven the
        // platform speaks — and a single-language store gets exactly one entry.
        $this->assertSame(['ar'], array_column($languages, 'code'));
        $this->assertTrue($languages[0]['rtl']);
    }

    public function test_a_legacy_key_is_folded_before_it_is_validated(): void
    {
        [$owner, $store] = $this->storeFixture();

        // An old client (or an imported file) may still send the pre-v2 id; the
        // channel resolves it rather than rejecting the owner's own setting.
        $response = $this->actingAs($owner)->postJson(route('theme.customizer.tokens', $store->id), [
            'values' => ['toggles.show_splash' => false],
        ])->assertOk();

        $this->assertSame([], $response->json('errors'));
    }
}
