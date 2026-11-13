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
use Juwri\Modules\Storefront\Services\Theme\SchemaV2;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Visual Card Picker ("CC" run) — lane B.
 *
 * The customizer's "Stories entry style" select becomes a card grid. The grid
 * is a GENERIC capability: any schema `select` field carrying `option_previews`
 * renders as cards; a field without them keeps the plain select. This suite
 * covers the wiring layer:
 *
 *  B1 — schema pass-through: `option_previews` / `option_sublabels` survive
 *       SchemaV2::normalizeField() (whitelisted keys are copied, everything
 *       else is silently stripped) and localizeSchema() translates sublabels
 *       while leaving preview PATHS untouched;
 *  B2 — both standalone themes declare the §4 previews map on `stories.entry`
 *       and the schema endpoint (the page payload) carries it to the editor;
 *  B3 — the customizer page ships the card-select partial + the plain-select
 *       guard, and the server fills the inherit tile's "Currently: …" sublabel;
 *  B4 — /admin/menu-templates renders the same cards (11 radios, no inherit)
 *       and the save round-trip (Option + activity log + slug validation) is
 *       unchanged.
 *
 * Publish-parity (SPEC §7): the card picker writes the SAME `stories.entry`
 * key through the SAME draft→publish path the select used — proven by the
 * round-trip test, not assumed.
 */
class CustomizerCardSelectTest extends TestCase
{
    use RefreshDatabase;

    /** The 11 concrete entry slugs (no inherit) — the §1 contract. */
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
        // Schemas are memoized per template for the life of the process; a
        // theme.json read by an earlier test must not hand this one a stale copy.
        ThemeCustomizerService::flushSchemaMemo();

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    /** @return array{0: User, 1: Store} */
    private function storeFixture(string $template = 'folio'): array
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

    /** A minimal raw schema carrying a card-capable select, for unit-level pass-through proofs. */
    private function rawCardSchema(): array
    {
        return [
            'settings' => [[
                'group' => 'Cards',
                'fields' => [[
                    'id' => 'demo.pick',
                    'type' => 'select',
                    'label' => 'Pick a look',
                    'default' => 'a',
                    'options' => ['a', 'b'],
                    'option_labels' => ['a' => 'Look A', 'b' => 'Look B'],
                    'option_previews' => [
                        'a' => 'global/stories-entries/previews/ribbon.svg',
                        'b' => 'global/stories-entries/previews/orb.svg',
                    ],
                    'option_sublabels' => ['a' => 'Card sublabel probe'],
                    'option_junk' => ['a' => 'must be stripped'],
                ]],
            ]],
        ];
    }

    // ── B1: schema pass-through ────────────────────────────────────────────────

    public function test_b1_normalize_passes_previews_and_sublabels_through_the_whitelist(): void
    {
        $schema = SchemaV2::normalize($this->rawCardSchema());
        $field = ThemeCustomizerService::fields($schema)['demo.pick'];

        $this->assertSame([
            'a' => 'global/stories-entries/previews/ribbon.svg',
            'b' => 'global/stories-entries/previews/orb.svg',
        ], $field['option_previews']);
        $this->assertSame(['a' => 'Card sublabel probe'], $field['option_sublabels']);

        // The whitelist is real: a key not on it is silently stripped.
        $this->assertArrayNotHasKey('option_junk', $field);
    }

    public function test_b1_localize_translates_sublabels_but_never_preview_paths(): void
    {
        // ___() slugs the source string and looks up lang.<slug>. Register a
        // translation for the SUBLABEL and for the PREVIEW PATH's slug: the
        // sublabel must come back translated, the path byte-identical (a path
        // routed through the translator would be corrupted by this line).
        app('translator')->addLines([
            'lang.'.Str::slug('Card sublabel probe', '_') => 'Sublabel translated',
            'lang.'.Str::slug('global/stories-entries/previews/ribbon.svg', '_') => 'PATH MUST NOT TRANSLATE',
        ], 'en');

        $schema = ThemeCustomizerService::localizeSchema(SchemaV2::normalize($this->rawCardSchema()));
        $field = ThemeCustomizerService::fields($schema)['demo.pick'];

        $this->assertSame('Sublabel translated', $field['option_sublabels']['a']);
        $this->assertSame('global/stories-entries/previews/ribbon.svg', $field['option_previews']['a']);
        $this->assertSame('global/stories-entries/previews/orb.svg', $field['option_previews']['b']);
    }

    public function test_b1_card_value_rides_the_same_draft_publish_path_as_the_select(): void
    {
        [$owner, $store] = $this->storeFixture('folio');

        // The card radio writes `stories.entry` through set() — the identical
        // key/endpoint the plain select used. Draft → publish → readable.
        $this->actingAs($owner)->postJson(route('theme.customizer.draft', $store->id), [
            'values' => ['stories.entry' => 'medallion'],
        ])->assertOk();
        $this->actingAs($owner)->postJson(route('theme.customizer.publish', $store->id))->assertOk();

        $this->assertSame('medallion', theme_setting($store->id, 'stories.entry'));

        // Previews are display metadata only — validate() still fences values
        // to `options`, so a slug outside the set is rejected unchanged.
        $this->actingAs($owner)->postJson(route('theme.customizer.draft', $store->id), [
            'values' => ['stories.entry' => 'sparkle-bomb'],
        ])->assertStatus(422);
    }

    // ── B2: both standalone themes declare the §4 previews map ────────────────

    public static function standaloneThemeProvider(): array
    {
        return [
            'folio' => ['folio'],
            'pageview' => ['pageview'],
        ];
    }

    #[DataProvider('standaloneThemeProvider')]
    public function test_b2_theme_json_declares_a_preview_per_entry_option(string $template): void
    {
        $field = ThemeCustomizerService::fields(ThemeCustomizerService::schemaFor($template))['stories.entry'] ?? null;

        $this->assertNotNull($field, "[{$template}] must declare stories.entry");
        $this->assertIsArray($field['option_previews'] ?? null, "[{$template}] stories.entry must carry option_previews");

        // Exactly one preview per option — same slugs, same order, asset-relative
        // paths into the §1 tile directory.
        $this->assertSame($field['options'], array_keys($field['option_previews']));
        foreach ($field['option_previews'] as $slug => $path) {
            $this->assertSame(
                'global/stories-entries/previews/'.$slug.'.svg',
                $path,
                "[{$template}] preview path for {$slug}"
            );
        }
    }

    public function test_b2_schema_endpoint_carries_previews_to_the_editor(): void
    {
        [$owner, $store] = $this->storeFixture('folio');

        $response = $this->actingAs($owner)->getJson(route('theme.customizer.schema', $store->id))->assertOk();

        $field = null;
        foreach ($response->json('schema.settings') as $group) {
            foreach ($group['fields'] ?? [] as $candidate) {
                if (($candidate['id'] ?? null) === 'stories.entry') {
                    $field = $candidate;
                }
            }
        }

        $this->assertNotNull($field, 'the page payload must include stories.entry');
        $this->assertSame('global/stories-entries/previews/ribbon.svg', $field['option_previews']['ribbon'] ?? null);
        $this->assertCount(12, $field['option_previews']);
    }

    // ── B3: customizer page wiring + inherit sublabel ─────────────────────────

    /** The stories.entry field as the schema ENDPOINT (page payload) returns it. */
    private function schemaEndpointStoriesEntry(User $owner, Store $store): ?array
    {
        $response = $this->actingAs($owner)->getJson(route('theme.customizer.schema', $store->id))->assertOk();
        foreach ($response->json('schema.settings') as $group) {
            foreach ($group['fields'] ?? [] as $candidate) {
                if (($candidate['id'] ?? null) === 'stories.entry') {
                    return $candidate;
                }
            }
        }

        return null;
    }

    public function test_b3_page_guards_the_plain_select_and_mounts_the_card_partial(): void
    {
        $this->shareLayoutGlobals();
        [$owner, $store] = $this->storeFixture('folio');

        $response = $this->actingAs($owner)
            ->get(route('theme.customizer.page', ['store' => $store->id]))->assertOk()->assertSeeHtml("f.type==='select' && !f.option_previews");

        // The card partial is lane A's file (cross-lane contract). When it is
        // present the page must ship the radio-card grid template; while absent
        // (parallel worktrees) @includeIf keeps the page rendering and this
        // half of the assertion is VERIFY-AFTER-MERGE — it arms itself once the
        // partial lands.
        if (view()->exists('owner.design.partials.tc-card-select')) {
            $response->assertSeeHtml('radiogroup')->assertSeeHtml('f.option_previews');
        }
    }

    public function test_b3_inherit_sublabel_names_the_live_platform_default(): void
    {
        [$owner, $store] = $this->storeFixture('folio');

        // No Option row → the §2 code fallback 'ribbon', humanised via the
        // field's own option_labels (both halves through ___(), locale-proof).
        $field = $this->schemaEndpointStoriesEntry($owner, $store);
        $this->assertSame(
            ___('Currently').': '.___('Gilded bookmark ribbon'),
            $field['option_sublabels']['inherit'] ?? null
        );

        // Once the super admin picks a default, the sublabel follows it live.
        PlatformSettings::set('stories_entry_default', 'seal');
        $field = $this->schemaEndpointStoriesEntry($owner, $store);
        $this->assertSame(
            ___('Currently').': '.___('Wax seal'),
            $field['option_sublabels']['inherit'] ?? null
        );
    }

    public function test_b3_a_preview_less_select_stays_a_plain_select(): void
    {
        // Generic contract: aurora's fonts.body select declares no previews, so
        // the payload carries none and the blade guard keeps the plain select.
        [$owner, $store] = $this->storeFixture('aurora');

        $response = $this->actingAs($owner)->getJson(route('theme.customizer.schema', $store->id))->assertOk();
        $fontsBody = null;
        foreach ($response->json('schema.settings') as $group) {
            foreach ($group['fields'] ?? [] as $candidate) {
                if (($candidate['id'] ?? null) === 'fonts.body') {
                    $fontsBody = $candidate;
                }
            }
        }

        $this->assertNotNull($fontsBody, 'aurora must still declare fonts.body');
        $this->assertSame('select', $fontsBody['type']);
        $this->assertArrayNotHasKey('option_previews', $fontsBody);
        $this->assertArrayNotHasKey('option_sublabels', $fontsBody);
    }

    // ── B4: /admin/menu-templates gets the same cards ─────────────────────────

    private function makeAdmin(): User
    {
        return User::factory()->create([
            'group_id' => '1', 'status' => 1, 'email_verified_at' => now(), 'user_type' => 'admin',
        ]);
    }

    private function adminLayoutGlobals(): void
    {
        // The admin chrome reads a handful of $settings props beyond the shared
        // defaults; seed them so the page renders (the sanctioned
        // shareLayoutGlobals path — no hand-rolled settings double).
        $this->shareLayoutGlobals([
            'site_admin_logo' => 'admin-logo.png', 'site_logo' => 'logo.png', 'site_logo_footer' => 'logo.png',
            'site_currency' => 'USD', 'currency_sign' => '$', 'invoice_admin_tax_type' => 'exclusive',
            'blog_enable' => 0, 'enable_contact_page' => 0, 'enable_faqs' => 0,
            'facebook_login' => 0, 'google_login' => 0, 'company_bank_info' => '',
        ], admin: true);
    }

    public function test_b4_admin_page_renders_eleven_card_radios_and_no_inherit(): void
    {
        $this->adminLayoutGlobals();
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->get(route('admin.menu-templates.index'))->assertOk();
        $html = $response->getContent();

        // 11 native radios inside tcc-card labels, sharing the tc-cards.css
        // grid (tcc-grid) with the customizer picker; each tile carries its
        // per-concept tcc-anim--<slug> hover class, so the card class is a
        // prefix, not the whole attribute.
        $this->assertSame(11, substr_count($html, 'name="stories_entry_default"'));
        $this->assertStringContainsString('class="tcc-card tcc-anim--', $html);
        $this->assertStringContainsString('class="tcc-grid', $html);
        $this->assertStringContainsString('role="radiogroup"', $html);
        $this->assertStringContainsString('global/stories-entries/previews/tc-cards.css', $html);
        $this->assertStringContainsString('global/stories-entries/previews/medallion.svg', $html);

        // 'inherit' is a store-level value — never an admin tile.
        $this->assertStringNotContainsString('value="inherit"', $html);

        // Unset → the code fallback tile is the checked one.
        $this->assertStringContainsString('value="ribbon" checked', $html);
        $this->assertStringNotContainsString('value="seal" checked', $html);

        // Once set, the saved slug's tile is checked instead.
        PlatformSettings::set('stories_entry_default', 'seal');
        $html = $this->actingAs($admin)->get(route('admin.menu-templates.index'))->assertOk()->getContent();
        $this->assertStringContainsString('value="seal" checked', $html);
        $this->assertStringNotContainsString('value="ribbon" checked', $html);
    }

    public function test_b4_card_save_round_trips_option_activity_and_validation(): void
    {
        $admin = $this->makeAdmin();

        // A card radio posts the same field through the same route — Option +
        // activity land exactly as the old select's save did.
        $this->actingAs($admin)
            ->from(route('admin.menu-templates.index'))
            ->post(route('admin.menuTemplates.storiesEntryDefault'), ['stories_entry_default' => 'filmstrip'])
            ->assertRedirect(route('admin.menu-templates.index'));

        $this->assertSame('filmstrip', PlatformSettings::get('stories_entry_default', false));
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'admin.menu_template.stories_entry_default',
            'actor_user_id' => $admin->id,
        ]);

        // Validation is untouched: a slug outside the 11 (inherit included) is
        // refused and the stored value stands.
        $this->actingAs($admin)
            ->from(route('admin.menu-templates.index'))
            ->post(route('admin.menuTemplates.storiesEntryDefault'), ['stories_entry_default' => 'inherit'])
            ->assertRedirect(route('admin.menu-templates.index'))
            ->assertSessionHas('app_alert_type', 'error');
        $this->assertSame('filmstrip', PlatformSettings::get('stories_entry_default', false));
    }
}
