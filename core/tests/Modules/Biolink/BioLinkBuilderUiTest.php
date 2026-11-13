<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Biolink\Services\BioLinkService;
use Tests\TestCase;

/**
 * bio-t15 Lane C — Builder UI HTTP/render tests (SPEC §2/§3/§8/§9, ORCHESTRATION
 * Lane C fence). This suite proves the builder DASHBOARD renders the editor
 * markup for every new Tier-1/2 block type (A1–A11), the appearance tab (B's §3
 * schema), the schedule/pin toggles (A12/A13) and the gating UI (C7) — entirely
 * from the frozen SPEC contract, independent of Lane A/B's engine code. Same
 * fixture-stub idiom as BioLinkDashboardTest: BioLinkService is resolved by
 * string in the controller, so this tree never needs the real (Lane A/B-owned)
 * class to exist.
 *
 * NOTE: BioLinkController::BLOCK_TYPES (Lane A's file) still lists only the
 * original 17 types in THIS worktree, so the "Add a block" CHIP for a brand-new
 * type (call/delivery/giftcard/loyalty/gallery/faq/event/tipjar) is not shown
 * yet — the add-tray gates chips by `array_intersect($groupTypes, $blockTypes)`.
 * That is expected cross-lane decoupling (ORCHESTRATION: "C does not wait on
 * A/B code"). What IS testable here, and what these tests assert, is that the
 * editor FORM FIELDS for every new type exist in the rendered DOM (they are
 * `data-group data-types="…"` divs inside the always-rendered edit form, shown/
 * hidden by client JS based on the currently selected type — independent of
 * whether the type's chip is visible yet).
 */
class BioLinkBuilderUiTest extends TestCase
{
    use RefreshDatabase;

    /** @var object the bound service stub (fixture reads only; builder never writes through it in this suite) */
    private $svc;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['settings.admin_allow_biolink' => true]);
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $this->shareLayoutGlobals(['currency_sign' => '$', 'currency_pos' => 1]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);

        $this->svc = $this->makeStub();
        app()->instance(BioLinkService::class, $this->svc);
    }

    private function makeStub(): object
    {
        return new class
        {
            public array $blocks = [];

            public array $settings = ['enabled' => true, 'preset' => 'classic_flat', 'mode' => 'menu_first', 'sections' => ['hours' => true], 'branch_pages' => false];

            public array $presetList = [
                ['slug' => 'classic_flat', 'name' => 'Classic Flat', 'family' => 'list', 'tier' => 'basic'],
            ];

            public array $locales = [];

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

            public function statsFor(int $storeId, string $range): array
            {
                return [];
            }

            public function presets(): array
            {
                return $this->presetList;
            }

            public function enabledLocales(int $storeId): array
            {
                return $this->locales;
            }

            public function saveSettings(int $storeId, array $settings): void {}

            public function createBlock(int $storeId, ?int $branchId, array $data): array
            {
                return ['id' => 999] + $data;
            }

            public function updateBlock(int $storeId, int $blockId, array $data): void {}

            public function toggleBlock(int $storeId, int $blockId): void {}

            public function deleteBlock(int $storeId, int $blockId): void {}

            public function reorderBlocks(int $storeId, array $orderedIds): void {}
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

    /* ─────────────────────────── C1 · partial extraction ──────────────────── */

    public function test_builder_renders_from_the_extracted_partials_intact(): void
    {
        [$owner, $store] = $this->store();

        $res = $this->actingAs($owner)->get(route('biolink.builder'))->assertStatus(200);

        // Add tray (partials/_add_tray.blade.php) — chip + label survive the split.
        $res->assertSee(___('Add a block'));
        $res->assertSeeHtml('data-add-block="link"');

        // Block editor fields (partials/_block_fields.blade.php) — a couple of
        // pre-existing per-type fields survive the split verbatim.
        $res->assertSeeHtml('name="label"');
        $res->assertSeeHtml('data-field="config.consent_text"');

        // Scheduling & visibility + active toggle (partials/_schedule_pin.blade.php).
        $res->assertSee(___('Scheduling & visibility'));
        $res->assertSeeHtml('id="editor-active"');

        // The `.bio-block-row` JS alias + the editor's data- hooks are untouched.
        $res->assertSeeHtml('data-editor-form');
        $res->assertSeeHtml('data-biolink-list');

        // CSP: the dashboard layout itself carries several unrelated nonced
        // <style>/<script> tags (Bootstrap components, toasts, …), so the
        // page-wide count isn't the right assertion. What Lane C owns is that
        // splitting the monolith did NOT introduce a second nonced pair inside
        // the biolink builder's own markup — the partials on disk must carry
        // none at all; the single nonced pair stays in the parent view.
        foreach ([
            'partials/_add_tray.blade.php',
            'partials/_block_fields.blade.php',
            'partials/_schedule_pin.blade.php',
            'partials/_appearance.blade.php',
        ] as $partial) {
            $src = file_get_contents(resource_path('views/owner/biolink/'.$partial));
            $this->assertStringNotContainsString('<style', $src, $partial.' must not add its own <style> tag');
            $this->assertStringNotContainsString('<script', $src, $partial.' must not add its own <script> tag');
        }
        $parentSrc = file_get_contents(resource_path('views/owner/biolink/builder.blade.php'));
        $this->assertSame(1, substr_count($parentSrc, '<style nonce='), 'builder.blade.php must keep exactly one nonced <style>');
        $this->assertSame(1, substr_count($parentSrc, '<script nonce='), 'builder.blade.php must keep exactly one nonced <script>');
    }

    /* ─────────────────────────── C3 · new block-type fields ───────────────── */

    /**
     * The editor form fields for every Tier 1/2 type (SPEC §2 A1–A11) render in
     * the DOM regardless of whether Lane A has landed the type in BLOCK_TYPES
     * yet — they're `data-group data-types="…"` divs inside the always-rendered
     * edit form, shown/hidden client-side. This is what "editor fields ship
     * ahead of the engine" means in practice, and it's what this test proves.
     */
    public function test_editor_exposes_config_fields_for_every_new_block_type(): void
    {
        [$owner, $store] = $this->store();

        $res = $this->actingAs($owner)->get(route('biolink.builder'))->assertStatus(200);

        // A1 hours
        $res->assertSeeHtml('data-field="config.source"');
        $res->assertSeeHtml('data-field="config.tz"');
        $res->assertSeeHtml('data-field="config.show_status"');
        $res->assertSeeHtml('data-hours-day="mon"');
        $res->assertSeeHtml('data-hours-day="sun"');

        // A2 call
        $res->assertSeeHtml('id="call-show-call"');
        $res->assertSeeHtml('data-field="config.show.call"');
        $res->assertSeeHtml('data-field="config.show.whatsapp"');
        $res->assertSeeHtml('data-field="config.show.sms"');

        // A3 reservation
        $res->assertSeeHtml('name="config[mode]"');
        $res->assertSeeHtml('data-field="config.party_note"');

        // A4 reviews
        $res->assertSeeHtml('data-field="config.platform"');
        $res->assertSeeHtml('data-field="config.rating"');
        $res->assertSeeHtml('data-field="config.show_stars"');

        // A5 delivery + A9 faq + A8 gallery all reuse the config_pairs textarea
        $res->assertSeeHtml('data-types="delivery_apps socials delivery faq gallery"');

        // A6 giftcard / A7 loyalty
        $res->assertSeeHtml('data-field="config.provider"');

        // A8 gallery layout/ratio
        $res->assertSeeHtml('data-field="config.ratio"');

        // A10 event
        $res->assertSeeHtml('data-field="config.date"');
        $res->assertSeeHtml('data-field="config.location"');
        $res->assertSeeHtml('data-field="config.capture"');

        // A11 tipjar
        $res->assertSeeHtml('data-field="config.currency"');
        $res->assertSeeHtml('data-field="config.amounts.0"');
        $res->assertSeeHtml('data-field="config.amounts.3"');
    }

    /* ─────────────────────────── C4 · schedule + pin toggles ──────────────── */

    /**
     * A12 (recurring schedule window) and A13 (pinned/spotlight) live in
     * partials/_schedule_pin.blade.php and apply to every block type — proven
     * by checking they render alongside the pre-existing one-time
     * visible_from/visible_until fields.
     */
    public function test_editor_exposes_schedule_and_pin_controls(): void
    {
        [$owner, $store] = $this->store();

        $res = $this->actingAs($owner)->get(route('biolink.builder'))->assertStatus(200);

        // Pre-existing one-time window untouched.
        $res->assertSeeHtml('name="visible_from"');
        $res->assertSeeHtml('name="visible_until"');

        // A12 recurring window: start/end time + all 7 day checkboxes.
        $res->assertSeeHtml('name="config[schedule][start_at]"');
        $res->assertSeeHtml('name="config[schedule][end_at]"');
        for ($d = 0; $d <= 6; $d++) {
            $res->assertSeeHtml('id="schedule-day-'.$d.'"');
        }

        // A13 pin + highlight.
        $res->assertSeeHtml('id="editor-pinned"');
        $res->assertSeeHtml('name="config[highlight]"');
        $res->assertSee(___('Pulse'));
        $res->assertSee(___('Glow'));
    }

    /* ─────────────────────────── C2 · appearance tab ───────────────────────── */

    /**
     * Every SPEC §3 appearance control renders, the tab switcher hooks are
     * present, and the appearance form carries hidden mirrors of the OTHER
     * settings (enabled/preset/mode/sections/branch_pages) so a save through
     * the shared settings.save route never resets them.
     */
    public function test_appearance_tab_exposes_every_spec_control(): void
    {
        [$owner, $store] = $this->store();
        $this->svc->settings = [
            'enabled' => true, 'preset' => 'classic_flat', 'mode' => 'bio_first',
            'sections' => ['hours' => true, 'socials' => false], 'branch_pages' => true,
        ];

        $res = $this->actingAs($owner)->get(route('biolink.builder'))->assertStatus(200);

        // Tab switcher hooks.
        $res->assertSeeHtml('data-builder-tab-btn="blocks"');
        $res->assertSeeHtml('data-builder-tab-btn="appearance"');
        $res->assertSeeHtml('data-appearance-form');
        $res->assertSeeHtml('action="'.route('biolink.settings.save').'"');

        // Colors.
        foreach (['accent', 'accent_ink', 'ink', 'muted', 'btn_bg', 'btn_fg'] as $c) {
            $res->assertSeeHtml('name="appearance['.$c.']"');
        }
        // Buttons.
        $res->assertSeeHtml('name="appearance[btn_style]"');
        $res->assertSeeHtml('name="appearance[btn_radius]"');
        $res->assertSeeHtml('name="appearance[btn_align]"');
        $res->assertSeeHtml('name="appearance[show_icons]"');
        $res->assertSeeHtml('name="appearance[show_arrows]"');
        // Background builder (solid/gradient/pattern/image/video).
        $res->assertSeeHtml('name="appearance[bg_type]"');
        $res->assertSeeHtml('name="appearance[bg_solid]"');
        $res->assertSeeHtml('name="appearance[bg_gradient][from]"');
        $res->assertSeeHtml('name="appearance[bg_gradient][angle]"');
        $res->assertSeeHtml('name="appearance[bg_pattern]"');
        $res->assertSeeHtml('id="bg-pattern-kraft"');
        $res->assertSeeHtml('id="bg-pattern-zellige"');
        $res->assertSeeHtml('name="appearance[bg_image]"');
        $res->assertSeeHtml('name="appearance[bg_scrim]"');
        // Fonts — the frozen §4 curated list (spot check a Latin + an Arabic family).
        $res->assertSeeHtml('name="appearance[font_display]"');
        $res->assertSeeHtml('name="appearance[font_body]"');
        $res->assertSeeHtml('>Bebas Neue<');
        $res->assertSeeHtml('>Cairo');
        // Header.
        $res->assertSeeHtml('name="appearance[avatar_shape]"');
        $res->assertSeeHtml('name="appearance[cover_image]"');
        $res->assertSeeHtml('name="appearance[show_cover]"');
        foreach (['title', 'tagline', 'status', 'verified'] as $hk) {
            $res->assertSeeHtml('name="appearance[hide]['.$hk.']"');
        }
        // OG / SEO metadata.
        $res->assertSeeHtml('name="appearance[og][title]"');
        $res->assertSeeHtml('name="appearance[og][description]"');
        $res->assertSeeHtml('name="appearance[og][image]"');

        // Hidden mirrors of the rest of Settings — preserved across a save.
        $res->assertSeeHtml('name="mode" value="bio_first"');
        $res->assertSeeHtml('name="branch_pages" value="1"');
        $res->assertSeeHtml('name="sections[hours]" value="1"');
        $res->assertSeeHtml('name="sections[socials]" value="0"');
    }

    /**
     * The Profile card exposes the display name + tagline + avatar (which drive
     * .bio-name / .bio-profile on the public page — there was no field for either
     * before), and every image field (background/cover/avatar/share) is wired to
     * the shared media-library / image-studio picker rather than a raw URL box.
     */
    public function test_appearance_tab_has_profile_fields_and_media_pickers(): void
    {
        [$owner, $store] = $this->store();

        $res = $this->actingAs($owner)->get(route('biolink.builder'))->assertStatus(200);

        // Profile identity fields.
        $res->assertSeeHtml('name="appearance[display_name]"');
        $res->assertSeeHtml('name="appearance[tagline]"');
        $res->assertSeeHtml('name="appearance[avatar_image]"');

        // Each image field opens the media-library / image-studio picker.
        $res->assertSeeHtml('openMediaPicker'); // the picker partial is included on the page
        foreach (['bio-avatar', 'bio-bg', 'bio-cover', 'bio-og'] as $target) {
            $res->assertSeeHtml("target: '".$target."'");
        }
        // The picker binds the bare library filename, not a pasted URL.
        $res->assertSeeHtml(':value="filename"');
    }

    /**
     * SPEC §9 deferred stubs: video bg, uploaded font and custom domain render
     * as disabled controls with an owner-setup hint, never as functioning
     * inputs — nobody can use them yet regardless of plan.
     */
    public function test_appearance_tab_shows_deferred_stubs_disabled_with_a_hint(): void
    {
        [$owner, $store] = $this->store();

        $res = $this->actingAs($owner)->get(route('biolink.builder'))->assertStatus(200);

        // Video background option is present but disabled.
        $res->assertSeeHtml('value="video" disabled');
        $res->assertSee(___('Coming soon'));

        // Font upload is a disabled file input.
        $res->assertSeeHtmlInOrder([___('Upload your own font'), 'disabled']);

        // Custom domain is a disabled, read-only-style panel.
        $res->assertSee(___('Connect a domain (owner setup required) — contact support.'));
    }

    /* ─────────────────────────── C5 · live preview extend ─────────────────── */

    /**
     * The preview iframe + appearance fields carry the hooks the parent
     * script wires up to append a `preview_appearance=` param to the SAME
     * iframe the design switcher already drives with `preview_preset=` —
     * proven by the wiring markers, since the debounced JS behavior itself
     * isn't something a server-render test can execute.
     */
    public function test_live_preview_wiring_covers_pending_appearance_edits(): void
    {
        [$owner, $store] = $this->store();

        $res = $this->actingAs($owner)->get(route('biolink.builder'))->assertStatus(200);
        $res->assertSeeHtml('data-preview-iframe');
        $res->assertSeeHtml('data-appearance-form');

        // At least one appearance field carries the collector hook, and the
        // parent script's own preview-extension logic is present verbatim.
        $html = $res->getContent();
        $this->assertGreaterThan(10, substr_count($html, 'data-appearance-field='));
        $this->assertStringContainsString('preview_appearance', $html);
        $this->assertStringContainsString('scheduleAppearancePreview', $html);
    }

    /* ─────────────────────────── C6 · palette regroup + polish ────────────── */

    public function test_add_tray_search_filter_and_every_block_group_render(): void
    {
        [$owner, $store] = $this->store();

        $res = $this->actingAs($owner)->get(route('biolink.builder'))->assertStatus(200);

        $res->assertSeeHtml('data-addtray-filter');
        $res->assertSeeHtml('data-addtray-chip');
        $res->assertSee(___('Search blocks…'));

        // The 4 SPEC-named groups all still render (nothing silently dropped
        // when the Tier 1/2 catalog was folded in).
        $res->assertSee(___('Content'));
        $res->assertSee(___('Menu & food'));
        $res->assertSee(___('Visit & contact'));
        $res->assertSee(___('Social & media'));

        // No block type present in BLOCK_TYPES lost its chip when C6 regrouped
        // (the original 17-type contract test already covers this — re-assert
        // a representative sample here so a future regroup edit trips this
        // suite too, not just BioLinkDashboardTest).
        foreach (['link', 'menu', 'hours', 'socials', 'reviews', 'capture'] as $type) {
            $res->assertSeeHtml('data-add-block="'.$type.'"');
        }
    }

    /* ─────────────────────────── C7 · gating UI ────────────────────────────── */

    /**
     * SPEC §8: tipjar and the appearance image-background are PRO; the badge
     * here is cosmetic only (no plan-entitlement signal reaches this view —
     * out of Lane C's file fence) — the real gate is server-side (sanitizer +
     * block store, Lane A/B). SPEC §9: video bg / font upload / custom domain
     * / capture SMS opt-in are DEFERRED — rendered disabled with an
     * owner-setup hint, unconditionally, since nobody can use them yet.
     */
    public function test_gating_ui_shows_pro_badges_and_deferred_hints(): void
    {
        [$owner, $store] = $this->store();

        $res = $this->actingAs($owner)->get(route('biolink.builder'))->assertStatus(200);

        // PRO — cosmetic badge. The tipjar CHIP itself only appears once Lane A
        // lands 'tipjar' in BLOCK_TYPES (array_intersect gate, same as every
        // other new type — see the class-level note), so assert the wiring at
        // the source level: $proBlockTypes marks tipjar, and the chip markup
        // renders the badge for any type in that list.
        $parentSrc = file_get_contents(resource_path('views/owner/biolink/builder.blade.php'));
        $this->assertMatchesRegularExpression('/\$proBlockTypes\s*=\s*\[[^\]]*tipjar/', $parentSrc);
        $traySrc = file_get_contents(resource_path('views/owner/biolink/partials/_add_tray.blade.php'));
        $this->assertStringContainsString('bio-addchip__pro', $traySrc);
        $res->assertSee(___('PRO'));

        // DEFERRED — disabled + an owner-setup hint, on every §9 item.
        $res->assertSeeHtml('value="video" disabled');   // background video
        $res->assertSeeHtmlInOrder([___('Upload your own font'), 'disabled']); // uploaded font
        $res->assertSee(___('Connect a domain (owner setup required) — contact support.')); // custom domain
        $res->assertSee(___('Also collect a phone number (SMS opt-in)')); // capture SMS opt-in
        $this->assertGreaterThanOrEqual(2, substr_count($res->getContent(), ___('Coming soon — contact support.')));
    }
}
