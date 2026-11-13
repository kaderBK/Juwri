<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Http\Controllers\Admin;

use App\Services\ResellerPages\BuilderTemplates;
use Cviebrock\EloquentSluggable\Services\SlugService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Juwri\Core\Http\Controllers\Admin\Concerns\AnswersAdminPanel;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Language;
use Juwri\Core\Support\Html;
use Juwri\Modules\Content\Http\Requests\Admin\PageRequest;
use Juwri\Modules\Content\Models\Page;

/**
 * Admin › Pages: the marketing site's CMS pages (terms, privacy, about, ...).
 * The list is per site language (a page without a language shows in every
 * list); create/edit run in the side panel. The second half of this
 * controller is the visual/trusted page editor.
 */
class PageController extends Controller
{
    use AnswersAdminPanel;

    public function __construct()
    {
        $this->authorizeResource(Page::class, 'page');
    }

    public function index(Request $request): View|RedirectResponse
    {
        if (! $request->has('lang')) {
            return redirect(url()->current().'?lang='.config('settings.lang', config('app.default_language')));
        }

        $language = Language::query()->where('code', (string) $request->query('lang'))->firstOrFail();

        $pages = Page::query()->with('language')
            ->where(fn ($q) => $q->where('translation_lang', $language->code)->orWhereNull('translation_lang'))
            ->get();

        return view('admin.pages.index', ['pages' => $pages, 'current_language' => $language->code]);
    }

    public function create(): View
    {
        return view('admin.pages.create');
    }

    public function store(PageRequest $request): JsonResponse
    {
        Page::create($this->attributes($request) + ['content' => $request->validated('page_content')]);

        return $this->panelDone(___('Created Successfully'));
    }

    public function show(Page $page): never
    {
        abort(404);
    }

    public function edit(Page $page): View
    {
        return view('admin.pages.edit', ['page' => $page]);
    }

    public function update(PageRequest $request, Page $page): JsonResponse
    {
        $attributes = $this->attributes($request);
        if (! $request->keepsBuilderContent()) {
            $attributes['content'] = $request->validated('page_content');
        }
        $page->update($attributes);

        return $this->panelDone(___('Updated Successfully'));
    }

    public function destroy(Page $page): RedirectResponse
    {
        $page->delete();
        app_alert_success(___('Deleted Successfully'));

        return back();
    }

    /**
     * Metadata shared by create and edit; an empty slug is made from the title.
     *
     * @return array<string, mixed>
     */
    private function attributes(PageRequest $request): array
    {
        $title = (string) $request->validated('page_title');
        $slug = (string) $request->validated('slug');

        return [
            'title' => $title,
            'slug' => $slug !== '' ? $slug : SlugService::createSlug(Page::class, 'slug', $title),
            'type' => $request->validated('type'),
            'translation_lang' => $request->validated('translation_lang') ?: null,
            'active' => $request->boolean('active') ? 1 : 0,
        ];
    }

    // ── Builder.js (html-mode) advanced editor ──────────────────────────────
    //
    // Ports the reseller PageBuilderController html-mode flow onto the platform
    // CMS `pages` surface. These routes live in the admin group, which already
    // runs under `csp.dashboard` (allows 'unsafe-eval' + nonce — Builder.js uses
    // eval). Client HTML is UNTRUSTED: every echo/save/publish passes through
    // Juwri\Core\Support\Html::cleanPage(), the allowlist DOM sanitizer.

    private const DEFAULT_TEMPLATE = 'Y0_Blank';

    /** Minimal starter for a fresh trusted (raw HTML+JS) page. */
    private const TRUSTED_STARTER = "<section style=\"max-width:960px;margin:0 auto;padding:48px 20px;font-family:system-ui,sans-serif\">\n  <h1>New trusted page</h1>\n  <p>Write full HTML, CSS and JavaScript here — it renders exactly as authored.</p>\n  <script>\n    // Inline JS runs (nonce-stamped automatically). Example:\n    document.currentScript.closest('section').querySelector('h1').dataset.ready = '1';\n  </script>\n</section>";

    /**
     * Open the advanced editor for a page. html-mode pages get the shared
     * Builder.js editor; a classic page first shows the template chooser
     * (picking a template is what switches it into html mode).
     */
    public function builder(Page $page): View
    {
        if ($page->isTrustedMode()) {
            // Superadmin-only raw HTML+JS editor (rendered as-authored, no sanitiser).
            return view('admin.pages.trusted-editor', [
                'page' => $page,
                'saveUrl' => route('admin.pages.builder.trustedSave', $page->id),
                'previewUrl' => url('/page/'.$page->slug),
                'backUrl' => route('admin.pages.index'),
            ]);
        }
        if (! $page->isBuilderMode()) {
            return view('admin.pages.builder-start', [
                'page' => $page,
                'templates' => BuilderTemplates::all(),
            ]);
        }

        $design = is_array($page->design_json) ? $page->design_json : [];
        $base = $design['base'] ?? self::DEFAULT_TEMPLATE;
        if (! BuilderTemplates::exists($base)) {
            $base = self::DEFAULT_TEMPLATE;
        }

        return view('builder.editor', [
            'designData' => $design['data'] ?? null,
            'draftHtml' => (string) ($page->content ?? ''),
            'baseTemplate' => $base,
            'baseUrl' => BuilderTemplates::baseUrl($base),
            'templates' => BuilderTemplates::all(),
            'builder' => [
                'title' => $page->title ?: ___('Untitled'),
                'backUrl' => route('admin.pages.index'),
                'saveUrl' => route('admin.pages.builder.save', $page->id),
                'publishUrl' => route('admin.pages.builder.publish', $page->id),
                'previewUrl' => route('admin.pages.builder.preview', $page->id),
                'assetsUrl' => route('admin.pages.builder.assets', $page->id),
                'aiUrl' => route('admin.pages.builder.ai', $page->id),
                'templateUrl' => route('admin.pages.builder.template', $page->id),
            ],
        ]);
    }

    /**
     * Switch a page into html mode, seeded from the chosen template, and open the
     * editor. Destructive: replaces the current rendered content with the template.
     */
    public function enableBuilder(Request $request, Page $page): RedirectResponse
    {
        $tpl = (string) $request->input('template', self::DEFAULT_TEMPLATE);
        if (! BuilderTemplates::exists($tpl)) {
            $tpl = self::DEFAULT_TEMPLATE;
        }

        $page->builder = 'html';
        $page->design_json = ['base' => $tpl, 'data' => BuilderTemplates::design($tpl)];
        $page->content = Html::cleanPage(BuilderTemplates::html($tpl));
        $page->save();

        return redirect()->route('admin.pages.builder', $page->id)
            ->with('success', ___('Created Successfully'));
    }

    /**
     * Switch a page into TRUSTED raw-HTML mode (superadmin/admin only — this whole
     * controller is AdminMiddleware-gated). Keeps any existing content as the raw
     * starting point, else seeds a minimal starter. Unlike html mode, content is
     * NEVER sanitised — the admin author is fully trusted.
     */
    public function enableTrusted(Request $request, Page $page): RedirectResponse
    {
        $page->builder = 'trusted';
        if (trim((string) $page->content) === '') {
            $page->content = self::TRUSTED_STARTER;
        }
        $page->save();

        return redirect()->route('admin.pages.builder', $page->id)
            ->with('success', ___('Created Successfully'));
    }

    /**
     * Trusted-mode save: store the raw HTML AS-AUTHORED. Deliberately NOT run through
     * Html::cleanPage() — the author is the trusted platform operator (admin panel).
     * The public render nonce-stamps inline <script>/<style> (Juwri\Core\Support\TrustedHtml).
     */
    public function trustedSave(Request $request, Page $page): JsonResponse
    {
        abort_unless($page->isTrustedMode(), 404);

        $page->content = (string) $request->input('html', '');
        $page->save();

        return response()->json(['ok' => true, 'saved_at' => now()->toIso8601String()]);
    }

    /**
     * html-mode autosave: store the Builder.js design + sanitized rendered HTML.
     * `content` is the ONLY thing ever echoed to visitors, so it is the XSS choke
     * point — purified through the 'page' profile here and again at echo.
     */
    public function builderSave(Request $request, Page $page): JsonResponse
    {
        abort_unless($page->isBuilderMode(), 404);

        $design = $request->input('design');
        if (is_string($design)) {
            $design = json_decode($design, true);
        }
        $base = (string) $request->input('base', self::DEFAULT_TEMPLATE);
        if (! BuilderTemplates::exists($base)) {
            $base = self::DEFAULT_TEMPLATE;
        }

        $page->design_json = ['base' => $base, 'data' => is_array($design) ? $design : null];
        $page->content = Html::cleanPage((string) $request->input('html', ''));
        $page->save();

        return response()->json(['ok' => true, 'saved_at' => now()->toIso8601String()]);
    }

    /**
     * Publish/unpublish. The classic `pages` row has no draft/published split —
     * `content` is the live rendered HTML — so publish just re-purifies (defence
     * in depth) and flips `active`.
     */
    public function builderPublish(Request $request, Page $page): JsonResponse
    {
        abort_unless($page->isBuilderMode(), 404);

        if ($request->boolean('unpublish')) {
            $page->active = false;
        } else {
            $page->content = Html::cleanPage((string) ($page->content ?? ''));
            $page->active = true;
        }
        $page->save();

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'active' => (bool) $page->active])
            : back()->with('success', ___('Saved.'));
    }

    /** Authenticated preview: the live theme page render, sanitized, noindex. */
    public function builderPreview(Page $page): Response
    {
        abort_unless($page->isBuilderMode(), 404);

        $page->content = Html::cleanPage((string) ($page->content ?? ''));
        $html = view('marketing.page', [
            'page' => $page,
            'pageHreflang' => '',
            'pageCanonical' => url()->current(),
        ])->render();

        return response($html, 200, ['X-Robots-Tag' => 'noindex, nofollow']);
    }

    /**
     * Switch to a different Builder.js template (resets the design to it). A full
     * reset+reload is required: Builder.js's getHtml() reads its own iframe, which
     * a second in-place load() leaves stale, so the shared editor reloads fresh.
     */
    public function builderTemplate(Request $request, Page $page): JsonResponse
    {
        abort_unless($page->isBuilderMode(), 400);

        $tpl = (string) $request->input('template', '');
        if (! BuilderTemplates::exists($tpl)) {
            return response()->json(['ok' => false, 'error' => ___('Could not load that template.')], 422);
        }

        $page->design_json = ['base' => $tpl, 'data' => BuilderTemplates::design($tpl)];
        $page->content = Html::cleanPage(BuilderTemplates::html($tpl));
        $page->save();

        return response()->json(['ok' => true]);
    }

    /**
     * Builder.js assetUploadHandler: store an image, return {url}. Uses the
     * platform image_upload() helper so the file lands under the served media root
     * (repo-root /storage, the docroot) — the Laravel 'public' disk does NOT serve
     * on this repo-root-docroot setup.
     */
    public function builderAsset(Request $request, Page $page): JsonResponse
    {
        abort_unless($page->isBuilderMode(), 404);
        $request->validate(['file' => 'required|file|image|max:8192']); // 8 MB

        $dir = 'storage/pages/builder/';
        $filename = image_upload($request->file('file'), $dir); // returns the stored filename only

        return response()->json(['url' => asset($dir.$filename)]);
    }

    /** Builder.js aiHandler. Gated: degrade gracefully with a non-hanging JSON. */
    public function builderAi(Request $request, Page $page): JsonResponse
    {
        abort_unless($page->isBuilderMode(), 404);

        return response()->json([
            'ok' => false,
            'error' => ___('AI assistance is not enabled for the builder yet.'),
        ], 200);
    }
}
