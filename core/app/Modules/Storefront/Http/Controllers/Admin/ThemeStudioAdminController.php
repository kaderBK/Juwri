<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Http\Controllers\Admin;

use App\Models\Theme;
use App\Services\ThemeStudio\AiThemeBuilder;
use App\Services\Verticals\VerticalRegistry;
use App\Storefront\ThemeSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\Rule;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Storefront\Http\Controllers\User\ThemeStudioAiController;
use Juwri\Modules\Storefront\Models\AiThemeProposal;
use Juwri\Modules\Storefront\Services\ThemeStudio\Ai\ProposalRenderer;
use Juwri\Modules\Storefront\Services\ThemeStudio\Ai\StoreVars;
use Juwri\Modules\Storefront\Services\ThemeStudio\LayoutDocument;
use Juwri\Modules\Storefront\Services\ThemeStudio\Recipes;
use Juwri\Modules\Storefront\Storefront\MarketplaceTheme;
use Juwri\Modules\Storefront\Storefront\MarketplaceThemeResolver;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use ZipArchive;

/**
 * Theme Studio phase D4: superadmin "generate a marketplace theme"
 * (rev/theme-studio/AI-BUILDER-CONTRACT.md). The same AI theme builder, run
 * against a vertical's DemoStorefront dataset instead of a store; `save`
 * packages a proposal as a Studio theme (theme.json pinned to section library
 * 1 + commerce runtime v2 + the proposal's tokens + recipe;
 * templates/index.json = the layout) and submits it through
 * ThemeSubmissionService (validator → immutable install → lint → Lighthouse
 * gate job) into the review queue. Nothing is published from here.
 *
 * Routes: admin group (`admin` + `demo` + `csp.dashboard`), /admin/theme-studio.
 * The page view (`admin.theme_studio.generate`) belongs to the UI lane.
 */
class ThemeStudioAdminController extends Controller
{
    public const VIEW = 'admin.theme_studio.generate';

    /** GET / — the generator page. */
    public function page(): mixed
    {
        $registry = app(VerticalRegistry::class);
        $verticals = [];
        foreach ($registry->all() as $type => $config) {
            $verticals[] = ['type' => (string) $type, 'label' => ___((string) ($config['label'] ?? ucfirst((string) $type)))];
        }

        $config = [
            'verticals' => $verticals,
            'languages' => ['en', 'ar'],
            'urls' => [
                'generate' => route('admin.theme-studio.run'),
                'preview' => route('admin.theme-studio.preview', ['set' => '__SET__', 'proposal' => '__P__']),
                'save' => route('admin.theme-studio.save'),
                'reviews' => route('admin.theme-reviews.index'),
            ],
            'csrf' => csrf_token(),
        ];

        // The UI lane ships the page; until it lands the config is served as JSON.
        return View::exists(self::VIEW) ? View::make(self::VIEW, ['config' => $config]) : response()->json(['config' => $config]);
    }

    /** POST generate — {vertical, brief, lang?} → {set_id, fallback, proposals}. */
    public function generate(Request $request): JsonResponse
    {
        $data = validator($request->all(), ThemeStudioAiController::briefRules() + [
            'vertical' => ['required', 'string', Rule::in(array_keys(app(VerticalRegistry::class)->all()))],
            'lang' => ['sometimes', 'nullable', 'string', Rule::in(['en', 'ar'])],
        ])->validate();

        try {
            $result = app(AiThemeBuilder::class)->generate(null, (array) ($data['brief'] ?? []), [
                'vertical' => $data['vertical'],
                'default_language' => (string) ($data['lang'] ?? 'en'),
                'user_id' => (int) auth()->id(),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'The builder could not produce a renderable proposal: '.$e->getMessage()], 422);
        }

        $set = AiThemeProposal::create([
            'store_id' => null,
            'created_by' => (int) auth()->id(),
            'vertical' => (string) $data['vertical'],
            'brief' => $result['brief'],
            'proposals' => $result['proposals'],
            'meta' => $result['meta'] + ['fallback_reason' => $result['fallback_reason']],
            'fallback' => $result['fallback'],
            'expires_at' => now()->addDays(max(1, (int) config('theme_studio.ai.ttl_days', 7))),
        ]);

        return response()->json(array_diff_key($set->toContract(), ['expires_at' => true]));
    }

    /** GET preview/{set}/{proposal} — the proposal rendered against the vertical's demo store. */
    public function preview(Request $request, string $set, string $proposal): SymfonyResponse
    {
        $row = $this->findSet($set);
        $p = $row->proposal($proposal);
        abort_unless($p !== null, 404);
        $theme = $this->theme($p);

        $default = (string) ($row->brief['default_language'] ?? 'en');
        $vars = StoreVars::forDemo((string) $row->vertical, $default);
        $lang = (string) $request->query('lang', $default);
        if (! $vars['menu_languages']->contains('code', $lang)) {
            $lang = $default;
        }

        $tokens = array_filter((array) ($p['tokens'] ?? []), 'is_string');
        $tokens['fonts.pair'] = (string) ($p['font_pair'] ?? '');

        try {
            $html = app(ProposalRenderer::class)->page($theme, $p['layout'], $tokens, $vars, $lang, $default);
        } catch (\Throwable $e) {
            report($e);

            return response('This proposal failed to render against the demo store.', 422);
        }

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /** POST save — {set, proposal, slug, name} → the proposal as a Studio theme in the review queue. */
    public function save(Request $request): JsonResponse
    {
        $data = validator($request->all(), [
            'set' => ['required', 'string', 'regex:/^[0-9a-z]{26}$/'],
            'proposal' => ['required', 'string', 'regex:/^p[1-9]$/'],
            'slug' => ['required', 'string', 'min:3', 'max:40', 'regex:/^[a-z0-9][a-z0-9-]*$/'],
            'name' => ['required', 'string', 'max:60'],
        ])->validate();

        $row = $this->findSet($data['set']);
        $p = $row->proposal($data['proposal']);
        abort_unless($p !== null, 404);
        $base = $this->theme($p);

        $slug = strtolower($data['slug']);
        if (Theme::where('slug', $slug)->exists() || is_dir(resource_path('liquid-themes/'.$slug))
            || is_dir(storage_path('app/marketplace-themes/'.$slug))) {
            return response()->json(['message' => 'That slug is already taken.', 'errors' => ['slug' => 'That slug is already taken.']], 422);
        }

        $zip = $this->package($p, $base, $slug, trim($data['name']), (string) $row->vertical);
        try {
            $version = app(ThemeSubmissionService::class)->submit($zip);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'The theme package was refused: '.$e->getMessage()], 422);
        } finally {
            @unlink($zip);
        }

        return response()->json([
            'ok' => true,
            'theme_id' => (int) $version->theme_id,
            'version_id' => (int) $version->id,
            'status' => (string) $version->status,
            'review_url' => route('admin.theme-reviews.index'),
        ]);
    }

    /**
     * Build the theme zip: theme.json + templates/index.json.
     *
     * @param  array<string,mixed>  $p
     */
    protected function package(array $p, MarketplaceTheme $base, string $slug, string $name, string $vertical): string
    {
        $recipe = Recipes::find((string) ($p['recipe_id'] ?? ''));
        // The vertical the recipe belongs to ("restaurant/classic" →
        // "restaurant"), for the manifest's vertical key.
        $recipeVertical = $recipe !== null ? (string) ($recipe['vertical'] ?? '') : '';
        if ($recipeVertical === '' && is_string($recipe['id'] ?? null) && str_contains((string) $recipe['id'], '/')) {
            $recipeVertical = (string) explode('/', (string) $recipe['id'])[0];
        }
        $baseManifest = $base->manifest();
        // A base manifest may carry a SET name ('food') where a vertical is
        // expected — only a real vertical is carried over.
        $baseVertical = (string) ($baseManifest['vertical'] ?? '');
        $manifestVertical = app(VerticalRegistry::class)->exists($baseVertical)
            ? $baseVertical
            : ($recipeVertical !== '' ? $recipeVertical : $vertical);
        $tokens = array_filter((array) ($p['tokens'] ?? []), 'is_string');
        if (is_string($p['font_pair'] ?? null) && $p['font_pair'] !== '') {
            $tokens['fonts.pair'] = $p['font_pair'];
        }

        $manifest = [
            'name' => $name,
            'slug' => $slug,
            'version' => '1',
            'author' => 'juwri',
            'description' => (string) ($p['rationale'] ?? ''),
            'min_platform_version' => '2.0',
            'source' => 'liquid',
            'library' => (string) ($base->library()->version ?? Recipes::DEFAULT_LIBRARY),
            'commerce_runtime' => 'v2',
            'category' => (string) ($baseManifest['category'] ?? $vertical),
            // The VERTICAL, not the set: the base manifest may carry a set
            // name ('food') where a vertical is expected, and a manifest that
            // names a vertical it does not have would be filed into the
            // wrong theme set. The recipe knows its vertical; the request's
            // vertical is validated against the registry.
            'vertical' => $manifestVertical,
            'recipe' => (string) ($recipe['id'] ?? ($p['recipe_id'] ?? '')),
            'tokens' => $tokens,
        ];

        $layout = LayoutDocument::normalize((array) $p['layout']);
        $index = ['sections' => $layout['sections'], 'order' => $layout['order']];

        $path = (string) tempnam(sys_get_temp_dir(), 'mbtheme').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('theme.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $zip->addFromString('templates/index.json', (string) json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $zip->close();

        return $path;
    }

    protected function findSet(string $set): AiThemeProposal
    {
        abort_unless((bool) preg_match('/^[0-9a-z]{26}$/', $set), 404);
        $row = AiThemeProposal::where('public_id', $set)->whereNull('store_id')->live()->first();
        abort_unless($row !== null, 404);

        return $row;
    }

    /** @param  array<string,mixed>  $p */
    protected function theme(array $p): MarketplaceTheme
    {
        $theme = MarketplaceThemeResolver::resolve('mp:'.($p['theme_slug'] ?? '').'@'.($p['theme_version'] ?? '1'), gate: false);
        abort_unless($theme !== null && $theme->isSectioned(), 409, 'This theme is no longer available.');

        return $theme;
    }
}
