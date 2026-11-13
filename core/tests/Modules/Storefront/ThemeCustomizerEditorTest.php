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
use Juwri\Modules\Storefront\Models\ThemeSetting;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Customizer v2 — WI-U1 (editor shell) and WI-L3 (conditional fields), server side.
 *
 * The shell itself is Alpine and is verified in a real browser, but three things
 * it depends on are server contracts and belong in the suite: the schema arrives
 * translated, Publish works for a store that never saved a draft, and Discard
 * actually throws the draft away.
 */
class ThemeCustomizerEditorTest extends TestCase
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

    public function test_the_schema_endpoint_returns_translated_labels(): void
    {
        [$owner, $store] = $this->storeFixture();

        $response = $this->actingAs($owner)->getJson(route('theme.customizer.schema', $store->id));
        $response->assertOk();

        $groups = collect($response->json('schema.settings'));
        $this->assertNotEmpty($groups);

        // The suite runs in Arabic, so an English group name coming back means the
        // theme.json label was never put through ___() — the exact gap that left
        // "Colors" sitting in the middle of an Arabic dashboard.
        $names = $groups->pluck('group')->all();
        $this->assertContains(___('Splash'), $names);
        $this->assertNotContains('Splash', $names, 'group labels must be translated, not passed through raw');

        $splash = $groups->firstWhere('group', ___('Splash'));
        $enabled = collect($splash['fields'])->firstWhere('id', 'splash.enabled');
        $this->assertSame(___('Show intro splash'), $enabled['label']);
        $this->assertNotSame('Show intro splash', $enabled['label']);
    }

    public function test_conditional_fields_carry_their_condition_to_the_editor(): void
    {
        [$owner, $store] = $this->storeFixture();

        $fields = collect($this->actingAs($owner)
            ->getJson(route('theme.customizer.schema', $store->id))
            ->json('schema.settings'))
            ->flatMap(fn ($g) => $g['fields'])
            ->keyBy('id');

        // The editor hides these until the shared splash layer is chosen. The
        // condition has to survive normalization to reach it.
        $this->assertSame(
            ['splash.enabled' => true, 'splash.style' => 'shared'],
            $fields['splash.duration']['visible_if'] ?? null
        );
        $this->assertArrayNotHasKey('visible_if', $fields['splash.enabled']);
    }

    public function test_a_hidden_field_is_still_validated_on_save(): void
    {
        [$owner, $store] = $this->storeFixture();

        // splash.duration is hidden while splash.style is `theme`. Hiding a control
        // must not become a way past the schema.
        $this->actingAs($owner)->postJson(route('theme.customizer.draft', $store->id), [
            'values' => ['splash.style' => 'theme', 'splash.duration' => 'not-a-number'],
        ])->assertStatus(422)->assertJsonValidationErrors(['splash.duration']);
    }

    public function test_publish_works_for_a_store_that_never_saved_a_draft(): void
    {
        [$owner, $store] = $this->storeFixture();
        $this->assertDatabaseCount('theme_settings', 0);

        // This used to 404 — publish() did a firstOrFail() on a row only saveDraft()
        // ever created, so the owner clicked Publish and nothing happened.
        $this->actingAs($owner)
            ->postJson(route('theme.customizer.publish', $store->id))
            ->assertOk()
            ->assertJson(['ok' => true]);

        $settings = ThemeSetting::where('store_id', $store->id)->firstOrFail()->settings;
        // assertEquals: JSON columns (MySQL / PostgreSQL) do not keep object key order.
        $this->assertEquals(
            ThemeCustomizerService::defaults(ThemeCustomizerService::schemaFor('crave')),
            $settings
        );
    }

    public function test_discarding_a_draft_leaves_the_published_settings_alone(): void
    {
        [$owner, $store] = $this->storeFixture();

        $this->actingAs($owner)->postJson(route('theme.customizer.draft', $store->id), [
            'values' => ['texts.hero_title' => 'Published'],
        ])->assertOk();
        $this->actingAs($owner)->postJson(route('theme.customizer.publish', $store->id))->assertOk();

        $this->actingAs($owner)->postJson(route('theme.customizer.draft', $store->id), [
            'values' => ['texts.hero_title' => 'Only a draft'],
        ])->assertOk();
        $this->assertSame('Only a draft', ThemeSetting::where('store_id', $store->id)->first()->draft['texts.hero_title']);

        $this->actingAs($owner)->postJson(route('theme.customizer.discard', $store->id))->assertOk();

        $row = ThemeSetting::where('store_id', $store->id)->firstOrFail();
        $this->assertNull($row->draft);
        $this->assertSame('Published', $row->settings['texts.hero_title']);
    }

    public function test_discard_is_scoped_to_the_owner(): void
    {
        [, $store] = $this->storeFixture();
        $stranger = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        $this->actingAs($stranger)
            ->postJson(route('theme.customizer.discard', $store->id))
            ->assertNotFound();
    }

    public function test_discarding_with_no_row_at_all_is_harmless(): void
    {
        [$owner, $store] = $this->storeFixture();

        $this->actingAs($owner)
            ->postJson(route('theme.customizer.discard', $store->id))
            ->assertOk()
            ->assertJson(['ok' => true]);
    }
}
