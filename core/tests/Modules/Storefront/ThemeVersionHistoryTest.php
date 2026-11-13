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
use Juwri\Modules\Storefront\Models\ThemeSettingVersion;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Customizer v2 — WI-V1: version history + revert.
 *
 * The contract: every publish snapshots a version, revert is a NEW version (the
 * timeline never loses an entry), the history reports per-version change counts,
 * and it is ownership-scoped.
 */
class ThemeVersionHistoryTest extends TestCase
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

    public function test_each_publish_snapshots_a_version(): void
    {
        [$owner, $store] = $this->storeFixture();

        $this->publish($owner, $store, ['colors.primary' => '#111111']);
        $this->publish($owner, $store, ['colors.primary' => '#222222']);

        $versions = ThemeSettingVersion::where('store_id', $store->id)->orderBy('id')->get();
        $this->assertCount(2, $versions);
        $this->assertSame('publish', $versions[0]->label);
        $this->assertSame('#222222', $versions[1]->settings['colors.primary']);
    }

    public function test_revert_is_a_new_version_and_never_destroys_history(): void
    {
        [$owner, $store] = $this->storeFixture();

        $this->publish($owner, $store, ['colors.primary' => '#aaaaaa']); // v1
        $this->publish($owner, $store, ['colors.primary' => '#bbbbbb']); // v2

        $v1 = ThemeSettingVersion::where('store_id', $store->id)->orderBy('id')->first();

        $this->actingAs($owner)
            ->postJson(route('theme.customizer.revert', [$store->id, $v1->id]))
            ->assertOk()->assertJsonPath('ok', true);

        // The live settings are back to v1…
        $this->assertSame('#aaaaaa', ThemeSetting::where('store_id', $store->id)->first()->settings['colors.primary']);
        // …and the revert is recorded as a THIRD version, not a rewrite of history.
        $versions = ThemeSettingVersion::where('store_id', $store->id)->orderBy('id')->get();
        $this->assertCount(3, $versions);
        $this->assertSame('revert', $versions[2]->label);
        $this->assertSame('#aaaaaa', $versions[2]->settings['colors.primary']);
    }

    public function test_history_reports_the_change_count_per_version(): void
    {
        [$owner, $store] = $this->storeFixture();

        $this->publish($owner, $store, ['colors.primary' => '#111111']);
        $this->publish($owner, $store, ['colors.primary' => '#222222', 'texts.hero_title' => 'Hi']);

        $history = $this->actingAs($owner)
            ->getJson(route('theme.customizer.history', $store->id))
            ->assertOk()->json('history');

        // Newest first; the second publish changed 2 keys from the first.
        $this->assertCount(2, $history);
        $this->assertSame(2, $history[0]['changed']);
        $this->assertSame(0, $history[1]['changed'], 'the first version has nothing before it');
    }

    public function test_the_diff_lists_the_changed_keys_old_to_new(): void
    {
        [$owner, $store] = $this->storeFixture();

        $this->publish($owner, $store, ['colors.primary' => '#111111']); // v1
        $this->publish($owner, $store, ['colors.primary' => '#999999']); // v2 = current

        $v1 = ThemeSettingVersion::where('store_id', $store->id)->orderBy('id')->first();

        $diff = $this->actingAs($owner)
            ->getJson(route('theme.customizer.versionDiff', [$store->id, $v1->id]))
            ->assertOk()->json('diff');

        $primary = collect($diff)->firstWhere('key', 'colors.primary');
        $this->assertSame('#111111', $primary['from']);
        $this->assertSame('#999999', $primary['to']);
    }

    public function test_reverting_to_an_unknown_version_is_a_clean_404(): void
    {
        [$owner, $store] = $this->storeFixture();

        $this->actingAs($owner)
            ->postJson(route('theme.customizer.revert', [$store->id, 999999]))
            ->assertStatus(404)->assertJsonPath('ok', false);
    }

    public function test_history_is_owner_scoped(): void
    {
        [, $store] = $this->storeFixture();
        $stranger = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        $this->actingAs($stranger)
            ->getJson(route('theme.customizer.history', $store->id))
            ->assertNotFound();
    }

    public function test_a_stranger_cannot_revert_another_stores_version(): void
    {
        [$owner, $store] = $this->storeFixture();
        $this->publish($owner, $store, ['colors.primary' => '#111111']);
        $v = ThemeSettingVersion::where('store_id', $store->id)->first();

        $stranger = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $this->actingAs($stranger)
            ->postJson(route('theme.customizer.revert', [$store->id, $v->id]))
            ->assertNotFound();
    }

    private function publish(User $owner, Store $store, array $values): void
    {
        $this->actingAs($owner)->postJson(route('theme.customizer.draft', $store->id), ['values' => $values])->assertOk();
        $this->actingAs($owner)->postJson(route('theme.customizer.publish', $store->id))->assertOk();
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
