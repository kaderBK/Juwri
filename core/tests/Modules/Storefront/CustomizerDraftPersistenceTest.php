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
use Symfony\Component\Finder\Finder;
use Tests\Support\TestPlans;
use Tests\Support\ViewRoots;
use Tests\TestCase;

/**
 * e2e b-themes "second draft did not persist": the customizer ran its init() TWICE (Alpine 3 calls a component's
 * init() itself and the root also had x-init="init()"), so two load() requests raced; when the second answer landed
 * after the owner's first edit it reset `values` to the server state and `dirty` to false, and the next edit saved a
 * draft without the first one (trace: colour set to #c2185b, the font change then sent colors.primary=#3a7bd5).
 * Fixed in the view (no x-init), and the save path no longer drops a save: queued while one is in flight, retried on
 * 429/5xx/network, reported otherwise; the server merges drafts and publishes under a row lock.
 */
class CustomizerDraftPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    public function test_a_second_draft_after_a_publish_is_kept_whole(): void
    {
        [$owner, $store] = $this->store();
        $this->draft($owner, $store, ['colors.primary' => '#3a7bd5', 'fonts.pair' => 'jakarta']);
        $this->actingAs($owner)->postJson(route('theme.customizer.publish', $store->id))->assertOk();

        // The second look, saved in two quick requests (one per edit).
        $this->draft($owner, $store, ['colors.primary' => '#c2185b']);
        $this->draft($owner, $store, ['fonts.pair' => 'syne-instrument']);

        $ts = ThemeSetting::where('store_id', $store->id)->firstOrFail();
        $this->assertSame('#c2185b', $ts->draft['colors.primary']);
        $this->assertSame('syne-instrument', $ts->draft['fonts.pair']);
        $this->assertSame('#3a7bd5', $ts->settings['colors.primary'], 'published look untouched until publish');

        $this->actingAs($owner)->postJson(route('theme.customizer.publish', $store->id))->assertOk();
        $ts->refresh();
        $this->assertNull($ts->draft);
        $this->assertSame('#c2185b', $ts->settings['colors.primary']);
        $this->assertSame('syne-instrument', $ts->settings['fonts.pair']);
    }

    public function test_the_draft_answer_echoes_the_merged_draft(): void
    {
        [$owner, $store] = $this->store();
        $this->draft($owner, $store, ['colors.primary' => '#111111']);

        $draft = $this->actingAs($owner)->postJson(route('theme.customizer.draft', $store->id), ['values' => ['fonts.pair' => 'jakarta']])
            ->assertOk()->json('draft');
        $this->assertSame('#111111', $draft['colors.primary']);
        $this->assertSame('jakarta', $draft['fonts.pair']);
    }

    public function test_the_customizer_page_initialises_once_and_never_drops_a_save(): void
    {
        $view = (string) file_get_contents(resource_path('views/owner/design/customizer.blade.php'));

        $this->assertStringNotContainsString('x-init="init()"', $view, 'Alpine already calls init(); a second call races load()');
        $this->assertStringContainsString('x-bind:disabled="!dirty" @click="saveDraft()"', $view, 'a click while saving queues, not swallowed');
        $this->assertStringContainsString('this.saveAgain = true', $view);
        $this->assertStringContainsString('r.status === 429 || r.status >= 500', $view, 'throttled / failed saves are retried');
        $this->assertStringContainsString("___('Could not save. Please try again.')", $view, 'and otherwise reported');
    }

    /** Alpine 3 runs a component's init() itself: an x-init that calls it again runs it twice. */
    public function test_no_view_calls_an_alpine_init_a_second_time(): void
    {
        $offenders = [];
        foreach (Finder::create()->files()->in(ViewRoots::all())->name('*.blade.php') as $file) { // + ee module views (P1-13)
            if (preg_match('/x-init="\s*init\s*\(/', $file->getContents())) {
                $offenders[] = ViewRoots::relative($file->getPathname());
            }
        }
        $this->assertSame([], $offenders);
    }

    private function draft(User $owner, Store $store, array $values): void
    {
        $this->actingAs($owner)->postJson(route('theme.customizer.draft', $store->id), ['values' => $values])->assertOk();
    }

    /** @return array{0: User, 1: Store} */
    private function store(): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999], 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', 'crave');

        return [$owner, $store];
    }
}
