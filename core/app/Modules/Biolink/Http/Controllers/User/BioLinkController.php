<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Biolink\Http\Controllers\User;

use App\Models\MenuCategory;
use App\Services\Directory\OpeningHoursService;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use App\Services\ResellerPages\BuilderTemplates;
use App\Support\BioAppearance;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Juwri\Core\Http\Controllers\Concerns\ResolvesCurrentStore;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Support\Html;
use Juwri\Modules\Biolink\Services\BioInsightsService;
use Juwri\Modules\Biolink\Services\BioLinkService;
use Juwri\Modules\Menu\Models\Menu;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

/**
 * Biolink Pages — Lane B (dashboards & roles).
 *
 * Owner/staff surfaces for the Linktree-class bio page: Settings (preset picker,
 * section toggles, routing mode, store/branch link, QR/copy), Builder (blocks
 * CRUD/reorder/toggle/schedule + typed add tray + live preview) and Insights
 * (KPIs, leaderboard, sources, devices).
 *
 * DATA CONTRACT: every read and write goes through {@see BioLinkService} (SPEC §3),
 * resolved from the container by STRING — never a constructor type-hint — so this
 * lane compiles and its HTTP tests run green with a bound stub even though Lane A
 * owns the concrete service/model/migrations. Tests bind a fixture stub via
 * `app()->instance(BioLinkService::class, …)`; production resolves the real class.
 *
 * Tenant scoping is the shared {@see ResolvesCurrentStore} guard (403 off-tenant).
 * The superadmin kill-switch is `feature:biolink` on the route group; staff access is
 * `staff.dashboard` (biolink.* → 'biolink' permission); demo writes are blocked by the
 * `demo` middleware on the write verbs.
 */
class BioLinkController extends Controller
{
    use ResolvesCurrentStore;

    /** The 14 locked block types (SPEC §3). */
    public const BLOCK_TYPES = [
        'link', 'menu', 'menu_item', 'hours', 'map', 'whatsapp', 'delivery_apps',
        'reservation', 'reviews', 'announcement', 'socials', 'capture', 'video', 'featured_dish',
        'text', 'image', 'divider',
        // bio-t15 Lane A — kept in lockstep with BioBlock::TYPES.
        'call', 'delivery', 'giftcard', 'loyalty', 'gallery', 'faq', 'event', 'tipjar',
    ];

    public const MODES = ['menu_first', 'bio_first', 'bio_only'];

    /** Section toggles surfaced on the settings page. */
    public const SECTIONS = ['hours', 'socials', 'capture', 'map', 'whatsapp', 'reviews'];

    public const RANGES = ['24h', '7d', '30d', '90d'];

    /** Builder.js starting template seeded when a store switches to Advanced HTML mode. */
    public const BUILDER_DEFAULT_TEMPLATE = 'Y0_Blank';

    /**
     * C1 · empty-state chooser: one curated card per starter archetype (SPEC
     * T1-1/T1-3). Each card seeds a representative preset whose personality
     * matches the archetype; Lane A's applyStarter() resolves preset → archetype.
     * BASIC-tier presets are listed first so a free plan never dead-ends
     * (`social_grid` is the single pro card, badged + upsold, never a dead button).
     *
     * @var array<int, array{archetype:string, preset:string, icon:string}>
     */
    public const STARTER_CARDS = [
        ['archetype' => 'restaurant_essential', 'preset' => 'menu_card', 'icon' => 'utensils'],
        ['archetype' => 'cafe', 'preset' => 'classic_flat', 'icon' => 'coffee'],
        ['archetype' => 'delivery_late_night', 'preset' => 'classic_dark', 'icon' => 'moped'],
        ['archetype' => 'fine_dining', 'preset' => 'majlis_gold', 'icon' => 'crown'],
        ['archetype' => 'minimal', 'preset' => 'midnight_glass', 'icon' => 'layout'],
        ['archetype' => 'social_grid', 'preset' => 'social_grid', 'icon' => 'apps'],
    ];

    /**
     * The bio engine. Resolved by string so PHP never needs the (Lane-A-owned)
     * class to exist in this tree — the container returns the bound stub in tests
     * and the concrete service in production.
     *
     * @return BioLinkService
     */
    private function svc()
    {
        return app(BioLinkService::class);
    }

    /* ─────────────────────────── B2 · Settings ───────────────────────────── */

    public function index(Request $request): View
    {
        $store = $this->currentRestaurant($request);
        $owner = $this->owner();

        $settings = $this->settings($store->id);
        $branchId = $this->resolveBranchId($request, $store->id);

        return view('owner.biolink.index', [
            'store' => $store,
            'bioSettings' => $settings,
            'presets' => $this->presetGroups($store->id),
            'modes' => self::MODES,
            'sections' => self::SECTIONS,
            'branches' => Branch::where('store_id', $store->id)->orderBy('position')->orderBy('id')->get(),
            'branchId' => $branchId,
            'canBioOnly' => plan_feature('allow_onlybio', $owner),
            'blockLimit' => (int) effective_limit('bio_blocks', $owner),
            'blockCount' => $this->blockCount($store->id),
            'bioUrl' => $this->bioUrl($store),
            'qrUrl' => $this->qrUrl($store),
            // C5 copy-to-store: the owner's OTHER stores (empty → card hidden).
            'copyTargets' => $this->copyTargets($store->id),
            // Advanced HTML (Builder.js) mode: current authoring mode + the switch
            // targets so the settings page can offer "Block editor" vs "Advanced HTML".
            'bioBuilder' => $this->builderMode($store),
        ]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $store = $this->currentRestaurant($request);
        $owner = $this->owner();

        $v = $request->validate([
            'enabled' => ['nullable'],
            'preset' => ['nullable', 'string', 'max:64'],
            'mode' => ['nullable', 'in:'.implode(',', self::MODES)],
            'branch_pages' => ['nullable'],
            'sections' => ['nullable', 'array'],
            'sections.*' => ['nullable'],
            // bio-t15 F0 (Lane B wire): the appearance sub-tree (Tier 4). Raw here —
            // BioAppearance::sanitize() below is the ONLY gate (whitelist keys, #rrggbb
            // colors, enum/number clamps, curated fonts, server-side PRO-key strip).
            'appearance' => ['nullable', 'array'],
        ]);

        // bio_only is only selectable when the plan grants allow_onlybio (SPEC §5);
        // coerce a forged/stale bio_only down to bio_first otherwise.
        $mode = $v['mode'] ?? 'menu_first';
        if ($mode === 'bio_only' && ! plan_feature('allow_onlybio', $owner)) {
            $mode = 'bio_first';
        }

        $sections = [];
        foreach (self::SECTIONS as $key) {
            $sections[$key] = (bool) ($request->input("sections.$key") ?? false);
        }

        $settings = [
            'enabled' => $request->boolean('enabled'),
            'preset' => $v['preset'] ?? 'classic_flat',
            'mode' => $mode,
            'sections' => $sections,
            'branch_pages' => $request->boolean('branch_pages'),
        ];

        // bio-t15 F0 (Lane B wire): persist the sanitized appearance sub-tree so the
        // builder's Appearance tab actually saves. Gating is enforced in the sanitizer
        // (PRO keys stripped unless the plan grants them) — the same FeatureAccess
        // flags the public render uses, so what's saved is exactly what's rendered.
        if ($request->has('appearance')) {
            $planFlags = [
                'bio_pro' => FeatureAccess::storeAllows($store->id, 'bio_pro'),
                'bio_analytics_pro' => FeatureAccess::storeAllows($store->id, 'bio_analytics_pro'),
            ];
            $settings['appearance'] = BioAppearance::sanitize((array) ($v['appearance'] ?? []), $planFlags);
        }

        $this->svc()->saveSettings($store->id, $settings);

        return back()->with('success', ___('Bio page settings saved.'));
    }

    /* ─────────────────────────── B3/B4 · Builder ──────────────────────────── */

    public function builder(Request $request): View
    {
        $store = $this->currentRestaurant($request);
        $owner = $this->owner();

        // Advanced HTML mode: the whole builder is the shared Builder.js editor
        // (the block tray/preview below is the blocks-mode UI only). Existing block
        // stores default to 'blocks' and never reach this branch.
        if ($this->builderMode($store) === 'html') {
            return $this->htmlEditorView($store);
        }

        $branchId = $this->resolveBranchId($request, $store->id);
        $settings = $this->settings($store->id);

        $locales = $this->enabledLocales($store->id);

        return view('owner.biolink.builder', [
            'store' => $store,
            'bioSettings' => $settings,
            // Builder needs the RAW rows (config still carries `_i18n`, text keys
            // NOT locale-resolved) so the per-locale editor can round-trip overrides —
            // blocksFor() is the render contract (strips `_i18n`), editorBlocks() is
            // the edit contract. Degrades to blocksFor() until Lane A ships it.
            'blocks' => $this->editorBlocks($store->id, $branchId),
            'branchId' => $branchId,
            'branches' => Branch::where('store_id', $store->id)->orderBy('position')->orderBy('id')->get(),
            'blockTypes' => self::BLOCK_TYPES,
            'blockLimit' => (int) effective_limit('bio_blocks', $owner),
            'blockCount' => $this->blockCount($store->id),
            // gap-fix: the hours block's "Use my store hours" (auto) option was gated on
            // an undefined var and therefore never shown. Offer it only when the store
            // actually has resolvable weekly hours (else the editor keeps manual-only).
            'hoursAutoAvailable' => OpeningHoursService::weeklyModelForPost($store) !== null,
            'enabledLocales' => $locales,
            'defaultLocale' => $this->defaultLocale(),
            'previewUrl' => $this->previewUrl($store, $branchId),
            // Live design switcher: apply a new skin (preset only, keeps blocks).
            'presetSetUrl' => $this->routeUrlIfExists('biolink.preset.set'),
            // C1 empty-state chooser: preset catalog (allowed/tier) + curated cards.
            'presets' => $this->presetGroups($store->id),
            'starterCards' => $this->starterCards($store->id),
            // C2 AI generate + C4 translate: dark unless the ai_biolink key is
            // shipped AND allowed for this store's owner (SPEC T1-4 gate).
            'aiEnabled' => $this->aiEnabled($owner),
            // C2/C4 post to Lane B's endpoints; null until those routes exist so
            // the UI degrades cleanly in this tree (F0 wires them). // TODO F0
            'aiGenerateUrl' => $this->routeUrlIfExists('biolink.template.generate'),
            'aiApplyUrl' => $this->routeUrlIfExists('biolink.template.generate.apply'),
            'translateUrl' => $this->routeUrlIfExists('biolink.translate'),
            // F0-merge: Lane B's progress endpoint is store-keyed (no {key} path
            // param) — `biolink.translate.progress`, not C's placeholder
            // `translate.status`. The poller fetches this URL directly.
            'translateStatusBase' => $this->routeUrlIfExists('biolink.translate.progress'),
            'applyUrl' => route('biolink.template.apply'),
            // C3 menu-item picker: the store's items grouped by category.
            'menuGroups' => $this->menuGroups($store->id),
        ]);
    }

    /* ───────────── Advanced HTML mode · Builder.js (additive) ─────────────────
       A store's bio page can be authored as blocks (the existing builder above) OR
       as a freeform Advanced-HTML page via the shared Builder.js editor
       (resources/views/builder/editor.blade.php), mirroring the reseller PageBuilder
       reference. Storage lives on the `posts` row (bio_builder / bio_design_json /
       bio_html / bio_published_html). Every action is tenant-scoped through
       currentRestaurant() (403 off-tenant — never trust the request for ownership);
       the routes ride the same feature:biolink + staff.dashboard + demo stack, plus
       csp.dashboard so Builder.js's runtime eval is allowed (unsafe-eval + nonce).
       Builder HTML is UNTRUSTED: it is sanitized through Html::cleanPage() on save,
       publish AND at echo (the /{slug}/bio render), which is the XSS choke point. */

    /** 'html' when the store's bio page is authored with Builder.js, else 'blocks'. */
    private function builderMode(Store $store): string
    {
        return ($store->bio_builder ?? 'blocks') === 'html' ? 'html' : 'blocks';
    }

    /** The stored Builder.js design ({base, data}) decoded, or [] when none/invalid. */
    /** @return array<string, mixed> */
    private function bioDesign(Store $store): array
    {
        $raw = $store->bio_design_json;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /** The base template id the design was built against (validated; blank → default). */
    private function bioBaseTemplate(Store $store): string
    {
        $design = $this->bioDesign($store);
        $base = $design['base'] ?? self::BUILDER_DEFAULT_TEMPLATE;

        return BuilderTemplates::exists((string) $base) ? (string) $base : self::BUILDER_DEFAULT_TEMPLATE;
    }

    /** Render the shared Builder.js editor for this store's Advanced-HTML bio page. */
    private function htmlEditorView(Store $store): View
    {
        $design = $this->bioDesign($store);
        $base = $this->bioBaseTemplate($store);
        // store_id is threaded onto every editor endpoint so a multi-store owner
        // always saves against the store they opened (not merely the session-current).
        $q = ['store_id' => $store->id];
        $title = trim((string) ($store->bio_title ?: $store->title ?: '')) ?: ___('Bio page');

        return view('builder.editor', [
            'designData' => $design['data'] ?? null,
            'draftHtml' => (string) ($store->bio_html ?? ''),
            'baseTemplate' => $base,
            'baseUrl' => BuilderTemplates::baseUrl($base),
            'templates' => BuilderTemplates::all(),
            'builder' => [
                'title' => $title,
                'backUrl' => route('biolink.index', $q),
                'saveUrl' => route('biolink.html.save', $q),
                'publishUrl' => route('biolink.html.publish', $q),
                'previewUrl' => route('biolink.html.preview', $q),
                'assetsUrl' => route('biolink.html.assets', $q),
                'aiUrl' => route('biolink.html.ai', $q),
                'templateUrl' => route('biolink.html.template', $q),
            ],
        ]);
    }

    /** Switch the store's bio page INTO Advanced HTML mode + seed a starting template. */
    public function htmlEnable(Request $request): RedirectResponse
    {
        $store = $this->currentRestaurant($request);

        $tpl = (string) $request->input('template', self::BUILDER_DEFAULT_TEMPLATE);
        if (! BuilderTemplates::exists($tpl)) {
            $tpl = self::BUILDER_DEFAULT_TEMPLATE;
        }

        $store->bio_builder = 'html';
        // Only seed the design/html the first time (or when empty) so re-enabling
        // never clobbers an owner's existing Advanced-HTML work.
        if ($this->bioDesign($store) === []) {
            $html = Html::cleanPage(BuilderTemplates::html($tpl));
            $store->bio_design_json = json_encode(['base' => $tpl, 'data' => BuilderTemplates::design($tpl)]);
            $store->bio_html = $html;
            // Seed the published snapshot too so a store that enables + goes live
            // before its first explicit Publish never serves a blank page.
            $store->bio_published_html = $html;
        }
        $store->save();

        return redirect()->route('biolink.builder', ['store_id' => $store->id])
            ->with('success', ___('Advanced HTML editor enabled.'));
    }

    /** Switch back to the block editor. Non-destructive — the HTML draft is kept. */
    public function htmlDisable(Request $request): RedirectResponse
    {
        $store = $this->currentRestaurant($request);
        $store->bio_builder = 'blocks';
        $store->save();

        return redirect()->route('biolink.builder', ['store_id' => $store->id])
            ->with('success', ___('Block editor enabled.'));
    }

    /** Autosave the Builder.js design + sanitized rendered HTML (PUT). */
    public function htmlSave(Request $request): JsonResponse
    {
        $store = $this->currentRestaurant($request);

        $design = $request->input('design');
        if (is_string($design)) {
            $design = json_decode($design, true);
        }
        $base = (string) $request->input('base', self::BUILDER_DEFAULT_TEMPLATE);
        if (! BuilderTemplates::exists($base)) {
            $base = self::BUILDER_DEFAULT_TEMPLATE;
        }

        // bio_design_json is the editor's own state (reloaded verbatim into Builder.js);
        // bio_html is the RENDERED output and the only thing ever echoed to visitors, so
        // it is the XSS choke point — purified through Html::cleanPage() here (and again
        // on publish + at echo, defence in depth).
        $store->bio_builder = 'html';
        $store->bio_design_json = json_encode(['base' => $base, 'data' => is_array($design) ? $design : null]);
        $store->bio_html = Html::cleanPage((string) $request->input('html', ''));
        $store->save();

        return response()->json(['ok' => true, 'saved_at' => now()->toIso8601String()]);
    }

    /** Publish: snapshot the sanitized draft HTML → bio_published_html (public render). */
    public function htmlPublish(Request $request): JsonResponse
    {
        $store = $this->currentRestaurant($request);

        // Re-purify at publish time (defence in depth against a stale draft).
        $store->bio_published_html = Html::cleanPage((string) ($store->bio_html ?? ''));
        $store->save();

        return response()->json(['ok' => true, 'status' => 'published']);
    }

    /** Owner/staff DRAFT preview of the Advanced-HTML page (sanitized at echo). */
    public function htmlPreview(Request $request): View
    {
        $store = $this->currentRestaurant($request);

        return view('bio.html', [
            'store' => $store,
            'html' => Html::cleanPage((string) ($store->bio_html ?? '')),
            'rtl' => is_rtl(),
            'isPreview' => true,
            'poweredBy' => '',
            'favicon' => (string) (brand_favicon() ?? ''),
        ]);
    }

    /** Reset the page's design to a chosen Builder.js template (draft only). A full
     *  reset+reload is required: Builder.js's getHtml() reads its own iframe, which a
     *  second in-place load() leaves stale, so the shared editor reloads on success. */
    public function htmlSetTemplate(Request $request): JsonResponse
    {
        $store = $this->currentRestaurant($request);

        $tpl = (string) $request->input('template', '');
        if (! BuilderTemplates::exists($tpl)) {
            return response()->json(['ok' => false, 'error' => ___('Could not load that template.')], 422);
        }

        $store->bio_builder = 'html';
        $store->bio_design_json = json_encode(['base' => $tpl, 'data' => BuilderTemplates::design($tpl)]);
        $store->bio_html = Html::cleanPage(BuilderTemplates::html($tpl));
        $store->save();

        return response()->json(['ok' => true]);
    }

    /** Builder.js assetUploadHandler: store an image, return {url}. Uses image_upload()
     *  so the file lands under the served media root (repo-root /storage, the docroot) —
     *  the Laravel 'public' disk does NOT serve on this repo-root-docroot setup. */
    public function htmlUploadAsset(Request $request): JsonResponse
    {
        $store = $this->currentRestaurant($request); // scope to the owner's store (403 off-tenant)
        $request->validate(['file' => 'required|file|image|max:8192']); // 8 MB

        $dir = 'storage/bio/'.$store->id.'/builder/';
        $filename = image_upload($request->file('file'), $dir); // returns the stored filename only

        return response()->json(['url' => asset($dir.$filename)]);
    }

    /** Builder.js aiHandler. Gated: answer with a clear, non-hanging JSON so the editor
     *  degrades gracefully (biolink AI lives on BioTemplateController's own endpoints). */
    public function htmlAi(Request $request): JsonResponse
    {
        $this->currentRestaurant($request); // scope

        return response()->json([
            'ok' => false,
            'error' => ___('AI assistance is not enabled for the builder yet.'),
        ], 200);
    }

    /* ─── C1 · apply a starter template. F0-merge: Lane C built an isolated
       duplicate applyTemplate() here; the canonical endpoint is Lane A's A3
       applyTemplate() (BIOLINK-TPL A3, below) which owns BioLinkService::
       applyStarter(). C's duplicate was dropped; the chooser UI posts to the
       same `biolink.template.apply` route and reads `created` + `redirect`. */

    /* ─────────────────── C5 · copy bio page to another store ───────────────── */

    /**
     * Copy this store's bio page onto another store the SAME owner controls
     * (SPEC T2-5, mirroring the theme-customizer copyTo pattern). Both ends are
     * ownership-scoped (403 either direction). The target's main-page blocks are
     * REPLACED and its `enabled` flag is forced OFF (a copy never publishes onto a
     * live store). Composed entirely from the locked BioLinkService API so it
     * needs no engine additions.
     */
    public function copyTo(Request $request): JsonResponse|RedirectResponse
    {
        // Source direction: aborts 403 unless the request's store is the owner's.
        $source = $this->currentRestaurant($request);

        $v = $request->validate(['target' => ['required', 'integer']]);
        $targetId = (int) $v['target'];

        // Dest direction: the target must be one of the owner's OTHER stores.
        if ($targetId === $source->id || ! in_array($targetId, $this->storeIds(), true)) {
            abort(403);
        }

        $svc = $this->svc();

        // 1) Settings, minus `enabled` — the target stays unpublished.
        try {
            $settings = (array) $svc->resolveSettings($source->id);
        } catch (\Throwable $e) {
            $settings = [];
        }
        $settings['enabled'] = false;
        $svc->saveSettings($targetId, $settings);

        // 2) Replace the target's main-page blocks with the source's. Mark the
        //    target migrated first so createBlock() does not synthesize its legacy
        //    link1..10 ahead of the copies (G4).
        $svc->markMigrated($targetId);
        foreach ((array) $svc->editorBlocks($targetId, null) as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $svc->deleteBlock($targetId, $id);
            }
        }
        foreach ((array) $svc->editorBlocks($source->id, null) as $row) {
            $svc->createBlock($targetId, null, [
                'type' => (string) ($row['type'] ?? 'link'),
                'label' => $row['label'] ?? null,
                'url' => $row['url'] ?? null,
                'config' => is_array($row['config'] ?? null) ? $row['config'] : [],
                'active' => (bool) ($row['active'] ?? true),
                'visible_from' => $row['visible_from'] ?? null,
                'visible_until' => $row['visible_until'] ?? null,
            ]);
        }

        return $this->ok($request, ___('Bio page copied to the selected store.'));
    }

    public function storeBlock(Request $request): JsonResponse|RedirectResponse
    {
        $store = $this->currentRestaurant($request);
        $owner = $this->owner();

        // Server-side limit enforcement (999 sentinel = unlimited).
        $limit = (int) effective_limit('bio_blocks', $owner);
        if ($limit < 999 && $this->blockCount($store->id) >= $limit) {
            return $this->reject($request, ___('You have reached your bio block limit on this plan.'));
        }

        [$data, $branchId] = $this->validatedBlock($request);

        // bio-t15 A11/SPEC §8: `tipjar` is PRO — reject the write server-side (the
        // UI lock is cosmetic only) rather than letting a non-entitled store spend
        // a block slot on a type its public page will never render anyway.
        if (($data['type'] ?? '') === 'tipjar' && ! $this->bioProAllowed($store->id)) {
            return $this->reject($request, ___('The tip jar block is available on a higher plan.'));
        }

        $block = $this->svc()->createBlock($store->id, $branchId, $data);

        return $this->ok($request, ___('Block added.'), ['block' => $block]);
    }

    public function updateBlock(Request $request, int $id): JsonResponse|RedirectResponse
    {
        $store = $this->currentRestaurant($request);

        [$data] = $this->validatedBlock($request);

        if (($data['type'] ?? '') === 'tipjar' && ! $this->bioProAllowed($store->id)) {
            return $this->reject($request, ___('The tip jar block is available on a higher plan.'));
        }

        $this->svc()->updateBlock($store->id, $id, $data);

        return $this->ok($request, ___('Block updated.'));
    }

    public function toggleBlock(Request $request, int $id): JsonResponse|RedirectResponse
    {
        $store = $this->currentRestaurant($request);

        $this->svc()->toggleBlock($store->id, $id);

        return $this->ok($request, ___('Block visibility updated.'));
    }

    public function reorderBlocks(Request $request): JsonResponse|RedirectResponse
    {
        $store = $this->currentRestaurant($request);

        $ids = array_values(array_filter(array_map('intval', (array) $request->input('order', []))));
        $this->svc()->reorderBlocks($store->id, $ids);

        return $this->ok($request, ___('Order saved.'));
    }

    public function destroyBlock(Request $request, int $id): JsonResponse|RedirectResponse
    {
        $store = $this->currentRestaurant($request);

        $this->svc()->deleteBlock($store->id, $id);

        return $this->ok($request, ___('Block removed.'));
    }

    /* ─────────────────────────── B5 · Insights ────────────────────────────── */

    public function insights(Request $request): View
    {
        $store = $this->currentRestaurant($request);

        $range = (string) $request->query('range', '7d');
        if (! in_array($range, self::RANGES, true)) {
            $range = '7d';
        }

        return view('owner.biolink.insights', [
            'store' => $store,
            'range' => $range,
            'ranges' => self::RANGES,
            'stats' => $this->statsWithDefaults($store->id, $range),
            // bio-t15 F0 seam: Lane D owns the advanced breakdowns (country/referrer,
            // richer device/source) via BioInsightsService::extra(), so the analytics
            // lane never edits this controller. Returns [] until Lane D fills it.
            'extra' => app(BioInsightsService::class)->extra($store->id, $range),
        ]);
    }

    /* ─────────────── BIOLINK-TPL A3 · starter templates (T1-2) ─────────────── */

    /**
     * Seed the store's main bio page from a preset's starter archetype.
     *
     * Tenant-scoped (currentRestaurant → 403 off-tenant); a plan-locked preset is
     * 403 (presetAllowed); applying over an existing page without `replace=1` is
     * 422. The engine (BioLinkService::applyStarter) does the transactional seed,
     * limit trim and hidden-when-missing rule; it never touches enabled/mode.
     */
    public function applyTemplate(Request $request): JsonResponse|RedirectResponse
    {
        $store = $this->currentRestaurant($request);

        $v = $request->validate([
            'preset' => ['required', 'string', 'max:64'],
            'replace' => ['nullable'],
        ]);
        $preset = $v['preset'];
        $replace = $request->boolean('replace');

        // Server-side entitlement: a pro preset the plan does not grant is 403,
        // never a silently-downgraded seed.
        if (! $this->presetIsAllowed($store->id, $preset)) {
            abort(403, ___('This template is not available on your plan.'));
        }

        // Applying over a page that already has blocks needs explicit replace=1.
        if (! $replace && $this->realBlockCount($store->id) > 0) {
            return $this->reject($request, ___('This page already has blocks. Turn on “Replace existing blocks” to apply a template.'));
        }

        $created = $this->svc()->applyStarter($store->id, $preset, $replace);

        return $this->ok($request, ___('Template applied.'), [
            'created' => $created,
            'redirect' => route('biolink.builder', $this->branchQuery($request, $store->id)),
        ]);
    }

    /**
     * Change ONLY the design (preset) — keeps every block and other setting. Powers
     * the builder's live design switcher (preview → apply). Tenant-scoped; a
     * plan-locked preset is 403 (mirrors applyTemplate). Never touches blocks,
     * enabled or mode.
     */
    public function setPreset(Request $request): JsonResponse|RedirectResponse
    {
        $store = $this->currentRestaurant($request);

        $v = $request->validate(['preset' => ['required', 'string', 'max:64']]);
        $preset = $v['preset'];

        if (! $this->presetIsAllowed($store->id, $preset)) {
            abort(403, ___('This design is not available on your plan.'));
        }

        // Partial save: the service merges onto stored settings, so only `preset`
        // changes — blocks, enabled, mode and sections are untouched.
        $this->svc()->saveSettings($store->id, ['preset' => $preset]);

        return $this->ok($request, ___('Design applied.'), [
            'preset' => $preset,
            'redirect' => route('biolink.builder', $this->branchQuery($request, $store->id)),
        ]);
    }

    /** presetAllowed() with a safe default (basic presets allowed on any error). */
    private function presetIsAllowed(int $storeId, string $preset): bool
    {
        try {
            return (bool) $this->svc()->presetAllowed($storeId, $preset);
        } catch (\Throwable $e) {
            return in_array($preset, BioLinkService::BASIC_PRESETS, true);
        }
    }

    /** bioProAllowed() with a safe (fail-closed — PRO stays locked) default on any error. */
    private function bioProAllowed(int $storeId): bool
    {
        try {
            return (bool) $this->svc()->bioProAllowed($storeId);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Count of REAL main-page blocks (editorBlocks — never the legacy-synth read). */
    private function realBlockCount(int $storeId): int
    {
        try {
            return count((array) $this->svc()->editorBlocks($storeId, null));
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Preserve the current branch scope when redirecting back to the builder.
     *
     * @return array<string,int>
     */
    private function branchQuery(Request $request, int $storeId): array
    {
        $branchId = $this->resolveBranchId($request, $storeId);

        return $branchId ? ['branch' => $branchId] : [];
    }

    /* ─────────────────────────── internals ────────────────────────────────── */

    /** Owner (never the staff member) — the entitlement + limit subject. */
    private function owner(): ?User
    {
        return function_exists('dashboard_owner') ? dashboard_owner() : auth()->user();
    }

    /**
     * Resolved settings merged onto defaults so views never touch an undefined key.
     *
     * @return array<string, mixed>
     */
    private function settings(int $storeId): array
    {
        $defaults = [
            'enabled' => false,
            'preset' => 'classic_flat',
            'mode' => 'menu_first',
            'sections' => array_fill_keys(self::SECTIONS, true),
            'branch_pages' => false,
        ];

        $resolved = [];
        try {
            $resolved = (array) $this->svc()->resolveSettings($storeId);
        } catch (\Throwable $e) {
            $resolved = [];
        }

        $merged = array_merge($defaults, $resolved);
        $merged['sections'] = array_merge($defaults['sections'], (array) ($resolved['sections'] ?? []));

        return $merged;
    }

    /**
     * statsFor() output merged onto a zeroed skeleton so the page renders empty-clean.
     *
     * @return array<string, mixed>
     */
    private function statsWithDefaults(int $storeId, string $range): array
    {
        $defaults = [
            'views' => 0, 'clicks' => 0, 'ctr' => 0.0, 'qr_scans' => 0,
            'per_block' => [], 'sources' => [], 'devices' => [],
        ];

        try {
            $stats = (array) $this->svc()->statsFor($storeId, $range);
        } catch (\Throwable $e) {
            $stats = [];
        }

        return array_merge($defaults, $stats);
    }

    /**
     * Preset catalog split into allowed vs tier-locked for the picker.
     *
     * @return array<int, array<string, mixed>>
     */
    private function presetGroups(int $storeId): array
    {
        $presets = [];
        try {
            $presets = (array) $this->svc()->presets();
        } catch (\Throwable $e) {
            $presets = [];
        }

        foreach ($presets as &$p) {
            $slug = (string) ($p['slug'] ?? '');
            try {
                $p['allowed'] = $slug !== '' ? (bool) $this->svc()->presetAllowed($storeId, $slug) : false;
            } catch (\Throwable $e) {
                $p['allowed'] = ($p['tier'] ?? 'basic') === 'basic';
            }
        }

        return $presets;
    }

    /**
     * C1 · curated starter cards for the empty-state chooser. Each card carries a
     * representative preset (with its name/thumb + tier-entitlement) plus the
     * archetype it seeds; a card whose preset is locked renders an upsell rather
     * than a dead button.
     *
     * @return array<int, array{archetype:string, preset:string, icon:string, name:string, allowed:bool}>
     */
    private function starterCards(int $storeId): array
    {
        $catalog = collect($this->presetGroups($storeId))->keyBy('slug');

        $cards = [];
        foreach (self::STARTER_CARDS as $card) {
            $slug = $card['preset'];
            $meta = $catalog->get($slug, []);
            $cards[] = [
                'archetype' => $card['archetype'],
                'preset' => $slug,
                'icon' => $card['icon'],
                'name' => (string) ($meta['name'] ?? ucwords(str_replace('_', ' ', $slug))),
                'allowed' => (bool) ($meta['allowed'] ?? false),
            ];
        }

        return $cards;
    }

    /**
     * C2/C4 · is the `ai_biolink` feature live for this store's owner?
     *
     * Dark by default: the key must be a SHIPPED registry entry (Lane B's B1
     * migration/registration) AND pass the kill-switch + plan gate. The registry
     * check is belt-and-suspenders against the R0-3 fail-open (an unknown key
     * would otherwise read as enabled) — so the AI surface stays absent in any
     * tree/prod where the key has not shipped yet.
     */
    private function aiEnabled(?User $owner): bool
    {
        try {
            if (! array_key_exists('ai_biolink', FeatureRegistry::keyed())) {
                return false;
            }

            return FeatureAccess::allows($owner, 'ai_biolink');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * C5 · the owner's OTHER stores — the eligible copy-to targets. Scoped to the
     * dashboard restaurant set (never all stores), so the picker can only ever
     * list stores the owner controls.
     *
     * @return array<int, array{id:int, title:string}>
     */
    private function copyTargets(int $currentPostId): array
    {
        $out = [];
        foreach (dashboard_stores() as $store) {
            if ((int) $store->id === $currentPostId) {
                continue;
            }
            $out[] = ['id' => (int) $store->id, 'title' => (string) ($store->title ?? '#'.$store->id)];
        }

        return $out;
    }

    /** A route's URL when it is registered, else null (cross-lane seam degradation). */
    private function routeUrlIfExists(string $name): ?string
    {
        try {
            return Route::has($name) ? route($name) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * C3 · the store's menu items grouped by category for the block-editor picker.
     * Shape: ordered categories, each with its active items ({id, name}). An
     * "uncategorised" bucket catches items with no/again-missing category so the
     * picker never silently drops an item.
     *
     * @return array<int, array{label:string, items:array<int, array{id:int, name:string}>}>
     */
    private function menuGroups(int $storeId): array
    {
        try {
            $categories = MenuCategory::where('store_id', $storeId)
                ->orderBy('position')->orderBy('id')->get(['id', 'name']);

            $items = Menu::where('store_id', $storeId)
                ->where('active', '1')
                ->orderBy('position')->orderBy('id')->get(['id', 'name', 'category_id']);
        } catch (\Throwable $e) {
            return [];
        }

        $byCategory = $items->groupBy('category_id');
        $groups = [];

        foreach ($categories as $cat) {
            $bucket = $byCategory->get($cat->id);
            if (! $bucket || $bucket->isEmpty()) {
                continue;
            }
            $groups[] = [
                'label' => (string) ($cat->name ?? ''),
                'items' => $bucket->map(fn ($m) => ['id' => (int) $m->id, 'name' => (string) ($m->name ?? '#'.$m->id)])->values()->all(),
            ];
        }

        // Items whose category is missing / not the store's own.
        $known = $categories->pluck('id')->all();
        $orphans = $items->reject(fn ($m) => in_array((int) $m->category_id, array_map('intval', $known), true));
        if ($orphans->isNotEmpty()) {
            $groups[] = [
                'label' => ___('Other items'),
                'items' => $orphans->map(fn ($m) => ['id' => (int) $m->id, 'name' => (string) ($m->name ?? '#'.$m->id)])->values()->all(),
            ];
        }

        return $groups;
    }

    private function blockCount(int $storeId): int
    {
        try {
            return count((array) $this->svc()->blocksFor($storeId, null, true));
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Enabled owner-content locales for the per-locale editor (B8); [] hides the switcher.
     *
     * @return array<int, string>
     */
    private function enabledLocales(int $storeId): array
    {
        try {
            return array_values((array) $this->svc()->enabledLocales($storeId));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * The default content locale — the one whose strings live in base `label`/`config`;
     * every OTHER enabled locale is an override under `config._i18n` (SPEC §3).
     */
    private function defaultLocale(): string
    {
        try {
            if (class_exists(LaravelLocalization::class)) {
                return (string) LaravelLocalization::getDefaultLocale();
            }
        } catch (\Throwable $e) {
            // fall through
        }

        return (string) config('app.locale', 'en');
    }

    /**
     * RAW block rows for the builder/editor: config still carries `_i18n` and text keys
     * are NOT locale-resolved, so the per-locale editor can round-trip overrides.
     * Degrades to blocksFor() (render contract) until Lane A ships editorBlocks().
     *
     * @return array<int, array<string, mixed>>
     */
    private function editorBlocks(int $storeId, ?int $branchId): array
    {
        try {
            return (array) $this->svc()->editorBlocks($storeId, $branchId);
        } catch (\Throwable $e) {
            try {
                return (array) $this->svc()->blocksFor($storeId, $branchId, true);
            } catch (\Throwable $e2) {
                return [];
            }
        }
    }

    /** The current branch scope for builder/preview: `?branch=ID` when a real child, else null (store). */
    private function resolveBranchId(Request $request, int $storeId): ?int
    {
        $branchId = (int) $request->query('branch', 0);
        if ($branchId <= 0) {
            return null;
        }

        $owned = Branch::where('store_id', $storeId)->where('id', $branchId)->exists();

        return $owned ? $branchId : null;
    }

    private function bioUrl(Store $store): string
    {
        return url('/'.$store->slug.'/bio');
    }

    private function qrUrl(Store $store): string
    {
        try {
            return store_public_qr_url($store, null, 'bio');
        } catch (\Throwable $e) {
            return $this->bioUrl($store);
        }
    }

    /** Signed preview URL for the live-preview iframe (bypasses enabled + view recording, Lane A). */
    private function previewUrl(Store $store, ?int $branchId): string
    {
        $params = ['slug' => $store->slug, 'preview' => 1];
        if ($branchId) {
            $params['branch'] = $branchId;
        }

        try {
            if (Route::has('bio.public')) {
                return URL::signedRoute('bio.public', $params);
            }
        } catch (\Throwable $e) {
            // fall through to the plain URL
        }

        // Lane A's public route may not exist yet in this tree — degrade to the
        // plain bio URL so the builder still renders.
        return $this->bioUrl($store).($branchId ? '?branch='.$branchId : '');
    }

    /**
     * Validate a block payload. `label`, `url` and the typed `config` map are the
     * only writable fields; the per-locale `_i18n` overrides ride inside config
     * (SPEC §3) and are whitelisted here.
     *
     * @return array{0: array<string,mixed>, 1: int|null}
     */
    private function validatedBlock(Request $request): array
    {
        $v = $request->validate([
            'type' => ['required', 'in:'.implode(',', self::BLOCK_TYPES)],
            'label' => ['nullable', 'string', 'max:191'],
            'url' => ['nullable', 'string', 'max:2000'],
            'active' => ['nullable'],
            'visible_from' => ['nullable', 'date'],
            'visible_until' => ['nullable', 'date', 'after_or_equal:visible_from'],
            'branch_id' => ['nullable', 'integer'],
            'config' => ['nullable', 'array'],
            'i18n' => ['nullable', 'array'],
        ]);

        $store = $this->currentRestaurant($request);
        $branchId = null;
        if (! empty($v['branch_id'])) {
            $branchId = Branch::where('store_id', $store->id)->where('id', (int) $v['branch_id'])->value('id');
            $branchId = $branchId ? (int) $branchId : null;
        }

        $config = $this->cleanConfig((array) ($v['config'] ?? []));

        // delivery_apps / delivery / socials are edited as "Name | URL" lines and
        // normalised to the render contract Lane C expects ({apps:[{name,url}]} /
        // {socials:[…]}). bio-t15 A5: `delivery` (SPEC §2 A5) is the new, richer
        // grouped-ordering block — same pairs textarea idiom as the legacy
        // `delivery_apps`, kept as a separate type (see LEDGER "Contract changes").
        $pairs = $this->parsePairs((string) $request->input('config_pairs', ''));
        if ($pairs) {
            if ($v['type'] === 'delivery_apps' || $v['type'] === 'delivery') {
                $config['apps'] = $pairs;
            } elseif ($v['type'] === 'socials') {
                $config['socials'] = $pairs;
            } elseif ($v['type'] === 'faq') {
                // bio-t15 A9: same "left | right" pairs textarea idiom, remapped
                // {name,url} → {q,a} (SPEC §2 A9).
                $config['items'] = array_map(
                    static fn (array $p) => ['q' => $p['name'], 'a' => $p['url']],
                    $pairs
                );
            } elseif ($v['type'] === 'gallery') {
                // bio-t15 gap-fix: `gallery` uses the SAME "Caption | Image URL" pairs
                // textarea (builder listKeyMap.gallery = 'images'), remapped to the
                // render contract {images:[{image,caption}]} (SPEC §2 A8). Without this
                // branch the block could never persist a photo through the shipped UI.
                $config['images'] = array_map(
                    static fn (array $p) => ['image' => $p['url'], 'caption' => $p['name']],
                    $pairs
                );
            }
        }

        // Per-locale overrides → config._i18n (§3). Keep only real locale codes with
        // non-empty field values; the base label/config carry the default locale.
        $i18n = [];
        foreach ((array) ($v['i18n'] ?? []) as $code => $fields) {
            if (! preg_match('/^[a-z]{2,3}(-[a-z]{2})?$/i', (string) $code) || ! is_array($fields)) {
                continue;
            }
            $clean = [];
            foreach ($fields as $field => $text) {
                $text = trim((string) $text);
                if ($text !== '' && in_array($field, ['label', 'text', 'title', 'subtitle', 'schedule_label', 'price_label'], true)) {
                    $clean[$field] = $text;
                }
            }
            if ($clean) {
                $i18n[strtolower((string) $code)] = $clean;
            }
        }
        if ($i18n) {
            $config['_i18n'] = $i18n;
        }

        $data = [
            'type' => $v['type'],
            'label' => $v['label'] ?? null,
            'url' => $this->sanitizeUrl((string) ($v['url'] ?? '')) ?: null,
            'config' => $config,
            'active' => $request->boolean('active', true),
            'visible_from' => $v['visible_from'] ?? null,
            'visible_until' => $v['visible_until'] ?? null,
        ];

        return [$data, $branchId];
    }

    /**
     * Drop empty/blank config entries; leave the shape otherwise untouched for the service.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function cleanConfig(array $config): array
    {
        unset($config['_i18n']); // rebuilt from the `i18n` field, never trusted from config[]

        // Scheme allow-list on every URL-shaped config value (gap-fix): the blocks
        // that carry a link in config (reservation/giftcard/loyalty/reviews/event/
        // tipjar) must never persist a `javascript:`/`data:` href to the PUBLIC page.
        if (array_key_exists('url', $config)) {
            $config['url'] = $this->sanitizeUrl((string) $config['url']);
        }

        // bio-t15 A12: `schedule` is a cross-cutting addition on ANY block type
        // (SPEC §2 A12) — sanitized generically here rather than per-type.
        if (array_key_exists('schedule', $config)) {
            $sched = $this->sanitizeSchedule(is_array($config['schedule']) ? $config['schedule'] : []);
            if ($sched !== null) {
                $config['schedule'] = $sched;
            } else {
                unset($config['schedule']);
            }
        }

        // bio-t15 A13: `pinned`/`highlight` are cross-cutting too (SPEC §2 A13) —
        // an invalid `highlight` falls back to 'none' rather than being stored raw.
        if (array_key_exists('pinned', $config)) {
            $config['pinned'] = filter_var($config['pinned'], FILTER_VALIDATE_BOOLEAN);
        }
        if (array_key_exists('highlight', $config)) {
            $config['highlight'] = in_array($config['highlight'], ['pulse', 'glow'], true) ? $config['highlight'] : 'none';
        }

        return array_filter($config, static fn ($val) => $val !== null && $val !== '');
    }

    /**
     * Whitelist a `config.schedule` payload (SPEC §2 A12): `start_at`/`end_at`
     * accept either a date(time) string or a bare "HH:MM" (validated loosely here
     * — BioLinkService::configScheduleVisible() is defensive against anything
     * that slips through and never crashes rendering on a malformed bound);
     * `days` keeps only integers 0–6, deduplicated. An entirely empty/invalid
     * payload returns null so the caller drops the key rather than storing a
     * no-op `{}`.
     *
     * @param  array<string,mixed>  $raw
     * @return array{start_at?:string,end_at?:string,days?:array<int,int>}|null
     */
    private function sanitizeSchedule(array $raw): ?array
    {
        $isDateish = static fn ($v) => is_string($v) && $v !== '' && preg_match(
            '/^([01]\d|2[0-3]):([0-5]\d)$|^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?)?$/',
            $v
        ) === 1;

        $out = [];
        foreach (['start_at', 'end_at'] as $key) {
            if ($isDateish($raw[$key] ?? null)) {
                $out[$key] = $raw[$key];
            }
        }

        if (is_array($raw['days'] ?? null)) {
            $days = array_values(array_unique(array_map(
                'intval',
                array_filter($raw['days'], static fn ($d) => is_numeric($d) && (int) $d >= 0 && (int) $d <= 6)
            )));
            if ($days !== []) {
                sort($days);
                $out['days'] = $days;
            }
        }

        return $out !== [] ? $out : null;
    }

    /**
     * Scheme allow-list for any owner-supplied URL that ends up in a public `href`.
     * Returns '' for anything that isn't an http(s)/tel/mailto/sms link or a
     * site-relative path — crucially rejecting `javascript:`/`data:`/`vbscript:`
     * (stored-XSS on the PUBLIC bio page; Blade's {{ }} escapes HTML but NOT the URL
     * scheme, and the bio CSP ships report-only until the owner enforces it). Control
     * chars are stripped before the scheme test so `java\tscript:` cannot slip through.
     */
    private function sanitizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        // Browsers ignore C0 controls/whitespace inside a scheme; strip them for the probe.
        $probe = strtolower((string) preg_replace('/[\x00-\x20]+/', '', $url));
        if (preg_match('/^([a-z][a-z0-9+.\-]*):/', $probe, $m)) {
            if (! in_array($m[1], ['http', 'https', 'tel', 'mailto', 'sms'], true)) {
                return '';
            }
        } elseif (str_starts_with($probe, '//')) {
            // protocol-relative (//evil.tld) — reject; a real link carries its scheme.
            return '';
        }

        return $url;
    }

    /**
     * Parse a "Name | URL" (one per line) textarea into an ordered list of
     * {name,url} rows, dropping blank/urlless lines. Used by delivery_apps + socials.
     *
     * @return array<int, array{name:string, url:string}>
     */
    private function parsePairs(string $raw): array
    {
        $rows = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line, 2));
            $name = $parts[0];
            // Scheme allow-list (gap-fix): a "Name | javascript:…" pair must not
            // survive to a public href. sanitizeUrl() returns '' for a bad scheme.
            $url = $this->sanitizeUrl($parts[1] ?? '');
            if ($url === '') {
                continue;
            }
            $rows[] = ['name' => mb_substr($name, 0, 60), 'url' => mb_substr($url, 0, 2000)];
        }

        return $rows;
    }

    /* JSON-or-redirect response helpers (the builder posts via fetch; forms fall back). */

    /**
     * @param  array<string, mixed>  $extra
     */
    private function ok(Request $request, string $message, array $extra = []): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(array_merge(['success' => true, 'message' => $message], $extra));
        }

        return back()->with('success', $message);
    }

    private function reject(Request $request, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['success' => false, 'message' => $message], 422);
        }

        return back()->with('error', $message);
    }
}
