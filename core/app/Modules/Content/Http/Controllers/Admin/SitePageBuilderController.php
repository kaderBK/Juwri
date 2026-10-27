<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Http\Controllers\Admin;

use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Language;
use App\Models\Site;
use App\Models\SitePage;
use Juwri\Modules\Content\Models\SitePageRevision;
use Juwri\Modules\Content\Services\SitePages\LegacyHomeBridge;
use Juwri\Modules\Content\Services\SitePages\SiteBlockRegistry;
use Juwri\Modules\Content\Services\SitePages\SitePageRenderer;
use App\Services\SitePages\SiteTranslator;
use Juwri\Core\Support\Html;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;
use Juwri\Modules\Ai\Contracts\AiProviderFailure;

/**
 * HOME-PAGES HP-40/42/43/44/45: the platform-admin block composer for Site
 * Pages (fork of the reseller PageBuilderController patterns; platform admin
 * auth, NOT the reseller portal). Client block JSON is UNTRUSTED — always
 * re-validated through SiteBlockRegistry and richtext purified server-side.
 * Publish snapshots draft→published_blocks and writes a pruned revision
 * (HP-42); unpublish is the per-site kill switch back to the legacy Blade
 * home. Preview renders the DRAFT against the live layout with noindex
 * (HP-43).
 */
class SitePageBuilderController extends Controller
{
    private const RESERVED_SLUGS = ['admin', 'login', 'blog', 'pricing', 'faqs', 'testimonials', 'signup', 'partners', 'api', 'mcp', 'developers', 'storage', 'page', 'contact', 'feedback', 'marketplace', 'studio'];

    private const REVISIONS_KEPT = 20;

    /** This install's Site row (created on first builder visit — seed-cmd parity). */
    private function site(): Site
    {
        return Site::active()->orderBy('id')->first()
            ?? Site::create(['key' => 'default', 'name' => (string) (config('settings.site_title') ?? config('app.name')), 'active' => true]);
    }

    /** Re-fetch a page scoped to this install's site; foreign ids 404. */
    private function page(int $id): SitePage
    {
        return SitePage::forSite($this->site()->id)->findOrFail($id);
    }

    /**
     * Active content locales, default first.
     *
     * @return array<int, string>
     */
    private function locales(): array
    {
        $default = (string) LaravelLocalization::getDefaultLocale();
        $codes = Language::where('active', 1)->orderBy('position')->pluck('code')->all();

        return array_values(array_unique(array_merge([$default], $codes ?: [$default])));
    }

    public function index(): View
    {
        $site = $this->site();
        // Lazily compose the bridge draft on first visit (never publishes).
        $home = SitePage::forSite($site->id)->where('type', 'home')->orderBy('id')->first()
            ?? app(LegacyHomeBridge::class)->seed($site)['page'];
        $sitePages = SitePage::forSite($site->id)->where('type', 'page')->orderBy('sort')->orderBy('id')->get();

        return view('admin.site-pages.index', [
            'site' => $site,
            'home' => $home,
            'sitePages' => $sitePages,
        ]);
    }

    public function edit(int $id): View
    {
        return view('admin.site-pages.edit', [
            'site' => $this->site(),
            'page' => $this->page($id),
            'registry' => SiteBlockRegistry::all(),
            'locales' => $this->locales(),
            'defaultLocale' => (string) LaravelLocalization::getDefaultLocale(),
        ]);
    }

    /** Autosave / explicit save of the DRAFT blocks JSON. */
    public function save(Request $request, int $id): JsonResponse
    {
        $page = $this->page($id);

        $incoming = $request->input('blocks', []);
        if (is_string($incoming)) {
            $incoming = json_decode($incoming, true) ?: [];
        }
        $clean = $this->sanitizeDocument($incoming);

        $page->blocks = $clean;
        if ($request->filled('title')) {
            $page->title = $this->sanitizeLocalized((array) $request->input('title'), 200);
        }
        if ($request->has('seo')) {
            $page->seo = $this->sanitizeSeo((array) $request->input('seo'));
        }
        $page->save();

        return response()->json(['ok' => true, 'blocks' => $clean, 'saved_at' => now()->toIso8601String()]);
    }

    /** Publish (snapshot + revision, HP-42) or unpublish (kill switch). */
    public function publish(Request $request, int $id): JsonResponse|RedirectResponse
    {
        $page = $this->page($id);

        if ($request->boolean('unpublish')) {
            $page->status = 'draft';
            $page->save();
        } else {
            $page->published_blocks = $page->blocks ?: ['version' => 1, 'blocks' => []];
            $page->status = 'published';
            $page->published_at = now();
            $page->save();

            SitePageRevision::create([
                'site_page_id' => $page->id,
                'blocks' => $page->published_blocks,
                'seo' => $page->seo,
                'label' => (string) $request->input('label', ''),
                'created_by' => auth()->id(),
                'created_at' => now(),
            ]);
            // Prune to the newest REVISIONS_KEPT.
            $keep = SitePageRevision::where('site_page_id', $page->id)
                ->orderByDesc('id')->limit(self::REVISIONS_KEPT)->pluck('id');
            SitePageRevision::where('site_page_id', $page->id)->whereNotIn('id', $keep)->delete();
        }

        // sitepage:html:* keys self-version on updated_at (HP-16); the save()
        // above already started fresh keys. SiteContext caches bust via
        // Site::booted only on site-row changes — nothing else to bust here.

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'status' => $page->status]);
        }

        return back()->with('success', ___('Saved.'));
    }

    /** Revisions list (JSON, for the composer's history panel). */
    public function revisions(int $id): JsonResponse
    {
        $page = $this->page($id);

        return response()->json([
            'ok' => true,
            'revisions' => $page->revisions()->orderByDesc('id')->get(['id', 'label', 'created_by', 'created_at']),
        ]);
    }

    /** Restore a revision into the DRAFT (publish separately after review). */
    public function restore(int $id, int $revisionId): JsonResponse
    {
        $page = $this->page($id);
        $revision = SitePageRevision::where('site_page_id', $page->id)->findOrFail($revisionId);

        $page->blocks = $this->sanitizeDocument($revision->blocks ?? []);
        if (! empty($revision->seo)) {
            $page->seo = $this->sanitizeSeo((array) $revision->seo);
        }
        $page->save();

        return response()->json(['ok' => true, 'blocks' => $page->blocks]);
    }

    /** Authenticated DRAFT preview against the live layout — noindex (HP-43). */
    public function preview(Request $request, int $id): Response
    {
        $page = $this->page($id);

        // Composer preview locale switch (?locale=ar) — render-time only.
        $locale = (string) $request->query('locale', '');
        if ($locale !== '' && in_array($locale, $this->locales(), true)) {
            app()->setLocale($locale);
        }

        $view = app(SitePageRenderer::class)
            ->renderDocument($page, $page->blocks ?: ['version' => 1, 'blocks' => []]);

        return response($view->render(), 200, ['X-Robots-Tag' => 'noindex, nofollow']);
    }

    /** AI translate the draft's localized fields (HP-44; admin-keyed limiter). */
    public function translate(Request $request, int $id): JsonResponse
    {
        $page = $this->page($id);
        $source = (string) LaravelLocalization::getDefaultLocale();

        $mode = $request->input('mode') === 'all' ? 'all' : 'missing';
        $targets = $this->resolveTargets($request, $source);
        if (empty($targets)) {
            return response()->json(['ok' => false, 'message' => ___('No target locale to translate.')], 422);
        }

        if (! RateLimiter::attempt('site-pages-translate:'.auth()->id(), 30, fn () => null, 3600)) {
            return response()->json(['ok' => false, 'message' => ___('Translation rate limit reached. Try again later.')], 429);
        }

        try {
            $doc = $page->blocks ?: ['version' => 1, 'blocks' => []];
            foreach ($targets as $target) {
                $doc = app(SiteTranslator::class)
                    ->translatePage($page->setAttribute('blocks', $doc), $source, $target, $mode);
            }
            $page->blocks = $doc;
            $page->save();
        } catch (AiProviderFailure $e) {
            return response()->json(['ok' => false, 'message' => ___('No AI provider is configured or reachable.')], 502);
        }

        return response()->json(['ok' => true, 'blocks' => $page->blocks, 'message' => ___('Translated.')]);
    }

    /** Image upload for block URL fields (HP-45 — deliberate 80/20, no media library). */
    public function upload(Request $request): JsonResponse
    {
        // NO svg: this is the one image upload that writes raw via ->store() instead of
        // StorageService, so an SVG would skip sanitizeSvg() and be served inline as
        // stored XSS in the admin origin. Site-page block images are rasters; drop svg.
        $request->validate([
            'image' => 'required|image|mimes:jpg,jpeg,png,webp|max:4096',
        ]);

        $path = $request->file('image')->store('site-pages', 'public');

        return response()->json(['ok' => true, 'url' => asset('storage/'.$path)]);
    }

    // ── slug pages CRUD ─────────────────────────────────────────────────────

    public function storePage(Request $request): RedirectResponse
    {
        $site = $this->site();

        $data = $request->validate([
            'title' => 'required|string|max:120',
            'slug' => 'required|string|max:80|regex:/^[a-z0-9-]{1,80}$/',
        ]);

        $slug = strtolower($data['slug']);
        if (in_array($slug, self::RESERVED_SLUGS, true)) {
            return back()->withErrors(['slug' => ___('That URL is reserved. Choose another.')])->withInput();
        }
        if (SitePage::forSite($site->id)->where('slug', $slug)->exists()) {
            return back()->withErrors(['slug' => ___('You already have a page with that URL.')])->withInput();
        }

        $locale = (string) LaravelLocalization::getDefaultLocale();
        $page = SitePage::create([
            'site_id' => $site->id,
            'type' => 'page',
            'slug' => $slug,
            'title' => [$locale => $data['title']],
            'blocks' => ['version' => 1, 'blocks' => []],
            'seo' => [],
            'status' => 'draft',
            'sort' => (int) SitePage::forSite($site->id)->where('type', 'page')->max('sort') + 1,
        ]);

        return redirect()->route('admin.site-pages.edit', $page->id)->with('success', ___('Page created.'));
    }

    public function destroyPage(int $id): RedirectResponse
    {
        $page = $this->page($id);
        abort_if($page->type === 'home', 403, ___('The home page cannot be deleted — unpublish it instead.'));
        $page->revisions()->delete();
        $page->delete();

        return redirect()->route('admin.site-pages.index')->with('success', ___('Page deleted.'));
    }

    // ── sanitization (mirrors the reseller builder; SiteBlockRegistry-backed) ──

    /**
     * @param  mixed  $incoming
     * @return array{version: int, blocks: array<int, array<string, mixed>>}
     */
    private function sanitizeDocument($incoming): array
    {
        $doc = is_array($incoming) ? $incoming : [];
        if (! isset($doc['blocks']) && array_is_list($doc)) {
            $doc = ['blocks' => $doc];
        }
        $clean = SiteBlockRegistry::validateDocument($doc);

        foreach ($clean['blocks'] as &$block) {
            $def = SiteBlockRegistry::get($block['type']);
            $block['content'] = $this->sanitizeContent($def, $block['content']);
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $def
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function sanitizeContent(array $def, array $content): array
    {
        $out = [];
        foreach ($def['content'] as $field) {
            $name = $field['name'];
            if (! array_key_exists($name, $content)) {
                continue;
            }
            $val = $content[$name];
            $max = (int) ($field['maxlen'] ?? 4000);
            $isRich = ($field['type'] ?? '') === 'richtext';

            if (! empty($field['localized'])) {
                $out[$name] = [];
                foreach ((array) $val as $loc => $s) {
                    $out[$name][$loc] = $isRich
                        ? Html::clean((string) $s, 'richtext')
                        : mb_substr(trim((string) $s), 0, $max);
                }
            } else {
                $out[$name] = mb_substr(trim((string) (is_array($val) ? '' : $val)), 0, $max);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $val
     * @return array<string, string>
     */
    private function sanitizeLocalized(array $val, int $max): array
    {
        $out = [];
        foreach ($val as $loc => $s) {
            $out[$loc] = mb_substr(trim((string) $s), 0, $max);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $seo
     * @return array<string, mixed>
     */
    private function sanitizeSeo(array $seo): array
    {
        $out = [];
        foreach (['meta_title', 'meta_description'] as $k) {
            if (isset($seo[$k]) && is_array($seo[$k])) {
                $out[$k] = $this->sanitizeLocalized($seo[$k], 300);
            }
        }
        if (isset($seo['robots']) && is_string($seo['robots'])) {
            $out['robots'] = mb_substr(trim($seo['robots']), 0, 60);
        }

        return $out;
    }

    /** @return array<int, string> */
    private function resolveTargets(Request $request, string $source): array
    {
        $active = Language::where('active', 1)->pluck('code')->all();
        $requested = $request->input('locale');
        if ($requested && $requested !== 'all') {
            return (in_array($requested, $active, true) && $requested !== $source) ? [$requested] : [];
        }

        return array_values(array_filter($active, fn ($c) => $c !== $source));
    }
}
