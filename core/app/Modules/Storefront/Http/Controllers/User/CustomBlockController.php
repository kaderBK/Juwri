<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Http\Controllers\User;

use App\Models\StoreCustomBlock;
use App\Services\ResellerPages\BuilderTemplates;
use App\Services\Storefront\StorefrontMenuCache;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Support\Html;

/**
 * Theme Studio phase A: owner-authored Builder.js blocks ("custom blocks").
 *
 * The owner lists, creates, edits (shared Builder.js editor, `builder/editor`),
 * publishes, places and deletes blocks for ONE store named by `?store=<store_id>`
 * (default: the current dashboard store) that the signed-in user owns — any
 * other store id, or a block id of another store, is a 404.
 *
 * `html` (draft) and `published_html` are the XSS choke points: both are run
 * through Html::cleanPage() on write, and the storefront re-runs it at echo
 * (BuilderRender::page). Publishing / unpublishing / re-placing / deleting a
 * published block busts the store's storefront caches.
 *
 * Routes: `auth` + `csp.dashboard` (owner dashboard group) + `feature:custom_sections`.
 */
class CustomBlockController extends Controller
{
    /** Template a new block starts from when none (or an unknown one) is picked. */
    public const DEFAULT_TEMPLATE = 'storefront-promo-banner';

    /** GET /dashboard/custom-blocks — list + create. */
    public function index(Request $request): View
    {
        $store = $this->ownedStore($request);
        $blocks = StoreCustomBlock::where('store_id', $store->id)->orderBy('id')->get();
        $limit = StoreCustomBlock::limitFor(auth()->user());

        return view('owner.marketing.custom-blocks.index', [
            'store' => $store,
            'blocks' => $blocks,
            'limit' => $limit,
            'atLimit' => $limit !== null && $blocks->count() >= $limit,
            'templates' => BuilderTemplates::forSurface('storefront'),
            'placements' => $this->placementLabels(),
            'locales' => $this->localeOptions(),
            'stores' => Store::whereIn('id', dashboard_store_ids())->orderBy('id')->get(['id', 'title']),
        ]);
    }

    /** POST — create a block from a storefront template, then open the editor. */
    public function store(Request $request): RedirectResponse
    {
        $store = $this->ownedStore($request);
        $data = $request->validate($this->metaRules() + [
            'template' => ['nullable', 'string', 'max:64'],
        ]);

        $limit = StoreCustomBlock::limitFor(auth()->user());
        $count = StoreCustomBlock::where('store_id', $store->id)->count();
        if ($limit !== null && $count >= $limit) {
            return redirect()->route('custom-blocks.index', ['store' => $store->id])
                ->with('error', ___('You have reached your plan’s custom block limit.'));
        }

        $tpl = (string) ($data['template'] ?? '');
        if (! $this->templateAllowed($tpl)) {
            $tpl = self::DEFAULT_TEMPLATE;
        }

        $block = StoreCustomBlock::create([
            'store_id' => $store->id,
            'name' => $data['name'],
            'placement' => $data['placement'] ?? null,
            'locale' => $data['locale'] ?? null,
            'status' => StoreCustomBlock::STATUS_DRAFT,
            'design_json' => json_encode(['base' => $tpl, 'data' => BuilderTemplates::design($tpl)]),
            'html' => Html::cleanPage(BuilderTemplates::html($tpl)),
            'updated_by' => auth()->id(),
        ]);

        return redirect()->route('custom-blocks.edit', ['id' => $block->id, 'store' => $store->id]);
    }

    /** GET {id}/edit — the shared Builder.js editor for this block. */
    public function edit(Request $request, int $id): View
    {
        $store = $this->ownedStore($request);
        $block = $this->block($store, $id);
        $design = $this->design($block);
        $base = $this->baseTemplate($block);
        $q = ['id' => $block->id, 'store' => $store->id];

        return view('builder.editor', [
            'designData' => $design['data'] ?? null,
            'draftHtml' => (string) ($block->html ?? ''),
            'baseTemplate' => $base,
            'baseUrl' => BuilderTemplates::baseUrl($base),
            'templates' => BuilderTemplates::forSurface('storefront'),
            'builder' => [
                'title' => (string) $block->name,
                'backUrl' => route('custom-blocks.index', ['store' => $store->id]),
                'backLabel' => ___('Back to custom blocks'),
                'saveUrl' => route('custom-blocks.save', $q),
                'publishUrl' => route('custom-blocks.publish', $q),
                'previewUrl' => route('custom-blocks.preview', $q),
                'assetsUrl' => route('custom-blocks.assets', $q),
                'aiUrl' => route('custom-blocks.ai', $q),
                'templateUrl' => route('custom-blocks.template', $q),
            ],
        ]);
    }

    /** PUT {id} — autosave the Builder.js design + sanitized draft HTML. */
    public function save(Request $request, int $id): JsonResponse
    {
        $store = $this->ownedStore($request);
        $block = $this->block($store, $id);

        $design = $request->input('design');
        if (is_string($design)) {
            $design = json_decode($design, true);
        }
        $base = (string) $request->input('base', '');
        if (! $this->templateAllowed($base)) {
            $base = $this->baseTemplate($block);
        }

        $block->design_json = json_encode(['base' => $base, 'data' => is_array($design) ? $design : null]);
        $block->html = Html::cleanPage((string) $request->input('html', ''));
        $block->updated_by = auth()->id();
        $block->save();

        return response()->json(['ok' => true, 'saved_at' => now()->toIso8601String()]);
    }

    /** POST {id}/publish — snapshot the (re-sanitized) draft for the storefront. */
    public function publish(Request $request, int $id): JsonResponse|RedirectResponse
    {
        $store = $this->ownedStore($request);
        $block = $this->block($store, $id);

        $block->published_html = Html::cleanPage((string) ($block->html ?? ''));
        $block->status = StoreCustomBlock::STATUS_PUBLISHED;
        $block->updated_by = auth()->id();
        $block->save();
        StorefrontMenuCache::bust((int) $store->id);

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['ok' => true, 'status' => 'published']);
        }

        return redirect()->route('custom-blocks.index', ['store' => $store->id])->with('success', ___('Block published.'));
    }

    /** POST {id}/unpublish — take the block off the storefront (draft is kept). */
    public function unpublish(Request $request, int $id): RedirectResponse
    {
        $store = $this->ownedStore($request);
        $block = $this->block($store, $id);

        $block->status = StoreCustomBlock::STATUS_DRAFT;
        $block->published_html = null;
        $block->updated_by = auth()->id();
        $block->save();
        StorefrontMenuCache::bust((int) $store->id);

        return redirect()->route('custom-blocks.index', ['store' => $store->id])->with('success', ___('Block hidden from your storefront.'));
    }

    /** POST {id}/settings — rename, (re)place, set the language. */
    public function update(Request $request, int $id): RedirectResponse
    {
        $store = $this->ownedStore($request);
        $block = $this->block($store, $id);
        $data = $request->validate($this->metaRules());

        $block->fill([
            'name' => $data['name'],
            'placement' => $data['placement'] ?? null,
            'locale' => $data['locale'] ?? null,
            'updated_by' => auth()->id(),
        ])->save();
        if ($block->status === StoreCustomBlock::STATUS_PUBLISHED) {
            StorefrontMenuCache::bust((int) $store->id);
        }

        return redirect()->route('custom-blocks.index', ['store' => $store->id])->with('success', ___('Block updated.'));
    }

    /** DELETE {id}. */
    public function destroy(Request $request, int $id): RedirectResponse
    {
        $store = $this->ownedStore($request);
        $block = $this->block($store, $id);
        $wasLive = $block->status === StoreCustomBlock::STATUS_PUBLISHED;
        $block->delete();
        if ($wasLive) {
            StorefrontMenuCache::bust((int) $store->id);
        }

        return redirect()->route('custom-blocks.index', ['store' => $store->id])->with('success', ___('Block deleted.'));
    }

    /** GET {id}/preview — owner DRAFT preview (sanitized + merge tags at echo). */
    public function preview(Request $request, int $id): View
    {
        $store = $this->ownedStore($request);
        $block = $this->block($store, $id);

        return view('owner.marketing.custom-blocks.preview', [
            'store' => $store,
            'block' => $block,
            'rtl' => is_rtl(),
        ]);
    }

    /** POST {id}/template — reset the draft design to a storefront template. */
    public function template(Request $request, int $id): JsonResponse
    {
        $store = $this->ownedStore($request);
        $block = $this->block($store, $id);

        $tpl = (string) $request->input('template', '');
        if (! $this->templateAllowed($tpl)) {
            return response()->json(['ok' => false, 'error' => ___('Could not load that template.')], 422);
        }

        $block->design_json = json_encode(['base' => $tpl, 'data' => BuilderTemplates::design($tpl)]);
        $block->html = Html::cleanPage(BuilderTemplates::html($tpl));
        $block->updated_by = auth()->id();
        $block->save();

        return response()->json(['ok' => true]);
    }

    /** POST {id}/assets — Builder.js image upload → {url} (served media root). */
    public function assets(Request $request, int $id): JsonResponse
    {
        $store = $this->ownedStore($request);
        $this->block($store, $id);
        $request->validate(['file' => 'required|file|image|max:8192']);

        $dir = 'storage/blocks/'.$store->id.'/';
        $filename = image_upload($request->file('file'), $dir);

        return response()->json(['url' => asset($dir.$filename)]);
    }

    /** POST {id}/ai — Builder.js aiHandler; not enabled on this surface yet. */
    public function ai(Request $request, int $id): JsonResponse
    {
        $this->block($this->ownedStore($request), $id);

        return response()->json(['ok' => false, 'error' => ___('AI assistance is not enabled for the builder yet.')]);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /** The store named by ?store= (or the current dashboard store) the user owns — else 404. */
    protected function ownedStore(Request $request): Store
    {
        $id = (int) $request->query('store', (string) $request->input('store', '0'));
        if ($id <= 0) {
            $current = dashboard_current_store();
            $id = $current && dashboard_can_access_store($current)
                ? (int) $current->id
                : (int) Store::whereIn('id', dashboard_store_ids())->orderBy('id')->value('id');
        }

        $store = $id > 0 && dashboard_can_access_store($id) ? Store::where('id', $id)->where('user_id', auth()->id())->first() : null;
        abort_unless($store !== null, 404);

        return $store;
    }

    /** A block of THIS store — another store's id is a 404. */
    protected function block(Store $store, int $id): StoreCustomBlock
    {
        $block = StoreCustomBlock::where('id', $id)->where('store_id', $store->id)->first();
        abort_unless($block !== null, 404);

        return $block;
    }

    /** @return array<string, mixed> */
    protected function design(StoreCustomBlock $block): array
    {
        $decoded = is_string($block->design_json) ? json_decode($block->design_json, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    protected function baseTemplate(StoreCustomBlock $block): string
    {
        $base = (string) ($this->design($block)['base'] ?? '');

        return $this->templateAllowed($base) ? $base : self::DEFAULT_TEMPLATE;
    }

    /** Only templates offered on this surface (storefront-* + blank). */
    protected function templateAllowed(string $id): bool
    {
        if ($id === '' || ! BuilderTemplates::exists($id)) {
            return false;
        }

        return in_array($id, array_column(BuilderTemplates::forSurface('storefront'), 'id'), true);
    }

    /** @return array<string, mixed> */
    protected function metaRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'placement' => ['nullable', 'string', Rule::in(StoreCustomBlock::PLACEMENTS)],
            'locale' => ['nullable', 'string', 'max:12', Rule::in(array_keys($this->localeOptions()))],
        ];
    }

    /** @return array<string, string> */
    protected function placementLabels(): array
    {
        return [
            'top' => ___('Top of the page'),
            'after-hero' => ___('After the header'),
            'before-menu' => ___('Before the menu'),
            'after-menu' => ___('After the menu'),
            'footer' => ___('Page footer'),
        ];
    }

    /** @return array<string, string> code => name of the platform's active languages */
    protected function localeOptions(): array
    {
        return Language::where('active', 1)->orderBy('id')->pluck('name', 'code')
            ->map(fn ($n) => (string) $n)->all();
    }
}
