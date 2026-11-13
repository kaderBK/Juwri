<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Models\ThemeSetting;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Customizer v2 — WI-V2 (export/import) and WI-V3 (copy between stores).
 *
 * The shared thread: settings can leave and re-enter the customizer, but only
 * ever through the schema — an import or copy is validated against the TARGET's
 * current schema, unknown/invalid keys are dropped and reported, and the result
 * is STAGED as a draft, never published straight onto a live store. Everything
 * is ownership-scoped, and a cross-theme transfer is refused.
 */
class ThemeExportImportCopyTest extends TestCase
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

    public function test_export_returns_the_published_settings_as_a_download(): void
    {
        [$owner, $store] = $this->storeFixture();
        $this->publish($owner, $store, ['colors.primary' => '#abcdef']);

        $response = $this->actingAs($owner)->get(route('theme.customizer.export', $store->id))->assertOk();

        $response->assertHeader('content-disposition');
        $this->assertSame('crave', $response->json('template'));
        $this->assertSame('#abcdef', $response->json('settings')['colors.primary']);
    }

    public function test_import_keeps_known_keys_drops_unknown_and_stages_a_draft(): void
    {
        [$owner, $store] = $this->storeFixture();

        $file = UploadedFile::fake()->createWithContent('theme.json', json_encode([
            'customizer' => 'v2', 'template' => 'crave',
            'settings' => ['colors.primary' => '#123456', 'made.up.key' => 'x', 'colors.background' => 'not-a-hex'],
        ]));

        $response = $this->actingAs($owner)
            ->post(route('theme.customizer.import', $store->id), ['file' => $file])
            ->assertOk()->assertJsonPath('ok', true);

        // The valid key carried; the unknown and the invalid were reported dropped.
        $this->assertSame('#123456', $response->json('values')['colors.primary']);
        $this->assertContains('made.up.key', $response->json('dropped'));
        $this->assertContains('colors.background', $response->json('dropped'));

        // Staged as a draft — not published.
        $ts = ThemeSetting::where('store_id', $store->id)->firstOrFail();
        $this->assertSame('#123456', $ts->draft['colors.primary']);
    }

    public function test_import_of_a_different_theme_is_rejected(): void
    {
        [$owner, $store] = $this->storeFixture('crave');

        $file = UploadedFile::fake()->createWithContent('theme.json', json_encode([
            'template' => 'velora', 'settings' => ['colors.primary' => '#123456'],
        ]));

        $this->actingAs($owner)
            ->post(route('theme.customizer.import', $store->id), ['file' => $file])
            ->assertStatus(422)->assertJsonPath('ok', false);

        $this->assertDatabaseCount('theme_settings', 0);
    }

    public function test_import_of_junk_is_a_clean_422(): void
    {
        [$owner, $store] = $this->storeFixture();
        $file = UploadedFile::fake()->createWithContent('x.json', 'not json at all');

        $this->actingAs($owner)
            ->post(route('theme.customizer.import', $store->id), ['file' => $file])
            ->assertStatus(422);
    }

    public function test_copy_targets_are_the_owners_other_stores_on_the_same_theme(): void
    {
        [$owner, $store] = $this->storeFixture('crave');
        [, $sameTheme] = $this->storeFixture('crave', $owner);
        [, $otherTheme] = $this->storeFixture('velora', $owner);
        [, $someoneElse] = $this->storeFixture('crave'); // different owner

        $targets = collect($this->actingAs($owner)
            ->getJson(route('theme.customizer.copyTargets', $store->id))
            ->assertOk()->json('targets'))->pluck('id');

        $this->assertTrue($targets->contains($sameTheme->id));
        $this->assertFalse($targets->contains($otherTheme->id), 'a different theme is not a target');
        $this->assertFalse($targets->contains($someoneElse->id), 'another owner\'s store is not a target');
    }

    public function test_copy_stages_a_draft_on_the_target_and_never_publishes(): void
    {
        [$owner, $source] = $this->storeFixture('crave');
        [, $target] = $this->storeFixture('crave', $owner);
        $this->publish($owner, $source, ['colors.primary' => '#00ff00']);

        $this->actingAs($owner)
            ->postJson(route('theme.customizer.copyTo', $source->id), ['target' => $target->id])
            ->assertOk()->assertJsonPath('ok', true);

        $ts = ThemeSetting::where('store_id', $target->id)->firstOrFail();
        $this->assertSame('#00ff00', $ts->draft['colors.primary'], 'staged as a draft on the target');
        $this->assertNotSame('#00ff00', theme_setting($target->id, 'colors.primary', '#3fd463'), 'not published');
    }

    public function test_copy_to_a_cross_owner_store_is_forbidden(): void
    {
        [$owner, $source] = $this->storeFixture('crave');
        [, $strangerStore] = $this->storeFixture('crave'); // different owner

        $this->actingAs($owner)
            ->postJson(route('theme.customizer.copyTo', $source->id), ['target' => $strangerStore->id])
            ->assertNotFound();
    }

    private function publish(User $owner, Store $store, array $values): void
    {
        $this->actingAs($owner)->postJson(route('theme.customizer.draft', $store->id), ['values' => $values])->assertOk();
        $this->actingAs($owner)->postJson(route('theme.customizer.publish', $store->id))->assertOk();
    }

    /** @return array{0:User,1:Store} */
    private function storeFixture(string $template = 'crave', ?User $owner = null): array
    {
        if ($owner === null) {
            $plan = TestPlans::create([
                'name' => 'P '.Str::random(5), 'description' => 'T',
                'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
                'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999], 'status' => 1,
            ]);
            $owner = User::factory()->create([
                'group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(),
            ]);
        }
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
