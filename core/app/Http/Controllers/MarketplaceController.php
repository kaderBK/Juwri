<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http\Controllers;

use App\Models\StoreOption;
use App\Models\Theme;
use App\Models\ThemeVersion;
use App\Services\Theme\ThemeSets;
use App\Services\ThemePurchaseService;
use App\Support\SearchTerm;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Modules\AppStore\Http\Controllers\MarketplaceReviewController;
use Juwri\Modules\Storefront\Services\Rendering\LiquidRenderer;
use Juwri\Modules\Storefront\Services\ThemeStudio\Recipes;
use Juwri\Modules\Storefront\Storefront\CommerceRuntime;
use Juwri\Modules\Storefront\Storefront\DemoStorefront;
use Juwri\Modules\Storefront\Storefront\MarketplaceThemeResolver;
use Juwri\Modules\Storefront\Storefront\SectionedTemplate;
use Juwri\Modules\Storefront\Storefront\ShellStyles;
use Juwri\Modules\Storefront\Storefront\ThemeContext;
use Juwri\Modules\Storefront\Storefront\ThemeSettingValues;

/**
 * P4-4b: the public theme marketplace — listing + detail (published themes
 * only), and the owner actions: buy (P4-1 wallet rails) and apply (points a
 * restaurant's template at the latest PUBLISHED version, entitlement-gated).
 * Routes register before the {slug} catch-all.
 */
class MarketplaceController extends Controller
{
    public function index(Request $request, ?string $themeSet = null)
    {
        $industriesVocab = config('marketplace.theme_industries');

        $q = trim((string) $request->query('q', ''));
        $price = (string) $request->query('price', '');                       // '' | free | paid
        $industry = array_values(array_intersect((array) $request->query('industry', []), $industriesVocab));
        $sort = (string) $request->query('sort', 'featured');
        $minRating = (int) $request->query('min_rating', 0);                  // 0=any | 2 | 3 | 4

        // The theme SET (food | retail | service | hotel | …) scopes the
        // catalogue to the stores it can actually serve. The public
        // marketplace shows every theme by default and can be scoped with
        // ?set=<set>; the owner dashboard's Theme Store passes the ACTIVE
        // store's set, so a clothing store is offered clothing themes and
        // never a restaurant's. null = no scoping.
        $sets = app(ThemeSets::class);
        $themeSet = $themeSet ?? $request->query('set');
        $themeSet = is_string($themeSet) ? trim($themeSet) : '';
        if ($themeSet !== '') {
            // A set the platform does not know is a 404, not an empty
            // catalogue: a typo in a shared link should say so.
            abort_unless($sets->isKnown($themeSet), 404);
        } else {
            $themeSet = null;
        }

        $applySet = fn ($query) => $themeSet === null ? $query : $query->where('theme_set', $themeSet);

        // A fresh base builder each call: published themes that actually have a
        // published version, honouring the text search. Facets clone off this.
        // The SET is applied on top of it (see $applySet), so the set facet can
        // count every set and not only the selected one.
        $baseQuery = fn () => Theme::query()
            ->where('status', 'published')
            ->whereHas('versions', fn ($v) => $v->where('status', 'published'))
            ->when(SearchTerm::clean($q) !== '', fn ($query) => SearchTerm::apply($query, ['name', 'tagline', 'slug'], $q));

        $base = fn () => $applySet($baseQuery());

        $applyPrice = fn ($query) => match ($price) {
            'free' => $query->where('price_minor', '<=', 0), // P1-05: the sign is the same in minor units
            'paid' => $query->where('price_minor', '>', 0),
            default => $query,
        };
        $applyIndustry = fn ($query) => empty($industry) ? $query : $query->whereIn('category', $industry);

        // Faceted counts — each facet ignores its OWN selection so multi-select
        // stays coherent (respects the other active filters + search).
        $ic = $applyPrice($base())->select('category', DB::raw('count(*) as c'))
            ->groupBy('category')->pluck('c', 'category');
        $industryCounts = [];
        foreach ($industriesVocab as $key) {
            $industryCounts[$key] = (int) ($ic[$key] ?? 0);
        }
        $freeCount = (int) $applyIndustry($base())->where('price_minor', '<=', 0)->count();
        $paidCount = (int) $applyIndustry($base())->where('price_minor', '>', 0)->count();

        // The SET facet — how many themes each store family holds. It counts
        // from the UNSET base, so every set shows its own number whatever is
        // selected (a facet that ignores only its own selection).
        $setCounts = [];
        foreach ($sets->all() as $setKey => $setLabel) {
            $setCounts[$setKey] = (int) $applyIndustry($applyPrice($baseQuery()))
                ->where('theme_set', $setKey)->count();
        }

        // The result set: base + both filters, sorted.
        $results = $applyIndustry($applyPrice($base()))
            ->when($minRating > 0, fn ($query) => $query->where('rating_avg', '>=', $minRating))
            ->with(['seller:id,name', 'screenshots']);
        $results = match ($sort) {
            // P1-05: every theme is priced in the platform currency, so minor units sort like the amounts
            'price_low' => $results->orderBy('price_minor')->orderBy('name'),
            'price_high' => $results->orderByDesc('price_minor')->orderBy('name'),
            'top_rated' => $results->orderByDesc('rating_avg')->orderByDesc('rating_count')->orderBy('name'),
            default => $results->orderByDesc('rating_avg')->orderBy('name'), // featured
        };
        $results = $results->get();

        return view('marketplace.index', [
            'results' => $results,
            'total' => $results->count(),
            'industries' => $industriesVocab,
            'industryCounts' => $industryCounts,
            'themeSets' => $sets->all(),
            'setCounts' => $setCounts,
            'freeCount' => $freeCount,
            'paidCount' => $paidCount,
            'q' => $q,
            'price' => $price,
            'industry' => $industry,
            'sort' => $sort,
            'minRating' => $minRating,
            'themeSet' => $themeSet,
            'siteName' => brand_title(),
        ]);
    }

    public function show(string $slug)
    {
        $theme = Theme::where('status', 'published')
            ->with(['seller:id,name', 'screenshots'])
            ->where('slug', $slug)->firstOrFail();
        $version = $this->latestPublished($theme);
        abort_unless($version, 404);

        // Changelog block: every published version, newest first.
        $versions = $theme->versions()
            ->where('status', 'published')->orderByDesc('id')->get();

        $entitled = auth()->check()
            && ThemePurchaseService::entitled(auth()->user(), $theme);
        $stores = auth()->check()
            ? Store::where('user_id', auth()->id())->get(['id', 'title'])
            : collect();

        $eligibleToReview = MarketplaceReviewController::themeEligible($theme);
        $myReview = auth()->check()
            ? $theme->reviews()->where('user_id', auth()->id())->first()
            : null;

        [$publishedReviews, $distribution] = $this->reviewSurface($theme);

        return view('marketplace.show', [
            'theme' => $theme,
            'version' => $version,
            'versions' => $versions,
            'screenshots' => $theme->screenshots,
            'entitled' => $entitled,
            'stores' => $stores,
            'previewUrl' => $this->previewImage($theme, $version),
            // Blade showcase: a first-party post_template. It has no Liquid package,
            // so "preview" links to a live demo store and "apply" writes the plain
            // template slug instead of buying/applying a `mp:` value.
            'isBlade' => $theme->isBlade(),
            'demoUrl' => $theme->demoUrl(),
            // A real localized storefront capture (home gallery asset) for the hero,
            // and the active-locale About copy. Both fall back cleanly.
            'heroShot' => theme_showcase_shot($theme->template_slug ?: $theme->slug),
            'aboutText' => $theme->localizedDescription($version->manifest['description'] ?? $theme->tagline),
            'eligibleToReview' => $eligibleToReview,
            'myReview' => $myReview,
            'publishedReviews' => $publishedReviews,
            'distribution' => $distribution,
            'siteName' => brand_title(),
        ]);
    }

    /**
     * P3-3: the published review list + 5→1 star distribution for a listing.
     * Shared shape with the App Store (Juwri\Modules\AppStore\Http\Controllers\AppStoreController).
     *
     * @return array{0:Collection,1:array<int,int>}
     */
    public static function reviewSurface($reviewable): array
    {
        $reviews = $reviewable->reviews()
            ->where('status', 'published')->with('user:id,name')
            ->latest('id')->limit(30)->get();

        $counts = $reviewable->reviews()->where('status', 'published')
            ->selectRaw('rating, count(*) as c')->groupBy('rating')->pluck('c', 'rating');
        $distribution = [];
        for ($s = 5; $s >= 1; $s--) {
            $distribution[$s] = (int) ($counts[$s] ?? 0);
        }

        return [$reviews, $distribution];
    }

    /** Buy a license (auth owner; wallet rails; idempotent). */
    public function buy(string $slug, ThemePurchaseService $purchases)
    {
        abort_unless(auth()->check(), 403);
        $theme = Theme::where('status', 'published')->where('slug', $slug)->firstOrFail();

        try {
            $purchases->purchase(auth()->user(), $theme);
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 402);
        }

        return response()->json(['ok' => true]);
    }

    /** Point one of the owner's restaurants at the latest published version. */
    public function apply(Request $request, string $slug)
    {
        abort_unless(auth()->check(), 403);
        $request->validate(['store_id' => 'required|integer']);

        $theme = Theme::where('status', 'published')->where('slug', $slug)->firstOrFail();

        // For paid (non-Blade) themes the entitlement is the gate, and it is
        // checked BEFORE the tenant-scoped post lookup: a user who has not bought
        // the theme gets 402 regardless of which post they name, so the 404 from a
        // post they do not own never pre-empts (and never leaks) the license gate.
        // Built-in Blade themes are free, so their only gate is post ownership.
        $version = null;
        if (! $theme->isBlade()) {
            $version = $this->latestPublished($theme);
            abort_unless($version, 404);

            // Entitlement is the gate — same rule the render path enforces.
            abort_unless(ThemePurchaseService::entitled(auth()->user(), $theme), 402);
        }

        $store = Store::where('id', $request->integer('store_id'))
            ->where('user_id', auth()->id())->firstOrFail();

        // Blade showcase: apply the PLAIN template slug the storefront renderer
        // understands (resolve_store_theme). A `mp:` value here would resolve to
        // null and fall through to the Blade FALLBACK theme, not this one.
        if ($theme->isBlade()) {
            $tpl = (string) ($theme->template_slug ?: $theme->slug);
            StoreOption::updateStoreOption($store->id, 'store_template', $tpl);

            return response()->json(['ok' => true, 'template' => $tpl]);
        }

        // The theme SET is the offerability rule: a retail theme (clothing)
        // belongs to a retail store, a food theme to a restaurant. Same rule
        // the Blade picker enforces in ThemeCustomizerController::setTemplate —
        // a store cannot switch itself onto a theme from another vertical.
        // A store that already runs THIS theme keeps it: a theme installed
        // before the sets existed carries the default set, and locking its
        // owner out of their own storefront would be the worse bug.
        $currentTemplate = (string) StoreOption::getStoreOption($store->id, 'store_template', '');
        if (! $theme->fitsStore($store->store_type ?? null, $currentTemplate)) {
            abort(422, ___('This theme is for a different kind of store.'));
        }

        StoreOption::updateStoreOption($store->id, 'store_template', 'mp:'.$theme->slug.'@'.$version->semver);

        return response()->json(['ok' => true, 'template' => 'mp:'.$theme->slug.'@'.$version->semver]);
    }

    /**
     * P4-5: render the theme against the DEMO dataset — try before you buy,
     * no live data, no entitlement. `?version=<semver>` lets an ADMIN preview
     * a review-queue version (feeds the P4-4 queue and the P4-3 gate URLs).
     */
    public function preview(Request $request, string $slug)
    {
        $theme = Theme::where('slug', $slug)->firstOrFail();

        // Blade showcase has no Liquid package to render against the demo dataset —
        // its "preview" is the live demo store itself. Fall back to the detail page
        // if no demo is linked.
        if ($theme->isBlade()) {
            abort_unless($theme->status === 'published', 404);

            return redirect($theme->demoUrl() ?? route('marketplace.show', $theme->slug));
        }

        $requested = (string) $request->query('version', '');
        $isAdmin = auth()->check() && (auth()->user()->user_type ?? null) === 'admin';
        // Theme Studio follow-ups: a valid temporary signed URL (minted by
        // RunThemeLighthouseGate::previewUrls for the Lighthouse gate, which has
        // no session) may preview a not-yet-published version too. The
        // signature covers slug + version + lang, so it cannot be re-pointed.
        $signed = $requested !== '' && $request->hasValidSignature();

        if ($requested !== '' && ($isAdmin || $signed)) {
            $version = $theme->versions()->where('semver', $requested)->firstOrFail();
        } else {
            abort_unless($theme->status === 'published', 404);
            $version = $this->latestPublished($theme);
            abort_unless($version, 404);
        }

        $mpTheme = MarketplaceThemeResolver::resolve(
            'mp:'.$theme->slug.'@'.$version->semver,
            gate: false, // preview is exactly the pre-publish surface
        );
        abort_unless($mpTheme, 404);

        // The preview dataset follows the THEME's own recipe vertical, not the
        // restaurant default: a clothing theme (recipe clothing/default) has to
        // preview against a GOODS store — Demo Market with tees, hoodies and a
        // size × colour variant matrix — or the buyer sees a restaurant and
        // concludes the theme is a food theme. Recipes::DEMO_DATASET maps the
        // recipe's vertical to the dataset that exercises it (clothing → retail).
        $recipeVertical = null;
        $recipeId = (string) ($mpTheme->manifest()['recipe'] ?? '');
        if ($recipeId !== '') {
            $recipe = Recipes::find($recipeId);
            $recipeVertical = is_array($recipe) ? (string) ($recipe['vertical'] ?? null) : null;
        }
        $dataset = $recipeVertical !== null
            ? (Recipes::DEMO_DATASET[$recipeVertical] ?? 'restaurant')
            : 'restaurant';

        $vars = DemoStorefront::vars($dataset);
        $vars['settings'] = ThemeSettingValues::resolve($mpTheme); // B0.3: schema defaults

        // Theme Studio B0.4: ?lang=<code> previews any demo language (Arabic →
        // RTL), so reviewers and buyers can check a theme's right-to-left render.
        $previewLang = $vars['menu_languages']->firstWhere('code', (string) $request->query('lang', ''))
            ?? $vars['default_menu_language'];
        $vars['default_menu_language'] = $previewLang;
        app()->setLocale($previewLang->code);
        $themeContext = app(ThemeContext::class);
        $commerce = app(CommerceRuntime::class);
        $globals = $themeContext->build($vars);
        $registers = $themeContext->registers($vars)
            + $commerce->registers($vars, $mpTheme)
            + [
                'theme_asset_map' => $mpTheme->assetMap(),
                'theme_locale_map' => $mpTheme->locales($previewLang->code),
            ];

        try {
            $body = $mpTheme->isSectioned()
                ? app(SectionedTemplate::class)->render($mpTheme, $globals, $registers, [], 'live')
                : app(LiquidRenderer::class)->render($mpTheme->templateSource(), $globals, 'live', $registers, $mpTheme->snippets());
        } catch (\Throwable $e) {
            report($e);

            return response('This theme version failed to render against the demo store.', 422);
        }

        if (trim($body) === '') {
            // Live mode never blanks a diner; a preview SHOULD surface it.
            return response('This theme version failed to render against the demo store (empty output).', 422);
        }

        $response = response()->view('store_templates.marketplace-shell', [
            'store' => $vars['store'],
            'body' => $body,
            'lang' => $previewLang->code,
            'dir' => ($previewLang->direction ?? null) === 'rtl' ? 'rtl' : 'ltr',
            'shellStyles' => ShellStyles::for($mpTheme),
        ]);

        if ($requested !== '' && $version->status !== 'published') {
            // An unreleased version: never cached, never indexed.
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }

    protected function latestPublished(Theme $theme): ?ThemeVersion
    {
        return $theme->versions()->where('status', 'published')->orderByDesc('id')->first();
    }

    protected function previewImage(Theme $theme, ThemeVersion $version): ?string
    {
        $rel = 'assets/marketplace-themes/'.$theme->slug.'/'.$version->semver.'/preview.png';

        return is_file(public_path($rel)) ? asset($rel) : null;
    }

    /**
     * Store-chrome language switch (route store.lang). The marketplace and
     * app-store pages are unprefixed and render session('locale'); this sets
     * it and returns to the referring page. Works in both locale modes —
     * in prefix mode /lang/{code} 301s to the localized HOME instead.
     */
    public function setLocale(string $code)
    {
        $language = Language::where('code', $code)->where('active', 1)->firstOrFail();

        app()->setLocale($language->code);
        session(['locale' => $language->code]);

        return redirect()->back(fallback: route('marketplace.index'));
    }
}
