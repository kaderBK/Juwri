<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Http\Controllers\User;

use App\Http\Controllers\User\StoreController;
use App\Models\StoreOption;
use App\Services\ThemeCustomizerService;
use App\Services\ThemeStudio\AiThemeBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Ai\Contracts\AiLimitExceeded;
use Juwri\Modules\Storefront\Models\AiThemeProposal;
use Juwri\Modules\Storefront\Models\ThemeSetting;
use Juwri\Modules\Storefront\Services\ThemeStudio\Ai\Brief;
use Juwri\Modules\Storefront\Services\ThemeStudio\Ai\ProposalRenderer;
use Juwri\Modules\Storefront\Services\ThemeStudio\Recipes;
use Juwri\Modules\Storefront\Services\ThemeStudio\SectionRestyler;
use Juwri\Modules\Storefront\Services\ThemeStudio\StoreLayouts;
use Juwri\Modules\Storefront\Services\ThemeStudio\StudioCaps;
use Juwri\Modules\Storefront\Storefront\MarketplaceTheme;
use Juwri\Modules\Storefront\Storefront\MarketplaceThemeResolver;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Theme Studio phase D: the owner's AI theme builder endpoints
 * (rev/theme-studio/AI-BUILDER-CONTRACT.md). Same middleware as the editor
 * (`auth` + `csp.dashboard` + `feature:theme_studio`) plus
 * `feature:ai_theme_builder`; every endpoint is scoped to ONE of the owner's
 * stores (`?store=`, anything else 404) and needs the store's
 * `caps.ai_generate` (403 otherwise).
 *
 * Nothing here publishes: `apply` makes the proposal's theme the store's
 * PENDING studio theme and writes the layout + tokens as DRAFTS — customers
 * keep seeing the live design until the owner publishes in Theme Studio.
 */
class ThemeStudioAiController extends Controller
{
    /** POST generate — {brief, images?} → {set_id, fallback, proposals}. */
    public function generate(Request $request): JsonResponse
    {
        $store = $this->store($request);
        $this->requireCap($store);

        $data = validator($request->all(), self::briefRules() + [
            'images' => ['sometimes', 'boolean'],
        ])->validate();

        $limit = (int) config('theme_studio.ai.daily_limit', 10);
        $today = AiThemeProposal::where('store_id', $store->id)->where('created_at', '>=', now()->startOfDay())->count();
        if ($limit > 0 && $today >= $limit) {
            return response()->json(['message' => ___('You have reached today\'s limit of AI theme generations. Please try again tomorrow.')], 429);
        }

        try {
            $result = app(AiThemeBuilder::class)->generate($store, (array) ($data['brief'] ?? []), [
                'user_id' => (int) auth()->id(),
                'images' => (bool) ($data['images'] ?? false),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => ___('We could not build a theme for this store right now. Please try again.')], 422);
        }

        $set = AiThemeProposal::create([
            'store_id' => (int) $store->id,
            'created_by' => (int) auth()->id(),
            'vertical' => (string) ($result['brief']['store']['store_type'] ?? ''),
            'brief' => $result['brief'],
            'proposals' => $result['proposals'],
            'meta' => $result['meta'] + ['fallback_reason' => $result['fallback_reason']],
            'fallback' => $result['fallback'],
            'expires_at' => now()->addDays(max(1, (int) config('theme_studio.ai.ttl_days', 7))),
        ]);

        return response()->json(array_diff_key($set->toContract(), ['expires_at' => true]));
    }

    /** GET sets/{set} — a stored set (this store's, not expired). */
    public function set(Request $request, string $set): JsonResponse
    {
        $store = $this->store($request);
        $this->requireCap($store);

        return response()->json($this->findSet($store, $set)->toContract());
    }

    /**
     * GET preview/{set}/{proposal} — the full storefront rendered with the
     * proposal (theme + layout + tokens), for the owner only, never cached.
     *
     * Rendered by the storefront's own Liquid branch (StoreController@publicView,
     * the owner-preview path) so the preview is exactly what the store would
     * show: the proposal is written as the pending theme + draft INSIDE a
     * database transaction that is always rolled back — nothing persists and
     * the public storefront never sees it.
     */
    public function preview(Request $request, string $set, string $proposal): SymfonyResponse
    {
        $store = $this->store($request);
        $this->requireCap($store);
        $row = $this->findSet($store, $set);
        $p = $row->proposal($proposal);
        abort_unless($p !== null, 404);
        $theme = $this->theme($p);

        $default = (string) ($row->brief['default_language'] ?? 'en');
        $languages = (array) ($row->brief['languages'] ?? [$default]);
        $lang = (string) $request->query('lang', $default);
        if (! in_array($lang, $languages, true)) {
            $lang = $default;
        }
        $layout = ProposalRenderer::localize($p['layout'], $lang, $default);
        $template = 'mp:'.$theme->slug.'@'.$theme->version;

        $html = '';
        DB::beginTransaction();
        try {
            StoreOption::updateStoreOption($store->id, ThemeStudioController::PENDING_OPTION, $template);
            $ts = ThemeSetting::firstOrNew(['store_id' => $store->id, 'template' => StoreLayouts::key($theme)]);
            if (! $ts->exists) {
                $ts->settings = [];
            }
            $ts->layout_draft = $layout;
            $ts->draft = array_merge(is_array($ts->draft) ? $ts->draft : [], $this->tokenValues($p));
            $ts->save();

            $request->query->remove('studio');
            $request->query->remove('studio_section');
            $request->query->add(['preview_theme' => '1', 'preview_lang' => $lang]);

            $response = app(StoreController::class)->publicView($store->slug);
            if ($response instanceof SymfonyResponse && $response->getStatusCode() === 200) {
                $html = (string) $response->getContent();
            }
        } catch (\Throwable $e) {
            report($e);
        } finally {
            DB::rollBack();
            StoreOption::flushOptionMemo();
        }

        if (! str_contains($html, 'mb-section')) {
            return response()->json(['message' => ___('The preview could not be rendered.')], 409);
        }

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * POST apply/{set}/{proposal} — the proposal becomes the store's PENDING
     * Studio theme with its layout as `layout_draft` and its tokens + font pair
     * as the customizer draft. Never publishes.
     */
    public function apply(Request $request, string $set, string $proposal): JsonResponse
    {
        $store = $this->store($request);
        $this->requireCap($store);
        $p = $this->findSet($store, $set)->proposal($proposal);
        abort_unless($p !== null, 404);
        $theme = $this->theme($p);
        $template = 'mp:'.$theme->slug.'@'.$theme->version;

        try {
            DB::transaction(function () use ($store, $theme, $template, $p): void {
                StoreLayouts::saveDraft($store, $theme, $p['layout']);
                ThemeCustomizerService::saveDraft($store, $template, $this->tokenValues($p));

                $live = (string) (store_options($store->id)->store_template ?? '');
                StoreOption::updateStoreOption($store->id, ThemeStudioController::PENDING_OPTION, $live === $template ? '' : $template);
            });
        } catch (ValidationException $e) {
            $errors = array_map(fn ($m) => is_array($m) ? (string) ($m[0] ?? '') : (string) $m, $e->errors());

            return response()->json(['message' => (string) (reset($errors) ?: ___('The given data was invalid.')), 'errors' => $errors], 422);
        }

        return response()->json(['ok' => true, 'redirect' => route('theme.studio', ['store' => $store->id])]);
    }

    /**
     * POST restyle — E2 "Restyle this section": {sid, prompt} → {css, preview:true, prompt, created_at}.
     *
     * The AI may only answer with CSS for that ONE existing section of the
     * store's editing layout; SectionRestyler sanitises it and scopes every
     * selector under `#mb-section-<sid>`. Nothing is stored here — the editor
     * previews the CSS and, when the owner keeps it, saves it with the draft as
     * `sections.<sid>.style` (re-cleaned by LayoutDocument on that write).
     * Limits: `theme_studio.ai.restyle_daily_limit` per store per day + the
     * owner's monthly AI allowance (AiService) → 429.
     */
    public function restyle(Request $request): JsonResponse
    {
        $store = $this->store($request);
        $this->requireCap($store);
        $theme = MarketplaceThemeResolver::resolve(ThemeStudioController::pendingTemplate($store) ?: (string) (store_options($store->id)->store_template ?? ''));
        if ($theme === null || ! $theme->isSectioned() || $theme->library() === null) {
            return response()->json(['message' => ___('This store is not using a Theme Studio theme yet.')], 409);
        }

        $data = validator($request->all(), [
            'sid' => ['required', 'string', 'max:64'],
            'prompt' => ['required', 'string', 'min:3', 'max:'.SectionRestyler::MAX_PROMPT],
        ])->validate();
        $sid = (string) $data['sid'];
        $section = StoreLayouts::editing((int) $store->id, $theme)['sections'][$sid] ?? null;
        abort_unless(is_array($section), 404);
        if (! preg_match(SectionRestyler::SID_PATTERN, $sid)) {
            return response()->json(['message' => ___('This section cannot be restyled.'), 'errors' => ['sid' => ___('This section cannot be restyled.')]], 422);
        }

        $limit = (int) config('theme_studio.ai.restyle_daily_limit', 20);
        $key = 'studio-restyle:'.$store->id.':'.now()->toDateString();
        if ($limit > 0 && RateLimiter::tooManyAttempts($key, $limit)) {
            return response()->json(['message' => ___('You have reached today\'s limit of AI section styles. Please try again tomorrow.')], 429);
        }

        try {
            $css = app(SectionRestyler::class)->generate($store, $theme, $sid, $section, trim((string) $data['prompt']), (int) auth()->id());
        } catch (AiLimitExceeded $e) {
            return response()->json(['message' => ___('You have used all AI requests included in your plan this month.')], 429);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => ___('We could not restyle this section right now. Please try again.')], 422);
        } finally {
            if ($limit > 0) {
                RateLimiter::hit($key, 86400);
            }
        }

        return response()->json([
            'css' => $css,
            'preview' => true,
            'prompt' => trim((string) $data['prompt']),
            'created_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Contract request rules for `brief` (shared with the superadmin endpoint).
     *
     * @return array<string,mixed>
     */
    public static function briefRules(): array
    {
        return [
            'brief' => ['sometimes', 'nullable', 'array'],
            'brief.text' => ['sometimes', 'nullable', 'string', 'max:500'],
            'brief.vibes' => ['sometimes', 'nullable', 'array', 'max:8'],
            'brief.vibes.*' => ['string', Rule::in(Brief::VIBES)],
            'brief.priority' => ['sometimes', 'nullable', 'string', Rule::in(Recipes::PRIORITIES)],
            'brief.colors' => ['sometimes', 'nullable', 'array', 'max:3'],
            'brief.colors.*' => ['string', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
        ];
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

    protected function requireCap(Store $store): void
    {
        if (! (StudioCaps::forStore($store)['ai_generate'] ?? false)) {
            abort(response()->json(['message' => ___('The AI theme builder is not included in your plan.')], 403));
        }
    }

    protected function findSet(Store $store, string $set): AiThemeProposal
    {
        abort_unless((bool) preg_match('/^[0-9a-z]{26}$/', $set), 404);
        $row = AiThemeProposal::where('public_id', $set)->where('store_id', $store->id)->live()->first();
        abort_unless($row !== null, 404);

        return $row;
    }

    /** @param  array<string,mixed>  $p */
    protected function theme(array $p): MarketplaceTheme
    {
        $theme = MarketplaceThemeResolver::resolve('mp:'.($p['theme_slug'] ?? '').'@'.($p['theme_version'] ?? '1'));
        if ($theme === null || ! $theme->isSectioned() || $theme->library() === null) {
            abort(response()->json(['message' => ___('This theme is no longer available.')], 409));
        }

        return $theme;
    }

    /**
     * The proposal's tokens + font pair as customizer values.
     *
     * @param  array<string,mixed>  $p
     * @return array<string,string>
     */
    protected function tokenValues(array $p): array
    {
        $values = array_filter((array) ($p['tokens'] ?? []), 'is_string');
        if (is_string($p['font_pair'] ?? null) && $p['font_pair'] !== '') {
            $values['fonts.pair'] = $p['font_pair'];
        }

        return $values;
    }
}
