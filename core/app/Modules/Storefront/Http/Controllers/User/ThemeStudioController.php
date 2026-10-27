<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Http\Controllers\User;

use App\Http\Controllers\User\StoreController;
use App\Models\StoreOption;
use App\Models\Theme;
use App\Services\PlatformMetrics;
use App\Services\Storefront\StorefrontMenuCache;
use App\Services\ThemeCustomizerService;
use App\Services\ThemePurchaseService;
use App\Services\Verticals\VerticalRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Modules\Ai\Contracts\AiLimitExceeded;
use Juwri\Modules\Ai\Contracts\AiProviderChain;
use Juwri\Modules\Ai\Contracts\Translator;
use Juwri\Modules\Media\Models\MediaAsset;
use Juwri\Modules\Storefront\Models\ThemeSetting;
use Juwri\Modules\Storefront\Models\ThemeSettingVersion;
use Juwri\Modules\Storefront\Services\ThemeStudio\LayoutDocument;
use Juwri\Modules\Storefront\Services\ThemeStudio\LayoutMerge;
use Juwri\Modules\Storefront\Services\ThemeStudio\StoreLayouts;
use Juwri\Modules\Storefront\Services\ThemeStudio\StudioCaps;
use Juwri\Modules\Storefront\Storefront\MarketplaceTheme;
use Juwri\Modules\Storefront\Storefront\MarketplaceThemeResolver;
use Juwri\Modules\Storefront\Storefront\SectionedTemplate;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Theme Studio C1: the visual editor's backend (rev/theme-studio/EDITOR-CONTRACT.md).
 *
 * Every endpoint is scoped to ONE store named by `?store=<store_id>` (default:
 * the owner's current dashboard store) that the signed-in user owns — any other
 * id is a 404. Layout writes go through LayoutMerge only (caps + limits +
 * store-scoped validation); publishing / discarding / history reuse
 * ThemeCustomizerService, which carries the layout alongside the settings.
 * Routes: `auth` + `csp.dashboard` + `feature:theme_studio` (owner dashboard group).
 */
class ThemeStudioController extends Controller
{
    /** Post option holding a studio theme choice that is not live yet. */
    public const PENDING_OPTION = 'studio_pending_template';

    /** Contract: image uploads up to 5 MB. */
    protected const MEDIA_MAX_KB = 5120;

    /**
     * The recipes service from the parallel B2 lane (`find($id)`, `layout($id)`,
     * optional `all()`), coded against by name so this lane works before it lands.
     */
    protected const RECIPES_CLASS = 'Juwri\\Modules\\Storefront\\Services\\ThemeStudio\\Recipes';

    /** GET /dashboard/theme-studio — the editor page (C2 view). */
    public function page(Request $request): View
    {
        $store = $this->store($request);
        $theme = $this->studioTheme($store);
        $caps = StudioCaps::forStore($store);

        $studio = [
            'store' => [
                'id' => (int) $store->id,
                'slug' => (string) $store->slug,
                'title' => (string) $store->title,
                'store_type' => (string) ($store->store_type ?? 'restaurant'),
                'languages' => $this->languages($store),
                'default_language' => (string) (store_options($store->id)->default_language ?? config('app.locale')),
            ],
            'stores' => Store::where('user_id', auth()->id())->orderBy('id')->get(['id', 'title'])
                ->map(fn (Store $p) => ['id' => (int) $p->id, 'title' => (string) $p->title])->values()->all(),
            'theme' => $theme ? $this->themeInfo($theme) : null,
            'themes' => array_values(array_map(
                fn (array $t) => array_intersect_key($t, array_flip(['slug', 'name', 'vertical_groups', 'thumb'])),
                $this->studioThemes()
            )),
            'recipes' => $this->recipeList(),
            'caps' => $caps,
            // C4: "Translate to other languages" (not a Studio cap — the AI seam decides).
            'translate' => $theme !== null && $this->canTranslate($store),
            // Publishing switches the live theme when a studio choice is pending.
            'pending_theme' => $theme !== null && self::pendingTemplate($store) !== '' ? $this->themeInfo($theme)['name'] : null,
            'limits' => ['max_sections' => SectionedTemplate::MAX_SECTIONS, 'max_blocks' => SectionedTemplate::MAX_BLOCKS],
            'urls' => $this->urls($store) + ($caps['ai_generate'] ? $this->aiUrls($store) : []),
            'csrf' => csrf_token(),
            'i18n' => $this->i18n(),
        ];

        return view('owner.design.studio', ['studio' => $studio]);
    }

    /** GET state — {layout, published_layout, has_draft, library, tokens, published_at}. */
    public function state(Request $request): JsonResponse
    {
        $store = $this->store($request);
        $theme = $this->requireStudioTheme($store);

        // C5: a theme / library update may have removed or renamed section code.
        try {
            StoreLayouts::migrate($store, $theme);
        } catch (\Throwable $e) {
            report($e);
        }

        $layout = StoreLayouts::editing((int) $store->id, $theme);

        return response()->json([
            'layout' => $layout,
            'published_layout' => StoreLayouts::published((int) $store->id, $theme),
            'has_draft' => StoreLayouts::hasDraft((int) $store->id, $theme),
            'library' => $this->library($theme, $layout),
            'tokens' => $this->tokens($store, $theme),
            'published_at' => ThemeSettingVersion::where('store_id', $store->id)
                ->where('template', StoreLayouts::key($theme))
                ->orderByDesc('id')->first()?->published_at?->toIso8601String(),
            'notices' => $this->notices($store),
        ]);
    }

    /** PUT draft — {layout} → LayoutMerge → stored draft. */
    public function draft(Request $request): JsonResponse
    {
        $store = $this->store($request);
        $theme = $this->requireStudioTheme($store);

        // C5: the editor's notices banner was dismissed.
        if ($request->boolean('dismiss_notices')) {
            StoreLayouts::dismissNotices($store);
        }

        $incoming = $request->input('layout');
        if (! is_array($incoming)) {
            return $this->invalid(['layout' => 'A layout document is required.']);
        }

        try {
            $merged = LayoutMerge::merge(
                StoreLayouts::editing((int) $store->id, $theme),
                $incoming,
                $theme,
                $store,
                StudioCaps::forStore($store),
            );
            StoreLayouts::saveDraft($store, $theme, $merged['layout']);
        } catch (ValidationException $e) {
            return $this->invalid(array_map(fn ($m) => is_array($m) ? (string) ($m[0] ?? '') : (string) $m, $e->errors()));
        }

        return response()->json([
            'layout' => StoreLayouts::editing((int) $store->id, $theme),
            'has_draft' => true,
            'rejected' => $merged['rejected'],
        ]);
    }

    /**
     * GET section/{sid} — one section of the draft rendered in studio mode (an
     * HTML fragment). Rendered by the storefront's own Liquid branch (same
     * data, same owner-preview gate), asked for exactly one section.
     */
    public function section(Request $request, string $sid): SymfonyResponse
    {
        $store = $this->store($request);
        $this->requireStudioTheme($store);
        abort_unless((bool) preg_match(LayoutDocument::ID_PATTERN, $sid), 404);

        $query = ['preview_theme' => '1', 'studio' => '1', 'studio_section' => $sid];
        $lang = (string) $request->query('lang', '');
        if ($lang !== '' && preg_match('/^[a-z]{2}(-[a-z0-9]{2,8})?$/i', $lang)) {
            $query['preview_lang'] = $lang;
        }
        $request->query->add($query);

        $response = app(StoreController::class)->publicView($store->slug);
        if (! $response instanceof SymfonyResponse || $response->headers->get('X-Studio-Fragment') !== '1') {
            return response()->json(['message' => ___('The preview could not be rendered.')], 409);
        }

        return $response;
    }

    /**
     * POST translate — C4 "Translate to other languages": fills the missing
     * `i18n.<lang>` text of every other menu language from the default-language
     * text via TranslatorService (AI; metered as `studio_translate`, counts
     * against the owner's AI calls). Existing translations are kept unless
     * `overwrite: true`. The result goes through LayoutMerge like any edit.
     */
    public function translate(Request $request): JsonResponse
    {
        $store = $this->store($request);
        $theme = $this->requireStudioTheme($store);

        if (! $this->canTranslate($store)) {
            return response()->json(['message' => ___('Automatic translation is not available for this store.')], 403);
        }

        $langs = StoreLayouts::languages($store);
        $targets = array_values(array_filter($langs['codes'], fn ($c) => $c !== $langs['default']));
        if ($targets === []) {
            return $this->invalid(['languages' => ___('Add another menu language first.')]);
        }

        $overwrite = $request->boolean('overwrite');
        $layout = StoreLayouts::editing((int) $store->id, $theme);
        $texts = LayoutDocument::translatableTexts($theme, $layout);
        $names = Language::whereIn('code', $langs['codes'])->pluck('name', 'code')->all();
        $translator = app(Translator::class);
        $ownerId = (int) (auth()->id() ?? $store->user_id);

        $count = 0;
        try {
            foreach ($targets as $lang) {
                $existing = is_array($layout['i18n'][$lang] ?? null) ? $layout['i18n'][$lang] : [];
                $records = [];
                foreach ($texts as $path => $entry) {
                    if ($overwrite || ! isset($existing[$path])) {
                        $records[$path] = ['v' => $entry['text']];
                    }
                }
                if ($records === []) {
                    continue;
                }
                $out = $translator->translateBatch($records, (string) ($names[$lang] ?? $lang), [
                    'sourceLang' => (string) ($names[$langs['default']] ?? $langs['default']),
                    'userId' => $ownerId,
                    'storeId' => (int) $store->id,
                    'feature' => 'studio_translate',
                ]);
                foreach ($records as $path => $record) {
                    $value = LayoutDocument::cleanText($texts[$path]['def'], $out[$path]['v'] ?? null);
                    if ($value !== null) {
                        $layout['i18n'][$lang][$path] = $value;
                        $count++;
                    }
                }
            }
        } catch (AiLimitExceeded $e) {
            return response()->json(['message' => ___('You have used all AI requests included in your plan this month.')], 429);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => ___('Translation failed. Please try again.')], 503);
        }

        try {
            $merged = LayoutMerge::merge(
                StoreLayouts::editing((int) $store->id, $theme),
                $layout,
                $theme,
                $store,
                StudioCaps::forStore($store),
            );
            StoreLayouts::saveDraft($store, $theme, $merged['layout']);
        } catch (ValidationException $e) {
            return $this->invalid(array_map(fn ($m) => is_array($m) ? (string) ($m[0] ?? '') : (string) $m, $e->errors()));
        }
        PlatformMetrics::record('studio.translate', $theme->slug);

        return response()->json([
            'layout' => StoreLayouts::editing((int) $store->id, $theme),
            'has_draft' => true,
            'translated' => $count,
        ]);
    }

    /**
     * C4: is "Translate to other languages" offered? The store needs a second
     * menu language and a working AI provider (platform key or the store's own
     * BYOK key) — the same seam menu translation runs through; the monthly AI
     * call allowance is enforced per call by AiService.
     */
    protected function canTranslate(Store $store): bool
    {
        if (count(StoreLayouts::languages($store)['codes']) < 2) {
            return false;
        }

        try {
            return app(AiProviderChain::class)->chain((int) $store->id) !== [];
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * C5: pending owner notices (layout migrations after a theme update), with
     * a readable message each.
     *
     * @return list<array<string,string>>
     */
    protected function notices(Store $store): array
    {
        $out = [];
        foreach (StoreLayouts::notices($store) as $notice) {
            $name = ucwords(str_replace(['-', '_'], ' ', (string) ($notice['type'] ?? '')));
            $message = ($notice['kind'] ?? '') === 'block_removed'
                ? ___('A “:name” block is no longer part of your theme and was removed from your layout.', ['name' => $name])
                : ___('The “:name” section is no longer part of your theme and was removed from your layout.', ['name' => $name]);
            $out[] = ['id' => (string) $notice['id'], 'kind' => (string) ($notice['kind'] ?? ''), 'message' => $message];
        }

        return $out;
    }

    /** POST publish — draft layout + customizer draft go live; storefront caches busted. */
    public function publish(Request $request): JsonResponse
    {
        $store = $this->store($request);
        $theme = $this->requireStudioTheme($store);

        ThemeCustomizerService::publish($store, $this->template($theme));
        // A theme chosen in the studio goes live only now.
        if (self::pendingTemplate($store) !== '') {
            StoreOption::updateStoreOption($store->id, 'store_template', $this->template($theme));
            StoreOption::updateStoreOption($store->id, self::PENDING_OPTION, '');
        }
        StorefrontMenuCache::bust((int) $store->id); // menu cache + full-page cache (token-keyed)
        PlatformMetrics::record('studio.publish', $theme->slug);

        $version = ThemeSettingVersion::where('store_id', $store->id)
            ->where('template', StoreLayouts::key($theme))
            ->orderByDesc('id')->value('id');

        return response()->json(['ok' => true, 'version_id' => $version ? (int) $version : null]);
    }

    /** POST discard — drop the draft (layout + customizer values). */
    public function discard(Request $request): JsonResponse
    {
        $store = $this->store($request);
        $theme = $this->requireStudioTheme($store);

        ThemeCustomizerService::discardDraft($store, $this->template($theme));
        // Discarding a not-yet-published theme choice returns to the live theme.
        if (self::pendingTemplate($store) !== '') {
            StoreOption::updateStoreOption($store->id, self::PENDING_OPTION, '');

            return response()->json(['ok' => true, 'layout' => null, 'redirect' => route('theme.studio', ['store' => $store->id])]);
        }

        return response()->json(['ok' => true, 'layout' => StoreLayouts::published((int) $store->id, $theme)]);
    }

    /** GET versions — published history, newest first. */
    public function versions(Request $request): JsonResponse
    {
        $store = $this->store($request);
        $theme = $this->requireStudioTheme($store);

        return response()->json(['versions' => ThemeCustomizerService::versionHistory($store, $this->template($theme))]);
    }

    /** POST revert/{id} — a past version becomes live (as a new version). */
    public function revert(Request $request, string $id): JsonResponse
    {
        $store = $this->store($request);
        $theme = $this->requireStudioTheme($store);
        abort_unless(ctype_digit($id), 404);

        $ts = ThemeCustomizerService::revertToVersion($store, $this->template($theme), (int) $id);
        abort_unless($ts !== null, 404);
        StorefrontMenuCache::bust((int) $store->id);

        return response()->json(['ok' => true, 'layout' => StoreLayouts::published((int) $store->id, $theme)]);
    }

    /** POST media — one image into the store's media library (storage quota enforced). */
    public function media(Request $request): JsonResponse
    {
        $store = $this->store($request);

        $validator = validator($request->all(), [
            'file' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MEDIA_MAX_KB],
        ]);
        if ($validator->fails()) {
            return $this->invalid(array_map(fn ($m) => (string) $m[0], $validator->errors()->toArray()));
        }

        $file = $request->file('file');
        // Same path as MediaController@store: StorageService hardens the file and
        // enforces the per-tenant storage quota ('' = refused).
        $filename = image_upload($file, 'storage/menu/', '1200', null, null, (int) $store->id);
        if ($filename === '') {
            return $this->invalid(['file' => ___('The image could not be stored. Your storage may be full.')]);
        }

        $asset = MediaAsset::create([
            'store_id' => (int) $store->id,
            'filename' => $filename,
            'original_name' => (string) $file->getClientOriginalName(),
            'folder' => 'theme-studio',
            'size_bytes' => (int) $file->getSize(),
        ]);

        return response()->json(['filename' => (string) $asset->filename, 'url' => (string) $asset->url]);
    }

    /** POST start — {theme_slug} or {recipe}: apply a Studio theme and seed the draft layout. */
    public function start(Request $request): JsonResponse
    {
        $store = $this->store($request);

        $themeSlug = $request->input('theme_slug');
        $recipeId = $request->input('recipe');
        if (! is_string($themeSlug) && ! is_string($recipeId)) {
            return $this->invalid(['theme_slug' => ___('Choose a theme or a starting recipe.')]);
        }

        $seed = null;
        if (is_string($recipeId) && $recipeId !== '') {
            $recipes = $this->recipes();
            $recipe = $recipes?->find($recipeId);
            $seed = $recipe ? $recipes->layout($recipeId) : null;
            if (! is_array($recipe) || ! is_array($seed)) {
                return $this->invalid(['recipe' => ___('That recipe does not exist.')]);
            }
            if (! is_string($themeSlug) || $themeSlug === '') {
                $themeSlug = $this->recipeThemeSlug($recipe, $store);
            }
        }

        $candidate = is_string($themeSlug) ? ($this->studioThemes()[$themeSlug] ?? null) : null;
        if ($candidate === null) {
            return $this->invalid(['theme_slug' => ___('That theme is not available in Theme Studio.')]);
        }
        if ($candidate['row'] instanceof Theme && ! ThemePurchaseService::entitled(auth()->user(), $candidate['row'])) {
            return response()->json(['message' => ___('Buy this theme in the marketplace first.')], 402);
        }
        /** @var MarketplaceTheme $theme */
        $theme = $candidate['theme'];

        // Seed BEFORE switching the live template, so a recipe that does not fit
        // this theme leaves the storefront exactly as it was.
        try {
            if ($seed !== null) {
                StoreLayouts::saveDraft($store, $theme, $seed);
            } elseif (StoreLayouts::forStore((int) $store->id, $theme, true) === null) {
                StoreLayouts::saveDraft($store, $theme, StoreLayouts::themeDefault($theme));
            }
        } catch (ValidationException $e) {
            return $this->invalid(array_map(fn ($m) => is_array($m) ? (string) ($m[0] ?? '') : (string) $m, $e->errors()));
        }

        // The live storefront does NOT change yet: the chosen theme is PENDING
        // (owner preview + editor use it) until the owner publishes — exactly what
        // the confirmation promised ("customers see your current design until you
        // publish"). A store already on this theme has nothing pending.
        $template = $this->template($theme);
        $live = (string) (store_options($store->id)->store_template ?? '');
        StoreOption::updateStoreOption($store->id, self::PENDING_OPTION, $live === $template ? '' : $template);

        return response()->json(['ok' => true, 'redirect' => route('theme.studio', ['store' => $store->id])]);
    }

    // ---------------------------------------------------------------- helpers

    /** The owner's store named by ?store= (or their current/first one); anything else 404s. */
    protected function store(Request $request): Store
    {
        $id = (int) $request->query('store', 0);
        if ($id <= 0) {
            $current = dashboard_current_store();
            $id = $current && (int) $current->user_id === (int) auth()->id()
                ? (int) $current->id
                : (int) Store::where('user_id', auth()->id())->orderBy('id')->value('id');
        }

        $store = $id > 0 ? Store::where('id', $id)->where('user_id', auth()->id())->first() : null;
        abort_unless($store !== null, 404);

        return $store;
    }

    /**
     * A theme picked in the studio but not published yet (post option; '' = none).
     * The owner's preview and the editor use it; the live storefront does not.
     */
    public static function pendingTemplate(Store $store): string
    {
        return (string) (store_options($store->id)->{self::PENDING_OPTION} ?? '');
    }

    /** The theme the studio edits: the pending choice, else the live theme — when it is a sectioned Studio (library) theme. */
    protected function studioTheme(Store $store): ?MarketplaceTheme
    {
        $pending = self::pendingTemplate($store);
        $theme = MarketplaceThemeResolver::resolve($pending !== '' ? $pending : (string) (store_options($store->id)->store_template ?? ''));

        return $theme && $theme->isSectioned() && $theme->library() !== null ? $theme : null;
    }

    protected function requireStudioTheme(Store $store): MarketplaceTheme
    {
        $theme = $this->studioTheme($store);
        if ($theme === null) {
            abort(response()->json(['message' => ___('This store is not using a Theme Studio theme yet.')], 409));
        }

        return $theme;
    }

    protected function template(MarketplaceTheme $theme): string
    {
        return 'mp:'.$theme->slug.'@'.$theme->version;
    }

    /** @return array<string,mixed> */
    protected function themeInfo(MarketplaceTheme $theme): array
    {
        return [
            'template' => $this->template($theme),
            'slug' => $theme->slug,
            'version' => $theme->version,
            'name' => (string) ($theme->manifest()['name'] ?? $theme->slug),
            'is_studio' => $theme->library() !== null,
        ];
    }

    /**
     * Studio themes the owner can pick, keyed by slug: published marketplace
     * Liquid themes whose latest published version is built on the section
     * library, plus in-repo library themes without a marketplace row (the
     * `*-dev` reference themes excluded).
     *
     * @return array<string,array{slug:string,name:string,vertical_groups:list<string>,thumb:?string,theme:MarketplaceTheme,row:?Theme}>
     */
    protected function studioThemes(): array
    {
        $out = [];

        try {
            $rows = Theme::where('status', 'published')->get();
        } catch (\Throwable $e) {
            report($e);
            $rows = collect();
        }
        $known = [];
        foreach ($rows as $row) {
            $known[] = strtolower((string) $row->slug);
            if ($row->isBlade()) {
                continue;
            }
            $version = $row->versions()->where('status', 'published')->orderByDesc('id')->first();
            $theme = $version ? MarketplaceThemeResolver::resolve('mp:'.$row->slug.'@'.$version->semver) : null;
            if (! $theme || ! $theme->isSectioned() || $theme->library() === null) {
                continue;
            }
            $out[$theme->slug] = $this->themeEntry($theme, $row);
        }

        foreach (glob(resource_path('liquid-themes').'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $slug = basename($dir);
            if (isset($out[$slug]) || in_array(strtolower($slug), $known, true) || str_ends_with($slug, '-dev') || str_contains($slug, '-dev-')) {
                continue;
            }
            $versions = array_map('basename', glob($dir.'/*', GLOB_ONLYDIR) ?: []);
            usort($versions, 'version_compare');
            $version = end($versions);
            $theme = is_string($version) ? MarketplaceThemeResolver::resolve('mp:'.$slug.'@'.$version) : null;
            if ($theme && $theme->isSectioned() && $theme->library() !== null) {
                $out[$slug] = $this->themeEntry($theme, null);
            }
        }

        return $out;
    }

    /** @return array{slug:string,name:string,vertical_groups:list<string>,thumb:?string,theme:MarketplaceTheme,row:?Theme} */
    protected function themeEntry(MarketplaceTheme $theme, ?Theme $row): array
    {
        $manifest = $theme->manifest();
        $groups = $manifest['vertical_groups'] ?? $manifest['verticals'] ?? ($row?->category ? [$row->category] : []);
        $preview = 'assets/marketplace-themes/'.$theme->slug.'/'.$theme->version.'/preview.png';

        return [
            'slug' => $theme->slug,
            'name' => (string) ($row?->name ?: ($manifest['name'] ?? $theme->slug)),
            'vertical_groups' => array_values(array_filter(array_map('strval', is_array($groups) ? $groups : [$groups]))),
            'thumb' => is_file(public_path($preview)) ? asset($preview) : null,
            'theme' => $theme,
            'row' => $row,
        ];
    }

    /**
     * The Studio theme a recipe starts on: the recipe's own, else the store's
     * current Studio theme, else the first one for the store's vertical group.
     *
     * @param  array<string,mixed>  $recipe
     */
    protected function recipeThemeSlug(array $recipe, Store $store): ?string
    {
        foreach (['theme', 'theme_slug'] as $key) {
            if (is_string($recipe[$key] ?? null) && $recipe[$key] !== '') {
                return $recipe[$key];
            }
        }
        if ($current = $this->studioTheme($store)) {
            return $current->slug;
        }
        $group = (string) (app(VerticalRegistry::class)->config($store->store_type ?? null)['group'] ?? 'food');
        $themes = $this->studioThemes();
        foreach ($themes as $slug => $entry) {
            if (in_array($group, $entry['vertical_groups'], true)) {
                return $slug;
            }
        }

        return array_key_first($themes);
    }

    /** The parallel recipes lane's service, when it has landed. */
    protected function recipes(): ?object
    {
        return class_exists(self::RECIPES_CLASS) ? app(self::RECIPES_CLASS) : null;
    }

    /** @return list<array{id:string,name:string,vertical:string}> */
    protected function recipeList(): array
    {
        $recipes = $this->recipes();
        if ($recipes === null || ! method_exists($recipes, 'all')) {
            return [];
        }

        $out = [];
        foreach ((array) $recipes->all() as $key => $recipe) {
            if (! is_array($recipe)) {
                continue;
            }
            $id = (string) ($recipe['id'] ?? $key);
            $out[] = [
                'id' => $id,
                'name' => (string) ($recipe['name'] ?? $id),
                'vertical' => (string) ($recipe['vertical'] ?? strtok($id, '/')),
            ];
        }

        return $out;
    }

    /**
     * The section catalogue for the editor, from each section's {% schema %}:
     * library sections + the theme's own. Hidden sections are listed only when
     * the layout already uses them. Names/labels are shown in the dashboard
     * language via the library's read-only locale strings.
     *
     * @param  array<string,mixed>  $layout
     * @return list<array<string,mixed>>
     */
    protected function library(MarketplaceTheme $theme, array $layout): array
    {
        $types = $theme->library()?->sectionTypes() ?? [];
        foreach (glob($theme->path('sections').'/*.liquid') ?: [] as $file) {
            $types[] = basename($file, '.liquid');
        }
        $types = array_values(array_unique($types));
        sort($types);

        $used = array_map(fn ($s) => (string) ($s['type'] ?? ''), $layout['sections'] ?? []);
        $strings = $theme->locales(app()->getLocale());
        $label = fn ($s) => is_string($s) ? ($strings[$s] ?? $s) : $s;
        $libraryVersion = $theme->library()?->version;

        $out = [];
        foreach ($types as $type) {
            $schema = LayoutMerge::schemaFor($theme, $type);
            if ($schema === []) {
                continue;
            }
            $hidden = (bool) ($schema['hidden_from_gallery'] ?? false);
            if ($hidden && ! in_array($type, $used, true)) {
                continue;
            }
            $thumb = null;
            foreach (['webp', 'png', 'jpg'] as $ext) {
                $rel = 'assets/liquid-library/'.$libraryVersion.'/thumbs/'.$type.'.'.$ext;
                if ($libraryVersion !== null && is_file(base_path('../'.$rel))) {
                    $thumb = asset($rel);
                    break;
                }
            }

            $out[] = [
                'type' => $type,
                'name' => (string) $label($schema['name'] ?? $type),
                'group' => (string) ($schema['group'] ?? 'content'),
                'verticals' => array_values(array_map('strval', (array) ($schema['verticals'] ?? ['all']))),
                'settings' => $this->localizeDefs($schema['settings'] ?? [], $label),
                'blocks' => array_values(array_map(fn ($b) => [
                    'type' => (string) ($b['type'] ?? ''),
                    'name' => (string) $label($b['name'] ?? ($b['type'] ?? '')),
                    'settings' => $this->localizeDefs($b['settings'] ?? [], $label),
                ] + (isset($b['limit']) ? ['limit' => (int) $b['limit']] : []), array_filter((array) ($schema['blocks'] ?? []), 'is_array'))),
                'presets' => array_values(array_filter((array) ($schema['presets'] ?? []), 'is_array')),
                'thumb' => $thumb,
                'hidden_from_gallery' => $hidden,
                'max_per_page' => is_int($schema['max_per_page'] ?? null) ? $schema['max_per_page'] : null,
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    protected function localizeDefs(mixed $defs, callable $label): array
    {
        $out = [];
        foreach (is_array($defs) ? $defs : [] as $def) {
            if (! is_array($def)) {
                continue;
            }
            foreach (['label', 'info', 'placeholder', 'content'] as $key) {
                if (isset($def[$key])) {
                    $def[$key] = $label($def[$key]);
                }
            }
            if (isset($def['options']) && is_array($def['options'])) {
                $def['options'] = array_map(fn ($o) => is_array($o) && isset($o['label']) ? ['label' => $label($o['label'])] + $o : $o, $def['options']);
            }
            $out[] = $def;
        }

        return $out;
    }

    /**
     * Design tokens: the customizer's non-section fields + their current values
     * (published ← draft). Edits go through the customizer endpoints (urls.tokens_*).
     *
     * @return array{fields:list<array<string,mixed>>,values:array<string,mixed>}
     */
    protected function tokens(Store $store, MarketplaceTheme $theme): array
    {
        $template = $this->template($theme);
        $schema = ThemeCustomizerService::localizeSchema(ThemeCustomizerService::schemaFor($template));

        $fields = [];
        foreach (ThemeCustomizerService::fields($schema) as $id => $field) {
            if (! str_starts_with((string) $id, 'sections.')) {
                $fields[] = $field;
            }
        }

        $ts = ThemeSetting::where('store_id', $store->id)->where('template', StoreLayouts::key($theme))->first();
        $values = array_merge(
            ThemeCustomizerService::defaults($schema),
            is_array($ts?->settings) ? $ts->settings : [],
            is_array($ts?->draft) ? $ts->draft : [],
        );
        $ids = array_map(fn ($f) => (string) $f['id'], $fields);

        return [
            'fields' => $fields,
            'values' => array_intersect_key($values, array_flip($ids)),
        ];
    }

    /** @return array<string,string> */
    protected function urls(Store $store): array
    {
        $q = ['store' => $store->id];

        return [
            'state' => route('theme.studio.state', $q),
            'draft' => route('theme.studio.draft', $q),
            'section' => route('theme.studio.section', ['sid' => '__SID__'] + $q),
            'publish' => route('theme.studio.publish', $q),
            'discard' => route('theme.studio.discard', $q),
            'versions' => route('theme.studio.versions', $q),
            'revert' => route('theme.studio.revert', ['id' => '__ID__'] + $q),
            'media' => route('theme.studio.media', $q),
            'start' => route('theme.studio.start', $q),
            'translate' => route('theme.studio.translate', $q),
            'preview' => route('publicView', $store->slug).'?preview_theme=1&studio=1',
            'tokens_draft' => route('theme.customizer.draft', $store->id),
            'tokens_schema' => route('theme.customizer.schema', $store->id),
        ];
    }

    /**
     * Theme Studio phase D: the AI theme builder endpoints (AI-BUILDER-CONTRACT.md),
     * only when the store's caps.ai_generate is on.
     *
     * @return array<string,string>
     */
    protected function aiUrls(Store $store): array
    {
        $q = ['store' => $store->id];

        return [
            'ai_generate' => route('theme.studio.ai.generate', $q),
            'ai_set' => route('theme.studio.ai.set', ['set' => '__SET__'] + $q),
            'ai_preview' => route('theme.studio.ai.preview', ['set' => '__SET__', 'proposal' => '__P__'] + $q),
            'ai_apply' => route('theme.studio.ai.apply', ['set' => '__SET__', 'proposal' => '__P__'] + $q),
            // E2: "Restyle this section" (a scoped CSS variant, never markup).
            'ai_restyle' => route('theme.studio.ai.restyle', $q),
        ];
    }

    /** @return list<array{code:string,name:string,dir:string}> default language first */
    protected function languages(Store $store): array
    {
        $options = store_options($store->id);
        $default = (string) ($options->default_language ?? config('app.locale'));
        $codes = $options->menu_languages ?? [];
        if (is_string($codes)) {
            $decoded = json_decode($codes, true);
            $codes = is_array($decoded) ? $decoded : [$codes];
        }
        $codes = array_values(array_filter(array_map('strval', is_array($codes) ? $codes : (array) $codes)));
        $codes = array_values(array_unique(array_merge([$default], $codes)));

        $rows = Language::whereIn('code', $codes)->get(['code', 'name', 'direction']);
        $out = [];
        foreach ($codes as $code) {
            $row = $rows->firstWhere('code', $code);
            if ($row) {
                $out[] = [
                    'code' => (string) $row->code,
                    'name' => (string) $row->name,
                    'dir' => strtolower((string) $row->direction) === 'rtl' ? 'rtl' : 'ltr',
                ];
            }
        }

        return $out;
    }

    /**
     * Owner-facing strings the editor script needs (the view adds its own).
     *
     * @return array<string,string>
     */
    protected function i18n(): array
    {
        $keys = [
            'Save', 'Saved', 'Saving…', 'Publish', 'Published', 'Discard', 'Preview', 'Undo', 'Redo',
            'Sections', 'Theme', 'Add section', 'Remove', 'Duplicate', 'Hide', 'Show', 'Move up', 'Move down',
            'History', 'Revert', 'Upload image', 'Something went wrong. Please try again.',
            'This change is not allowed on your plan.', 'Choose a theme', 'Start from a recipe',
            // C4 translate · C5 notices · publish confirmation
            'Translate to other languages', 'Translating…', 'Translated :n texts. Review them in each language.',
            'Everything is already translated.', 'Translation failed. Please try again.',
            'Translate the texts of this page into your other menu languages? Texts you already translated are kept.',
            'Translate', 'Dismiss', 'Your theme was updated',
            'Publish your changes?', 'Your storefront will show these changes to customers.',
            'Your store will switch to :theme.', 'Publish now',
            // E2 restyle this section
            'Restyle with AI', 'Describe the look you want, for example: darker background, rounder cards, bigger heading.',
            'Generate style', 'Generating…', 'Keep this style', 'Reset to default style', 'This section uses an AI style.',
            'Previewing the new style. Keep it or discard it.', 'Style kept. Publish to show it to customers.',
            'Style removed.', 'Could not create a style. Try a different description.',
        ];
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = ___($key);
        }

        return $out;
    }

    /** @param  array<string,string>  $errors */
    protected function invalid(array $errors): JsonResponse
    {
        return response()->json([
            'message' => (string) (reset($errors) ?: ___('The given data was invalid.')),
            'errors' => $errors,
        ], 422);
    }
}
