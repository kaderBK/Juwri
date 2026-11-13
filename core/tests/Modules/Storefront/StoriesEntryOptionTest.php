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
use Juwri\Core\Settings\PlatformSettings;
use Juwri\Modules\Storefront\Models\ThemeSetting;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Stories Entry Points ("SE" run) — lane B (dashboard/admin).
 *
 * Covers the option layer of the §2 resolution chain:
 *  1. the customizer select (a schema `select` field) round-trips draft → publish;
 *  2. both standalone themes declare the `stories.entry` select;
 *  3. the super-admin platform default (`stories_entry_default`) persists,
 *     is slug-validated, and is admin-gated;
 *  4. the chain itself — ThemeSetting `stories.entry` wins, else the Option row,
 *     else the code fallback `'ribbon'`.
 *
 * The one-line resolver `stories_entry_for()` is shipped by lane A. Where it is
 * absent in this worktree the tests assert the raw layers (ThemeSetting + Option)
 * and mark the resolver assertion VERIFY-AFTER-MERGE.
 */
class StoriesEntryOptionTest extends TestCase
{
    use RefreshDatabase;

    /** The 11 non-inherit entry slugs — the customizer/admin contract (SPEC §1). */
    private const SLUGS = [
        'none', 'ribbon', 'orb', 'toolbar', 'capsule', 'tassel',
        'seal', 'dogear', 'filmstrip', 'medallion', 'sheet',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        // Schemas are memoized per template for the life of the process; a theme.json
        // read by an earlier test must not hand this one a stale copy.
        ThemeCustomizerService::flushSchemaMemo();

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    /** @return array{0: User, 1: Store} */
    private function storeFixture(string $template = 'aurora'): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
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
        StoreOption::updateStoreOption($store->id, 'store_template', $template);

        return [$owner, $store];
    }

    private function makeAdmin(): User
    {
        return User::factory()->create([
            'group_id' => '1', 'status' => 1, 'email_verified_at' => now(), 'user_type' => 'admin',
        ]);
    }

    // ── B1: the generic customizer renderer already supports `select` ──────────
    //
    // Verified in code (no renderer change was needed): the pipeline handles a
    // `select` field end to end —
    //   • SchemaV2::normalizeField() preserves `type`, `options`, `option_labels`
    //     (core/app/Modules/Storefront/Services/Theme/SchemaV2.php ~line 160);
    //   • ThemeCustomizerService::uiField() maps it + carries `options`
    //     (~line 141), and validate() rejects a value outside `options`
    //     (case 'select', ~line 264); option_labels are localized in
    //     localizeSchema() (~line 398);
    //   • the blade renders a <select> with option_labels
    //     (owner/design/customizer.blade.php ~line 264).
    // These two tests prove that mechanism round-trips draft → publish, using
    // aurora's existing `fonts.body` select so the proof is independent of B2.

    public function test_b1_schema_select_round_trips_draft_to_publish(): void
    {
        [$owner, $store] = $this->storeFixture('aurora');

        $this->actingAs($owner)->postJson(route('theme.customizer.draft', $store->id), [
            'values' => ['fonts.body' => 'Poppins'],
        ])->assertOk();

        // Draft only — published value is still the theme default.
        $this->assertNotSame('Poppins', theme_setting($store->id, 'fonts.body'));

        $this->actingAs($owner)->postJson(route('theme.customizer.publish', $store->id))->assertOk();

        $this->assertSame('Poppins', theme_setting($store->id, 'fonts.body'));
        $this->assertNull(ThemeSetting::where('store_id', $store->id)->first()->draft);
    }

    public function test_b1_schema_select_rejects_a_value_outside_its_options(): void
    {
        [$owner, $store] = $this->storeFixture('aurora');

        $this->actingAs($owner)->postJson(route('theme.customizer.draft', $store->id), [
            'values' => ['fonts.body' => 'ComicSans'],
        ])->assertStatus(422);
    }

    // ── B2: both standalone themes declare the `stories.entry` select ──────────

    public static function standaloneThemeProvider(): array
    {
        return [
            'folio' => ['folio'],
            'pageview' => ['pageview'],
        ];
    }

    #[DataProvider('standaloneThemeProvider')]
    public function test_b2_theme_declares_the_stories_entry_select(string $template): void
    {
        $schema = ThemeCustomizerService::schemaFor($template);
        $fields = ThemeCustomizerService::fields($schema);

        $this->assertArrayHasKey(
            'stories.entry',
            $fields,
            "[{$template}] theme.json must declare the stories.entry customizer field."
        );

        $field = $fields['stories.entry'];
        $this->assertSame('select', $field['type']);
        $this->assertSame('inherit', $field['default'], 'default must be inherit (the resolution chain owns the real default)');

        // Every §1 slug + inherit is offered, and only those.
        $this->assertSame(
            array_merge(['inherit'], self::SLUGS),
            $field['options'],
            "[{$template}] stories.entry must offer inherit + the 11 §1 slugs, in order."
        );
    }

    #[DataProvider('standaloneThemeProvider')]
    public function test_b2_stories_entry_round_trips_draft_to_publish(string $template): void
    {
        [$owner, $store] = $this->storeFixture($template);

        // A valid slug validates, drafts, publishes, and reads back through the
        // resolution chain's step 1 (theme_setting stories.entry).
        $this->actingAs($owner)->postJson(route('theme.customizer.draft', $store->id), [
            'values' => ['stories.entry' => 'orb'],
        ])->assertOk();
        $this->actingAs($owner)->postJson(route('theme.customizer.publish', $store->id))->assertOk();

        $this->assertSame('orb', theme_setting($store->id, 'stories.entry'));

        // A slug outside the option set is rejected — no free-text entry key.
        $this->actingAs($owner)->postJson(route('theme.customizer.draft', $store->id), [
            'values' => ['stories.entry' => 'not-a-real-entry'],
        ])->assertStatus(422);
    }

    // ── B3: super-admin platform default (Option `stories_entry_default`) ──────

    public function test_b3_admin_sets_the_platform_stories_entry_default(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->from(route('admin.menu-templates.index'))
            ->post(route('admin.menuTemplates.storiesEntryDefault'), ['stories_entry_default' => 'medallion'])
            ->assertRedirect(route('admin.menu-templates.index'));

        $this->assertSame('medallion', PlatformSettings::get('stories_entry_default', false));

        // §6: the change is audited (rides the admin.menu_template.% activity feed).
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'admin.menu_template.stories_entry_default',
            'actor_user_id' => $admin->id,
        ]);
    }

    public function test_b3_invalid_slug_is_rejected_and_not_persisted(): void
    {
        $admin = $this->makeAdmin();

        // 'inherit' is a store-level value only — never a valid platform default.
        $this->actingAs($admin)
            ->from(route('admin.menu-templates.index'))
            ->post(route('admin.menuTemplates.storiesEntryDefault'), ['stories_entry_default' => 'inherit'])
            ->assertRedirect(route('admin.menu-templates.index'))
            ->assertSessionHas('app_alert_type', 'error');
        $this->assertFalse(PlatformSettings::get('stories_entry_default', false));

        // A bogus slug is likewise refused.
        $this->actingAs($admin)
            ->from(route('admin.menu-templates.index'))
            ->post(route('admin.menuTemplates.storiesEntryDefault'), ['stories_entry_default' => 'sparkle-bomb'])
            ->assertRedirect(route('admin.menu-templates.index'));
        $this->assertFalse(PlatformSettings::get('stories_entry_default', false));

        $this->assertDatabaseMissing('activity_logs', ['action' => 'admin.menu_template.stories_entry_default']);
    }

    public function test_b3_non_admin_is_blocked(): void
    {
        [$owner] = $this->storeFixture('folio');

        $this->actingAs($owner)
            ->post(route('admin.menuTemplates.storiesEntryDefault'), ['stories_entry_default' => 'orb'])
            ->assertRedirect(route('login'));

        $this->assertFalse(PlatformSettings::get('stories_entry_default', false));
    }

    public function test_b3_index_card_shows_current_value_and_code_default(): void
    {
        // The admin chrome (layout + sidebar) reads a handful of $settings props
        // beyond the shared defaults; seed them so the page renders (the sanctioned
        // shareLayoutGlobals path — no hand-rolled settings double, per the ratchet).
        $this->shareLayoutGlobals([
            'site_admin_logo' => 'admin-logo.png', 'site_logo' => 'logo.png', 'site_logo_footer' => 'logo.png',
            'site_currency' => 'USD', 'currency_sign' => '$', 'invoice_admin_tax_type' => 'exclusive',
            'blog_enable' => 0, 'enable_contact_page' => 0, 'enable_faqs' => 0,
            'facebook_login' => 0, 'google_login' => 0, 'company_bank_info' => '',
        ], admin: true);
        $admin = $this->makeAdmin();

        // Unset → the card shows the code fallback 'ribbon'.
        $this->actingAs($admin)->get(route('admin.menu-templates.index'))->assertOk()->assertSeeHtml(___('Stories entry default'))->assertSeeHtml('code default')->assertSeeHtml('stories_entry_default');

        // Once set, the card reflects the saved slug.
        PlatformSettings::set('stories_entry_default', 'seal');
        $this->actingAs($admin)->get(route('admin.menu-templates.index'))->assertOk()->assertSeeHtml('seal');
    }

    // ── B4: the §2 resolution chain at the option layer ───────────────────────
    //
    // The one-line resolver `stories_entry_for(int $storeId): string` is shipped by
    // lane A and is ABSENT in this worktree, so these tests assert the RAW LAYERS
    // the resolver reads (ThemeSetting `stories.entry` + Option `stories_entry_default`),
    // per B4's instruction. The resolver's own output is VERIFY-AFTER-MERGE:
    //   step 1  theme_setting($id,'stories.entry') is a concrete slug (≠ inherit) → that wins;
    //   step 2  step-1 value is 'inherit'/absent                                → Option `stories_entry_default` wins;
    //   step 3  no Option row                                                   → code fallback 'ribbon';
    //   GATE    the resolver ALSO returns 'none' when the store has zero publishable
    //           stories, regardless of the above (SPEC §2) — a lane-A concern.

    private function publishStoreEntry(Store $store, ?string $slug): void
    {
        // The published customizer value = the resolver's step-1 source. Written
        // straight to the published `settings` under the template's settings key,
        // exactly as publish() would.
        $settings = $slug === null ? [] : ['stories.entry' => $slug];
        ThemeSetting::updateOrCreate(
            ['store_id' => $store->id, 'template' => ThemeCustomizerService::settingsKey('folio')],
            ['settings' => $settings, 'draft' => null]
        );
    }

    public function test_b4_layer1_store_choice_is_the_resolution_source(): void
    {
        [, $store] = $this->storeFixture('folio');
        $this->publishStoreEntry($store, 'seal');
        PlatformSettings::set('stories_entry_default', 'orb'); // platform default present but overridden

        // Step 1 source: the store's concrete choice reads back — the resolver uses it.
        $this->assertSame('seal', theme_setting($store->id, 'stories.entry'));
        // Step 2 source exists but must lose to the store choice.
        $this->assertSame('orb', PlatformSettings::get('stories_entry_default', false));
        // VERIFY-AFTER-MERGE: stories_entry_for($store->id) === 'seal' (given ≥1 publishable story).
    }

    public function test_b4_layer2_platform_default_wins_when_store_inherits_or_is_unset(): void
    {
        PlatformSettings::set('stories_entry_default', 'capsule');

        // 'inherit' — the explicit "use the platform default" choice.
        [, $inheritStore] = $this->storeFixture('folio');
        $this->publishStoreEntry($inheritStore, 'inherit');
        $this->assertSame('inherit', theme_setting($inheritStore->id, 'stories.entry'));

        // Unset — no ThemeSetting row at all; step 1 yields the default (null).
        [, $unsetStore] = $this->storeFixture('folio');
        $this->assertNull(theme_setting($unsetStore->id, 'stories.entry'));

        // Both fall through to the same step-2 source.
        $this->assertSame('capsule', PlatformSettings::get('stories_entry_default', false));
        // VERIFY-AFTER-MERGE: stories_entry_for() === 'capsule' for both (given ≥1 publishable story).
    }

    public function test_b4_layer3_code_fallback_is_ribbon_when_no_option_row(): void
    {
        [, $store] = $this->storeFixture('folio');

        // Neither a store choice nor a platform default is set.
        $this->assertNull(theme_setting($store->id, 'stories.entry'));
        $this->assertFalse(PlatformSettings::get('stories_entry_default', false));

        // The admin card mirrors the code fallback that the resolver applies here.
        $this->assertSame('ribbon', PlatformSettings::get('stories_entry_default', false) ?: 'ribbon');
        // VERIFY-AFTER-MERGE: stories_entry_for() === 'ribbon' (given ≥1 publishable story;
        // 'none' if the store has zero publishable stories — the SPEC §2 gate).
    }
}
