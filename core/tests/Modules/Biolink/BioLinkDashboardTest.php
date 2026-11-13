<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Biolink\Http\Controllers\User\BioLinkController;
use Juwri\Modules\Biolink\Services\BioLinkService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Biolink Pages — Lane B (dashboards & roles) HTTP tests.
 *
 * The engine is Lane A / F0's job. Here we bind a fixture STUB of {@see BioLinkService}
 * (a standalone anonymous class — the real service does not exist in Lane B's tree)
 * so the pages/controller/route/nav/permission wiring is proven against the locked §3
 * contract, independent of the engine. The controller resolves the service by string
 * (`app(BioLinkService::class)`) and never type-hints it, so PHP never needs the real
 * class to exist and the container returns our stub.
 *
 * EnsureStaffDashboardPermission is deliberately kept ON — it is the staff gate under test.
 */
class BioLinkDashboardTest extends TestCase
{
    use RefreshDatabase;

    /** @var object the bound service stub (records writes; returns read fixtures) */
    private $svc;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        // Feature ships DARK (registry default OFF). Simulate the superadmin kill-switch being ON
        // so the `feature:biolink` dashboard gate is reachable — sibling idiom (AccountingDashboardTest).
        config(['settings.admin_allow_biolink' => true]);
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $this->shareLayoutGlobals(['currency_sign' => '$', 'currency_pos' => 1]);

        // NOTE: EnsureStaffDashboardPermission is NOT disabled — it is the gate under test.
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);

        $this->svc = $this->makeStub();
        app()->instance(BioLinkService::class, $this->svc);
    }

    /** A standalone fixture stub — records writes, returns read fixtures. */
    private function makeStub(): object
    {
        return new class
        {
            public array $saved = [];

            public array $created = [];

            public array $updated = [];

            public array $toggled = [];

            public array $deleted = [];

            public array $reordered = [];

            public array $blocks = [];

            public array $settings = ['enabled' => true, 'preset' => 'classic_flat', 'mode' => 'menu_first', 'sections' => ['hours' => true], 'branch_pages' => false];

            public array $presetList = [
                ['slug' => 'classic_flat', 'name' => 'Classic Flat', 'family' => 'list', 'tier' => 'basic'],
                ['slug' => 'neon_arcade', 'name' => 'Neon Arcade', 'family' => 'list', 'tier' => 'pro'],
            ];

            public array $locales = [];

            public array $stats = [
                'views' => 120, 'clicks' => 30, 'ctr' => 0.25, 'qr_scans' => 8,
                'per_block' => [['label' => 'Order online', 'clicks' => 18], ['label' => 'Instagram', 'clicks' => 12]],
                'sources' => ['qr' => 40, 'social' => 50, 'direct' => 30],
                'devices' => ['mobile' => 90, 'desktop' => 30],
            ];

            public function resolveSettings(int $storeId): array
            {
                return $this->settings;
            }

            public function blocksFor(int $storeId, ?int $branchId = null, bool $includeHidden = false): array
            {
                return $this->blocks;
            }

            public function editorBlocks(int $storeId, ?int $branchId = null): array
            {
                return $this->blocks;
            }

            public function presetAllowed(int $storeId, string $preset): bool
            {
                return $preset === 'classic_flat';
            }

            public array $statsRanges = [];

            public function statsFor(int $storeId, string $range): array
            {
                $this->statsRanges[] = $range;

                return $this->stats;
            }

            public function presets(): array
            {
                return $this->presetList;
            }

            public function enabledLocales(int $storeId): array
            {
                return $this->locales;
            }

            public function saveSettings(int $storeId, array $settings): void
            {
                $this->saved[] = compact('storeId', 'settings');
            }

            public function createBlock(int $storeId, ?int $branchId, array $data): array
            {
                $this->created[] = compact('storeId', 'branchId', 'data');

                return ['id' => 999] + $data;
            }

            public function updateBlock(int $storeId, int $blockId, array $data): void
            {
                $this->updated[] = compact('storeId', 'blockId', 'data');
            }

            public function toggleBlock(int $storeId, int $blockId): void
            {
                $this->toggled[] = $blockId;
            }

            public function deleteBlock(int $storeId, int $blockId): void
            {
                $this->deleted[] = $blockId;
            }

            public function reorderBlocks(int $storeId, array $orderedIds): void
            {
                $this->reordered[] = $orderedIds;
            }
        };
    }

    /** @return array{0: User, 1: Store} */
    private function store(): array
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now(), 'user_type' => 'user']);
        $title = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1',
        ]);

        return [$owner, $store];
    }

    private function staff(Store $store, string $role = 'manager', array $permissions = []): StaffMember
    {
        return StaffMember::create([
            'owner_id' => $store->user_id, 'store_id' => $store->id,
            'name' => 'S '.Str::random(4), 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => $role, 'active' => 1,
        ] + ($permissions ? ['permissions' => $permissions] : []));
    }

    /**
     * A staff dashboard session: the OWNER is authenticated on the web guard
     * (so `auth()->user()` is the owner User, as in production) and the staff
     * identity is carried purely by `staff_session_id` — which is what
     * `staff_session()` / `is_staff_dashboard()` read. (Calling
     * `actingAs($staff,'staff')` would flip the DEFAULT guard to staff and make
     * the shared layout resolve the staff as the owner — a test artifact only.)
     */
    private function asStaff(User $owner, StaffMember $staff): self
    {
        return $this->actingAs($owner, 'web')->withSession(['staff_session_id' => $staff->id]);
    }

    /** @return array{0: User, 1: Store} owner on a plan whose settings carry $settings */
    private function storeOnPlan(array $settings): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10,
            'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => $settings + ['restaurant_limit' => 5, 'menu_limit' => 999], 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(), 'user_type' => 'user']);
        $title = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1',
        ]);

        return [$owner, $store];
    }

    /* ─────────────────────────── B1 · foundation ──────────────────────────── */

    public function test_owner_reaches_settings_builder_and_insights(): void
    {
        [$owner, $store] = $this->store();

        $this->actingAs($owner)->get(route('biolink.index'))->assertStatus(200)->assertSee(___('Bio page'));
        $this->actingAs($owner)->get(route('biolink.builder'))->assertStatus(200)->assertSee(___('Add a block'));
        $this->actingAs($owner)->get(route('biolink.insights'))->assertStatus(200)->assertSee(___('Click-through rate'));
    }

    public function test_nav_shows_bio_page_link_for_owner(): void
    {
        [$owner, $store] = $this->store();

        // The sidebar nav renders the gated Bio-page link pointing at the settings route.
        $this->actingAs($owner)->get(route('biolink.index'))->assertStatus(200)->assertSeeHtml('href="'.route('biolink.index').'"');
    }

    public function test_customer_guest_cannot_reach_the_dashboard(): void
    {
        // No auth → the `auth` middleware bounces to login (never renders the dashboard).
        $this->get(route('biolink.index'))->assertRedirect(route('login'));
    }

    /* ─────────────────────────── B2 · settings ────────────────────────────── */

    public function test_settings_page_renders_picker_toggles_and_modes(): void
    {
        [$owner, $store] = $this->store();

        $res = $this->actingAs($owner)->get(route('biolink.index'))->assertStatus(200);

        // Preset picker shows both an allowed (basic) and a tier-locked (pro) preset.
        $res->assertSee('Classic Flat');
        $res->assertSee('Neon Arcade');
        $res->assertSee(___('Pro'));                 // lock badge on the pro preset

        // Enable switch + section toggles + all three routing modes.
        $res->assertSee(___('Bio page is live'));
        $res->assertSeeHtml('id="section-hours"');
        $res->assertSeeHtml('id="mode-menu_first"');
        $res->assertSeeHtml('id="mode-bio_first"');
        $res->assertSeeHtml('id="mode-bio_only"');
        // Public link + copy control.
        $res->assertSee($store->slug.'/bio');
        $res->assertSee(___('Copy'));
    }

    public function test_bio_only_radio_is_disabled_without_entitlement(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        [$owner, $store] = $this->store();

        // A plain owner has no allow_onlybio entitlement → the radio is disabled + upgrade hint.
        $this->actingAs($owner)->get(route('biolink.index'))
            ->assertSee(___('“Bio only” mode is available on higher plans.'));
    }

    public function test_save_settings_persists_through_the_service(): void
    {
        [$owner, $store] = $this->store();

        $this->actingAs($owner)->post(route('biolink.settings.save'), [
            'enabled' => '1',
            'preset' => 'classic_flat',
            'mode' => 'bio_first',
            'branch_pages' => '1',
            'sections' => ['hours' => '1', 'socials' => '0'],
        ])->assertRedirect();

        $this->assertCount(1, $this->svc->saved);
        $saved = $this->svc->saved[0]['settings'];
        $this->assertTrue($saved['enabled']);
        $this->assertSame('classic_flat', $saved['preset']);
        $this->assertSame('bio_first', $saved['mode']);
        $this->assertTrue($saved['branch_pages']);
        $this->assertTrue($saved['sections']['hours']);
        $this->assertFalse($saved['sections']['socials']);
    }

    public function test_bio_only_mode_is_coerced_down_when_plan_disallows(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        [$owner, $store] = $this->store();

        $this->actingAs($owner)->post(route('biolink.settings.save'), [
            'enabled' => '1', 'preset' => 'classic_flat', 'mode' => 'bio_only',
        ])->assertRedirect();

        // No allow_onlybio entitlement → forged/stale bio_only becomes bio_first.
        $this->assertSame('bio_first', $this->svc->saved[0]['settings']['mode']);
    }

    /* ─────────────────────────── B3 · builder ─────────────────────────────── */

    public function test_builder_add_tray_lists_all_14_types(): void
    {
        [$owner, $store] = $this->store();

        $res = $this->actingAs($owner)->get(route('biolink.builder'))->assertStatus(200);
        foreach (BioLinkController::BLOCK_TYPES as $type) {
            $res->assertSeeHtml('data-add-block="'.$type.'"');
        }
    }

    public function test_builder_renders_existing_blocks_with_clicks_and_schedule(): void
    {
        [$owner, $store] = $this->store();
        $this->svc->blocks = [
            ['id' => 1, 'type' => 'link', 'label' => 'Order online', 'url' => 'https://x', 'config' => [], 'active' => true, 'scheduled' => true, 'clicks' => 42],
            ['id' => 2, 'type' => 'whatsapp', 'label' => 'Chat', 'url' => null, 'config' => [], 'active' => false, 'scheduled' => false, 'clicks' => 7],
        ];

        $this->actingAs($owner)->get(route('biolink.builder'))
            ->assertStatus(200)
            ->assertSee('Order online')
            ->assertSee('42')                         // inline click count
            ->assertSee(___('Scheduled'))             // schedule badge
            ->assertSee(___('Hidden'));               // inactive badge
    }

    public function test_store_block_creates_through_service(): void
    {
        [$owner, $store] = $this->store();

        $this->actingAs($owner)->postJson(route('biolink.blocks.store'), [
            'type' => 'link', 'label' => 'My site', 'url' => 'https://example.test', 'active' => '1',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertCount(1, $this->svc->created);
        $data = $this->svc->created[0]['data'];
        $this->assertSame('link', $data['type']);
        $this->assertSame('My site', $data['label']);
        $this->assertSame('https://example.test', $data['url']);
        $this->assertTrue($data['active']);
    }

    public function test_store_block_captures_typed_config_fields(): void
    {
        [$owner, $store] = $this->store();

        $this->actingAs($owner)->postJson(route('biolink.blocks.store'), [
            'type' => 'menu_item', 'label' => 'Special',
            'config' => ['item_id' => '5', 'price_label' => '$9.00'],
        ])->assertOk();

        $config = $this->svc->created[0]['data']['config'];
        $this->assertSame('5', $config['item_id']);
        $this->assertSame('$9.00', $config['price_label']);
    }

    public function test_delivery_apps_pairs_normalise_to_apps_list(): void
    {
        [$owner, $store] = $this->store();

        $this->actingAs($owner)->postJson(route('biolink.blocks.store'), [
            'type' => 'delivery_apps', 'label' => 'Order',
            'config_pairs' => "Talabat | https://talabat.test\nHungerStation | https://hs.test\n | https://noname.test\nBadRow",
        ])->assertOk();

        $apps = $this->svc->created[0]['data']['config']['apps'];
        $this->assertCount(3, $apps); // "BadRow" (no url) dropped; empty-name kept
        $this->assertSame(['name' => 'Talabat', 'url' => 'https://talabat.test'], $apps[0]);
        $this->assertSame('HungerStation', $apps[1]['name']);
    }

    public function test_schedule_fields_are_persisted(): void
    {
        [$owner, $store] = $this->store();

        $this->actingAs($owner)->postJson(route('biolink.blocks.store'), [
            'type' => 'announcement', 'config' => ['text' => 'Eid hours'],
            'visible_from' => '2026-09-01T09:00', 'visible_until' => '2026-09-10T18:00',
        ])->assertOk();

        $data = $this->svc->created[0]['data'];
        $this->assertSame('2026-09-01T09:00', $data['visible_from']);
        $this->assertSame('2026-09-10T18:00', $data['visible_until']);
        $this->assertSame('Eid hours', $data['config']['text']);
    }

    public function test_update_toggle_reorder_and_delete_reach_the_service(): void
    {
        [$owner, $store] = $this->store();

        $this->actingAs($owner)->postJson(route('biolink.blocks.update', 10), ['type' => 'link', 'label' => 'Renamed'])->assertOk();
        $this->assertSame(10, $this->svc->updated[0]['blockId']);
        $this->assertSame('Renamed', $this->svc->updated[0]['data']['label']);

        $this->actingAs($owner)->postJson(route('biolink.blocks.toggle', 10))->assertOk();
        $this->assertSame([10], $this->svc->toggled);

        $this->actingAs($owner)->postJson(route('biolink.blocks.reorder'), ['order' => [3, 1, 2]])->assertOk();
        $this->assertSame([3, 1, 2], $this->svc->reordered[0]);

        $this->actingAs($owner)->deleteJson(route('biolink.blocks.destroy', 10))->assertOk();
        $this->assertSame([10], $this->svc->deleted);
    }

    public function test_invalid_block_type_is_rejected(): void
    {
        [$owner, $store] = $this->store();

        $this->actingAs($owner)->postJson(route('biolink.blocks.store'), ['type' => 'bogus'])
            ->assertStatus(422);
        $this->assertCount(0, $this->svc->created);
    }

    /* ─────────────────────────── B4 · live preview ────────────────────────── */

    public function test_builder_shows_preview_iframe_and_device_toggle(): void
    {
        [$owner, $store] = $this->store();

        $res = $this->actingAs($owner)->get(route('biolink.builder'))->assertStatus(200);

        // Preview iframe points at the public bio page (Lane A's route; degrades to the
        // plain /bio URL until bio.public exists, then a signed preview URL).
        $res->assertSeeHtml('data-preview-iframe');
        $res->assertSee($store->slug.'/bio');
        // Device toggle (mobile/desktop) + refresh control.
        $res->assertSeeHtml('data-device="mobile"');
        $res->assertSeeHtml('data-device="desktop"');
        $res->assertSeeHtml('data-preview-reload');
    }

    /* ─────────────────────────── B5 · insights ────────────────────────────── */

    public function test_insights_renders_kpis_leaderboard_sources_and_devices(): void
    {
        [$owner, $store] = $this->store();

        $res = $this->actingAs($owner)->get(route('biolink.insights'))->assertStatus(200);

        // KPI strip (views/clicks/CTR/QR) from statsFor().
        $res->assertSee(___('Views'));
        $res->assertSee('120');
        $res->assertSee(___('Clicks'));
        $res->assertSee('25.0%');                  // ctr 0.25 ratio → 25.0%
        $res->assertSee(___('QR scans'));
        $res->assertSee('8');
        // Per-block leaderboard + sources + devices donut.
        $res->assertSee('Order online');
        $res->assertSee(___('Sources'));
        $res->assertSee(___('Devices'));
    }

    public function test_insights_range_chips_are_links_and_clamp_bad_input(): void
    {
        [$owner, $store] = $this->store();

        // Chips are anchors to ?range= (the Spotlight pattern), not AJAX buttons.
        $this->actingAs($owner)->get(route('biolink.insights', ['range' => '30d']))->assertStatus(200)->assertSeeHtml('href="'.route('biolink.insights', ['range' => '90d']).'"');
        $this->assertSame('30d', $this->svc->statsRanges[0]);

        // Out-of-whitelist range falls back to 7d.
        $this->actingAs($owner)->get(route('biolink.insights', ['range' => 'bogus']))->assertStatus(200);
        $this->assertContains('7d', $this->svc->statsRanges);
    }

    public function test_insights_empty_states(): void
    {
        [$owner, $store] = $this->store();
        $this->svc->stats = ['views' => 0, 'clicks' => 0, 'ctr' => 0, 'qr_scans' => 0, 'per_block' => [], 'sources' => [], 'devices' => []];

        $this->actingAs($owner)->get(route('biolink.insights'))
            ->assertStatus(200)
            ->assertSee(___('No clicks yet'))
            ->assertSee(___('No traffic yet'));
    }

    /* ─────────────────────── B8 · per-locale content ──────────────────────── */

    public function test_builder_shows_locale_switcher_when_multilocale(): void
    {
        [$owner, $store] = $this->store();
        $this->svc->locales = ['en', 'ar'];   // default 'en' + one override locale

        $this->actingAs($owner)->get(route('biolink.builder'))
            ->assertStatus(200)->assertSee(___('Translations'))->assertSeeHtml('name="i18n[ar][label]"');
    }

    public function test_single_locale_store_hides_the_locale_switcher(): void
    {
        [$owner, $store] = $this->store();
        $this->svc->locales = ['en'];   // only the default locale

        $this->actingAs($owner)->get(route('biolink.builder'))->assertStatus(200)->assertDontSeeHtml('name="i18n[');
    }

    public function test_store_block_writes_a_locale_override(): void
    {
        [$owner, $store] = $this->store();

        $this->actingAs($owner)->postJson(route('biolink.blocks.store'), [
            'type' => 'link', 'label' => 'Order online', 'url' => 'https://x.test',
            'i18n' => ['ar' => ['label' => 'اطلب اونلاين']],
        ])->assertOk();

        $config = $this->svc->created[0]['data']['config'];
        $this->assertSame('اطلب اونلاين', $config['_i18n']['ar']['label']);
        // Base label stays the default-locale string.
        $this->assertSame('Order online', $this->svc->created[0]['data']['label']);
    }

    public function test_empty_override_does_not_persist_an_i18n_key(): void
    {
        [$owner, $store] = $this->store();

        $this->actingAs($owner)->postJson(route('biolink.blocks.store'), [
            'type' => 'link', 'label' => 'Order online', 'url' => 'https://x.test',
            'i18n' => ['ar' => ['label' => '']],   // blank override → falls back to base
        ])->assertOk();

        $this->assertArrayNotHasKey('_i18n', $this->svc->created[0]['data']['config']);
    }

    /* ─────────────────────────── B9 · authz suite ─────────────────────────── */

    public function test_staff_with_biolink_permission_reaches_the_dashboard(): void
    {
        [$owner, $store] = $this->store();
        $staff = $this->staff($store, 'manager', ['biolink' => true]);

        $this->asStaff($owner, $staff)->get(route('biolink.index'))->assertStatus(200);
        $this->asStaff($owner, $staff)->get(route('biolink.builder'))->assertStatus(200);
        $this->asStaff($owner, $staff)->get(route('biolink.insights'))->assertStatus(200);
    }

    public function test_staff_without_biolink_permission_is_forbidden(): void
    {
        [$owner, $store] = $this->store();
        $staff = $this->staff($store, 'manager', ['biolink' => false]);

        $this->asStaff($owner, $staff)->get(route('biolink.index'))->assertStatus(403);
        $this->asStaff($owner, $staff)->get(route('biolink.builder'))->assertStatus(403);
        // Writes are barred too (unmapped would default-deny; the prefix rule maps them).
        $this->asStaff($owner, $staff)->postJson(route('biolink.blocks.store'), ['type' => 'link'])->assertStatus(403);
    }

    public function test_staff_with_permission_sees_the_nav_link(): void
    {
        [$owner, $store] = $this->store();
        $staff = $this->staff($store, 'manager', ['biolink' => true]);

        // A permitted staff member reaches the page AND the gated Bio-page nav link
        // renders for them (dashboard_allows('biolink') = true). A staff member WITHOUT
        // the permission is 403'd at the access layer (see the forbidden test) and the
        // same dashboard_allows wrap keeps the link out of their nav.
        $this->asStaff($owner, $staff)->get(route('biolink.index'))->assertStatus(200)->assertSeeHtml('href="'.route('biolink.index').'"');
    }

    public function test_demo_mode_blocks_writes_but_allows_reads(): void
    {
        User::factory()->create();                 // ensure the owner is not user id 1 (demo-exempt)
        [$owner, $store] = $this->store();
        config(['app.demo_mode' => true]);

        // Reads still work on the demo site.
        $this->actingAs($owner)->get(route('biolink.index'))->assertStatus(200);

        // Writes are intercepted by the `demo` middleware before the controller runs.
        $this->actingAs($owner)->post(route('biolink.settings.save'), ['enabled' => '1', 'preset' => 'classic_flat', 'mode' => 'menu_first']);
        $this->actingAs($owner)->post(route('biolink.blocks.store'), ['type' => 'link', 'label' => 'X']);

        $this->assertCount(0, $this->svc->saved);
        $this->assertCount(0, $this->svc->created);
    }

    public function test_block_limit_is_enforced_server_side(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        [$owner, $store] = $this->storeOnPlan(['bio_blocks_limit' => 1]);
        $this->svc->blocks = [['id' => 1, 'type' => 'link', 'label' => 'One', 'config' => [], 'active' => true]];

        $this->actingAs($owner)->postJson(route('biolink.blocks.store'), ['type' => 'link', 'label' => 'Two'])
            ->assertStatus(422);
        $this->assertCount(0, $this->svc->created);
    }

    public function test_unlimited_plan_allows_adding_blocks(): void
    {
        [$owner, $store] = $this->storeOnPlan(['bio_blocks_limit' => 999]);
        $this->svc->blocks = [['id' => 1, 'type' => 'link', 'label' => 'One', 'config' => [], 'active' => true]];

        $this->actingAs($owner)->postJson(route('biolink.blocks.store'), ['type' => 'link', 'label' => 'Two'])->assertOk();
        $this->assertCount(1, $this->svc->created);
    }
}
