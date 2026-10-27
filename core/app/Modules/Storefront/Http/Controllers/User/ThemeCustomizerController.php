<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Http\Controllers\User;

use App\Models\ImageMenu;
use App\Models\StoreOption;
use App\Services\Storefront\StorefrontMenuCache;
use App\Services\StorefrontThemeService;
use App\Services\Theme\VerticalThemeSets;
use App\Services\ThemeCustomizerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Support\UploadLimit;
use Juwri\Modules\Media\Contracts\StorageQuotas;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Models\ThemeSetting;
use Juwri\Modules\Storefront\Services\Theme\ContrastGuard;
use Juwri\Modules\Storefront\Services\Theme\PaletteFromLogo;
use Juwri\Modules\Storefront\Services\Theme\ThemeKeys;
use Juwri\Modules\Storefront\Services\Theme\Tokens;

/**
 * Platform P3: owner-facing theme customizer. Schema-driven — the owner submits
 * DATA validated against the theme's theme.json; no code is ever accepted. Draft is
 * saved for live preview, then published.
 */
class ThemeCustomizerController extends Controller
{
    private function ownRestaurant(int $storeId): Store
    {
        return Store::where('id', $storeId)->where('user_id', auth()->id())->whereIn('id', dashboard_store_ids())->firstOrFail();
    }

    /** Owner dashboard page: pick a restaurant, customize its theme. */
    public function page(?int $storeId = null)
    {
        $stores = Store::whereIn('id', dashboard_store_ids())->get(['id', 'title', 'slug']);
        // Deep link from the store-settings Design tab: ?store=<id>. Only ids the
        // owner actually has are honored; anything else falls back to the first
        // store instead of 403ing a stale link.
        $requested = $storeId ?: (int) request('store') ?: dashboard_current_store()?->id;
        $activeId = ($requested && $stores->contains('id', $requested))
            ? $requested
            : ($stores->first()->id ?? null);
        $activePost = null;
        if ($activeId) {
            $activePost = $this->ownRestaurant($activeId); // authorize
        }
        // Phase 3: theme library panel — every enabled template for the picker.
        // rev/multi-vertical Lane C (C4): scoped to the active store's vertical
        // theme set, so a retail/service store never even sees a food theme
        // (crave, matcha, …) offered, and a restaurant never sees shelf/bookla.
        // No active store yet (brand-new owner) → unfiltered, same as before.
        $templates = app(StorefrontThemeService::class)->available();
        if ($activePost) {
            $templates = app(VerticalThemeSets::class)->filterFor($templates, $activePost->store_type ?? null);
        }

        // P6-212: the Page View / Folio PDF upload hint reflects the host's
        // real per-file cap (upload_max_filesize & post_max_size), not the
        // hard-coded 20 MB claim — a shared host pinned to 8 MB will silently
        // 413 a 20 MB PDF, so we tell the owner up front.
        $hostUploadMaxMb = UploadLimit::effectiveMaxMb();

        return view('owner.design.customizer', compact('stores', 'activeId', 'templates', 'hostUploadMaxMb'));
    }

    private function template(Store $store): string
    {
        return (string) (store_options($store->id)->store_template ?? '');
    }

    public function schema(int $storeId)
    {
        $store = $this->ownRestaurant($storeId);
        $template = $this->template($store);
        $schema = ThemeCustomizerService::schemaFor($template);
        // P2-4: marketplace settings persist under the versionless key.
        $ts = ThemeSetting::where('store_id', $store->id)
            ->where('template', ThemeCustomizerService::settingsKey($template))->first();

        return response()->json([
            'template' => $template,
            // Labels live in theme.json as English data; the editor renders them
            // beside its own translated chrome, so they are localized here.
            // CC run: the inherit card's "Currently: …" sublabel is filled after
            // localization so it names the live platform default in the
            // dashboard's language (SPEC §5).
            'schema' => ThemeCustomizerService::withStoriesInheritSublabel(
                ThemeCustomizerService::localizeSchema($schema)
            ),
            'settings' => $ts->settings ?? ThemeCustomizerService::defaults($schema),
            'draft' => $ts->draft ?? null,
            'pv' => $template === 'pageview' ? $this->pageviewState($store) : null,
            // Folio panel state (rev/folio-theme/SPEC.md §3 option contract).
            'folio' => $template === 'folio' ? $this->folioState($store) : null,
            // WI-U4/K5: the preview's language tabs come from the store's OWN
            // languages, not the eleven the platform speaks.
            'languages' => $this->storeLanguages($store),
        ]);
    }

    /**
     * The languages this store's menu is published in, default first.
     *
     * Falls back to the store's default language alone, so a single-language
     * store gets one tab rather than an empty control (DECISIONS K5).
     *
     * @return array<int,array{code:string,name:string,rtl:bool}>
     */
    private function storeLanguages(Store $store): array
    {
        $default = (string) (store_options($store->id)->default_language ?? config('app.locale'));
        $codes = store_options($store->id, 'menu_languages');
        $codes = array_values(array_filter(is_array($codes) ? $codes : (array) $codes));
        if (! in_array($default, $codes, true)) {
            array_unshift($codes, $default);
        }

        $languages = Language::whereIn('code', $codes)->get(['code', 'name', 'direction']);

        $out = [];
        foreach ($codes as $code) {
            $language = $languages->firstWhere('code', $code);
            if (! $language) {
                continue;
            }
            $out[] = [
                'code' => (string) $language->code,
                'name' => (string) $language->name,
                'rtl' => strtolower((string) $language->direction) === 'rtl',
            ];
        }

        return $out;
    }

    /** Current Page View Mode B state (display source + uploaded PDF), for the owner UI. */
    private function pageviewState(Store $store): array
    {
        $opts = store_options($store->id);
        $source = $opts->restaurant_pageview_source ?? 'data';
        $pdf = $opts->restaurant_pageview_pdf ?? null;

        return [
            'source' => in_array($source, ['data', 'pdf'], true) ? $source : 'data',
            'pdf' => $pdf,
            'pdfUrl' => $pdf ? asset('storage/'.$pdf) : null,
        ];
    }

    /**
     * Page View Mode B: set the display source and (optionally) upload/replace the
     * display PDF. Tenant-scoped via ownRestaurant(). The uploaded PDF is stored on
     * the public disk and its relative path saved to store_options so the Page View
     * template can render it client-side with pdf.js.
     */
    public function pageviewSource(Request $request, int $storeId)
    {
        $store = $this->ownRestaurant($storeId);

        $request->validate([
            'source' => 'required|in:data,pdf',
            'pdf' => 'nullable|file|mimes:pdf|max:20480', // 20 MB
        ]);

        $source = $request->input('source');

        if ($request->hasFile('pdf')) {
            $pdf = $request->file('pdf');
            $ownerId = (int) $store->user_id;
            if ($ownerId && ! app(StorageQuotas::class)->allow($ownerId, $pdf->getSize())) {
                return response()->json([
                    'ok' => false,
                    'message' => ___('Storage limit reached. Delete some files or upgrade your plan.'),
                ], 422);
            }

            // Replace any previous file so we don't orphan uploads.
            $prev = store_options($store->id)->restaurant_pageview_pdf ?? null;
            if ($prev) {
                $disk = Storage::disk('public');
                $oldSize = $disk->exists($prev) ? (int) $disk->size($prev) : 0;
                $disk->delete($prev);
                if ($oldSize > 0 && $ownerId) {
                    app(StorageQuotas::class)->sub($ownerId, $oldSize);
                }
            }
            $path = $pdf->storeAs(
                'pageview',
                $store->id.'-menu.pdf',
                'public'
            );
            if ($ownerId) {
                app(StorageQuotas::class)->add($ownerId, (int) $pdf->getSize());
            }
            StoreOption::updateStoreOption($store->id, 'restaurant_pageview_pdf', $path);
        }

        StoreOption::updateStoreOption($store->id, 'restaurant_pageview_source', $source);

        return response()->json(['ok' => true, 'pv' => $this->pageviewState($store)]);
    }

    /**
     * Current Folio state — the option contract of rev/folio-theme/SPEC.md §3
     * with defaults applied — for the owner UI (dashboard PDF tab + customizer
     * panel). A missing/empty option always resolves to its documented default,
     * so a fresh store renders sanely with zero options set.
     *
     * @return array<string,mixed>
     */
    private function folioState(Store $store): array
    {
        $opts = store_options($store->id);
        $pick = static function ($value, array $allowed, string $default): string {
            $value = (string) ($value ?? '');

            return in_array($value, $allowed, true) ? $value : $default;
        };
        $hex = static function ($value, string $default): string {
            $value = (string) ($value ?? '');

            return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value) ? $value : $default;
        };
        $pdf = (string) ($opts->restaurant_folio_pdf ?? '');

        return [
            'source' => $pick($opts->restaurant_folio_source ?? null, ['images', 'pdf'], 'images'),
            'display' => $pick($opts->restaurant_folio_display ?? null, ['auto', 'single', 'double'], 'auto'),
            'hardcover' => $pick($opts->restaurant_folio_hardcover ?? null, ['0', '1'], '0'),
            'anim' => $pick($opts->restaurant_folio_anim ?? null, ['flip', 'swipe'], 'flip'),
            'tb_thumbs' => $pick($opts->restaurant_folio_tb_thumbs ?? null, ['0', '1'], '1'),
            'tb_zoom' => $pick($opts->restaurant_folio_tb_zoom ?? null, ['0', '1'], '1'),
            'tb_fullscreen' => $pick($opts->restaurant_folio_tb_fullscreen ?? null, ['0', '1'], '1'),
            'tb_download' => $pick($opts->restaurant_folio_tb_download ?? null, ['0', '1'], '0'),
            'bg' => $hex($opts->restaurant_folio_bg ?? null, '#1f2430'),
            'accent' => $hex($opts->restaurant_folio_accent ?? null, '#6d28d9'),
            // FP B2: ambient texture, page-turn sound, and the order CTA.
            'texture' => $pick($opts->restaurant_folio_texture ?? null, ['', 'linen', 'wood', 'marble', 'paper'], ''),
            'sound' => $pick($opts->restaurant_folio_sound ?? null, ['0', '1'], '0'),
            'cta' => $pick($opts->restaurant_folio_cta ?? null, ['', 'whatsapp', 'link'], ''),
            'cta_url' => (string) ($opts->restaurant_folio_cta_url ?? ''),
            'cta_label' => (string) ($opts->restaurant_folio_cta_label ?? ''),
            // FP B3: time-based books + per-page tags, plus the store's image
            // pages (id+name) the tagging UI selects from. page_books is cast to
            // an object so an empty map round-trips as {} not [].
            'books' => folio_books($store->id),
            'page_books' => (object) folio_page_book_tags($store->id),
            'pages' => $store->image_menus()->get(['id', 'name'])
                ->map(fn ($m) => ['id' => (int) $m->id, 'name' => (string) $m->name])->all(),
            // FP B8: 30-day page-view insights for the panel (empty state = zeros).
            'insights' => folio_metrics_summary($store->id),
            'pdf' => $pdf,
            'pdfUrl' => $pdf !== '' ? asset('storage/'.$pdf) : null,
        ];
    }

    /**
     * Unlink the store's current folio PDF file, if any. Basename-fenced under
     * the repo-root storage/folio-pdf/ directory so a poisoned option value can
     * never traverse elsewhere. A folio PDF is never shared between stores, so a
     * plain unlink is safe (no MediaReferences ref-count involved).
     */
    private function deleteFolioPdfFile(Store $store): void
    {
        $prev = (string) (store_options($store->id)->restaurant_folio_pdf ?? '');
        if ($prev === '') {
            return;
        }
        $path = public_path('storage/folio-pdf/'.basename($prev));
        if (is_file($path)) {
            $oldSize = (int) @filesize($path);
            @unlink($path);
            if ($oldSize > 0) {
                app(StorageQuotas::class)->sub((int) $store->user_id, $oldSize);
            }
        }
    }

    /**
     * FP B5: wipe a store's pre-rastered PDF page directory. The path is built
     * from the INT post id alone (no user input), so it can never traverse out
     * of storage/folio-pdf-pages/.
     */
    private function wipeFolioPdfPages(int $storeId): void
    {
        $dir = public_path('storage/folio-pdf-pages/'.$storeId);
        if (is_dir($dir)) {
            File::deleteDirectory($dir);
        }
    }

    /**
     * The owner dashboard PDF tab submits ordinary HTML forms (redirect + flash
     * expected), while the customizer panel calls via fetch (JSON expected) —
     * the imageMenuResponse() idiom in ManagesMenu (2026-08-17).
     *
     * @param  array{success: bool, message?: string}  $result
     */
    private function folioResponse(Request $request, Store $store, array $result): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json($result + ['folio' => $this->folioState($store)], 200);
        }

        if ($result['success']) {
            app_alert_success($result['message'] ?? ___('Saved Successfully'));
        } else {
            app_alert_error($result['message'] ?? ___('Unexpected Error'));
        }

        return redirect()->back();
    }

    /**
     * Folio: upload/replace or remove the flip-book display PDF and set the
     * content source. Mirrors pageviewSource(), but the file lives under the
     * repo-root storage/folio-pdf/ directory (public_path() IS the repo root, so
     * it is served at /storage/folio-pdf/<file> — SPEC rev/folio-theme §2), and
     * HTML form posts get a redirect + flash while AJAX callers get JSON.
     * Tenant-scoped via ownRestaurant().
     */
    public function folioPdf(Request $request, int $storeId): JsonResponse|RedirectResponse
    {
        $store = $this->ownRestaurant($storeId);

        $validator = Validator::make($request->all(), [
            'action' => ['nullable', 'in:remove'],
            'source' => ['nullable', 'in:images,pdf'],
            'pdf' => ['nullable', 'file', 'mimes:pdf', 'max:20480'], // 20 MB
        ]);
        if ($validator->fails()) {
            return $this->folioResponse($request, $store, [
                'success' => false, 'message' => implode('<br>', $validator->errors()->all()),
            ]);
        }

        if ($request->input('action') === 'remove') {
            $this->deleteFolioPdfFile($store);
            // FP B5: removing the PDF also clears any pre-rastered pages.
            $this->wipeFolioPdfPages($store->id);
            StoreOption::updateStoreOption($store->id, 'restaurant_folio_pdf', '');
            StoreOption::updateStoreOption($store->id, 'restaurant_folio_pdf_pages', '');
            StoreOption::updateStoreOption($store->id, 'restaurant_folio_source', 'images');

            return $this->folioResponse($request, $store, ['success' => true, 'message' => ___('Deleted Successfully')]);
        }

        if ($request->hasFile('pdf')) {
            $pdf = $request->file('pdf');
            $ownerId = (int) $store->user_id;
            if ($ownerId && ! app(StorageQuotas::class)->allow($ownerId, $pdf->getSize())) {
                return $this->folioResponse($request, $store, [
                    'success' => false,
                    'message' => ___('Storage limit reached. Delete some files or upgrade your plan.'),
                ]);
            }

            // Replace any previous file so we don't orphan uploads. A fresh
            // random name per upload keeps a replaced PDF from being served
            // stale out of browser caches.
            $this->deleteFolioPdfFile($store);
            // FP B5: a replacement PDF invalidates the old pre-rastered pages —
            // wipe them so the storefront falls back to the fresh PDF until the
            // panel re-rasters it.
            $this->wipeFolioPdfPages($store->id);
            StoreOption::updateStoreOption($store->id, 'restaurant_folio_pdf_pages', '');
            $name = $store->id.'-'.Str::lower(Str::random(10)).'.pdf';
            $pdfBytes = (int) $pdf->getSize(); // before move(): the file is gone from its old path after
            $pdf->move(public_path('storage/folio-pdf'), $name);
            if ($ownerId) {
                app(StorageQuotas::class)->add($ownerId, $pdfBytes);
            }
            StoreOption::updateStoreOption($store->id, 'restaurant_folio_pdf', 'folio-pdf/'.$name);
            StoreOption::updateStoreOption($store->id, 'restaurant_folio_source',
                $request->filled('source') ? $request->input('source') : 'pdf');

            return $this->folioResponse($request, $store, ['success' => true, 'message' => ___('Saved Successfully')]);
        }

        if ($request->filled('source')) {
            StoreOption::updateStoreOption($store->id, 'restaurant_folio_source', $request->input('source'));

            return $this->folioResponse($request, $store, ['success' => true, 'message' => ___('Saved Successfully')]);
        }

        return $this->folioResponse($request, $store, ['success' => false, 'message' => ___('Unexpected Error')]);
    }

    /**
     * Folio: save the flip-book display options — the option contract of
     * rev/folio-theme/SPEC.md §3, written straight to store_options so the
     * storefront reads exactly what the panel saved (publish-parity: these have
     * no draft layer; what the panel previews IS what publish writes). Request
     * keys map 1:1 to the `restaurant_folio_*` option names. Tenant-scoped via
     * ownRestaurant().
     */
    public function folioOptions(Request $request, int $storeId): JsonResponse
    {
        $store = $this->ownRestaurant($storeId);

        $validated = $request->validate([
            'source' => 'sometimes|in:images,pdf',
            'display' => 'sometimes|in:auto,single,double',
            'hardcover' => 'sometimes|in:0,1',
            'anim' => 'sometimes|in:flip,swipe',
            'tb_thumbs' => 'sometimes|in:0,1',
            'tb_zoom' => 'sometimes|in:0,1',
            'tb_fullscreen' => 'sometimes|in:0,1',
            'tb_download' => 'sometimes|in:0,1',
            'bg' => ['sometimes', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'accent' => ['sometimes', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            // FP B2: whitelist exactly like the folioState() $pick() idiom.
            'texture' => 'sometimes|in:,linen,wood,marble,paper',
            'sound' => 'sometimes|in:0,1',
            'cta' => 'sometimes|in:,whatsapp,link',
            'cta_url' => ['sometimes', 'nullable', 'required_if:cta,link', 'url', 'regex:#^https?://#i', 'max:2048'],
            'cta_label' => ['sometimes', 'nullable', 'string', 'max:60'],
        ]);

        foreach ($validated as $key => $value) {
            StoreOption::updateStoreOption($store->id, 'restaurant_folio_'.$key, (string) $value);
        }

        return response()->json(['ok' => true, 'folio' => $this->folioState($store)]);
    }

    /**
     * Folio B3: save the store's time-based "books" and their page tags.
     *
     * Request `books` is a list (≤6) of {label,start,end}; the server is
     * authoritative on the slug key (unique, suffixed on collision) so the
     * owner never types one. Request `page_books` maps imageMenuId => book INDEX
     * (0-based into the books list) — indices, not keys, so re-slugging can
     * never orphan a tag. Both are written as JSON options the storefront reads
     * via folio_books()/folio_page_book_tags(). Tenant-scoped via ownRestaurant().
     */
    public function folioBooks(Request $request, int $storeId): JsonResponse
    {
        $store = $this->ownRestaurant($storeId);

        $validated = $request->validate([
            'books' => ['nullable', 'array', 'max:6'],
            'books.*.label' => ['required', 'string', 'max:40'],
            'books.*.start' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'books.*.end' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'page_books' => ['nullable', 'array'],
        ]);

        // Slug each label into a unique key (server authoritative).
        $books = [];
        $keys = [];
        $seen = [];
        foreach (($validated['books'] ?? []) as $b) {
            $key = Str::slug((string) $b['label']);
            if ($key === '') {
                $key = 'book';
            }
            $base = $key;
            $n = 2;
            while (isset($seen[$key])) {
                $key = $base.'-'.$n;
                $n++;
            }
            $seen[$key] = true;
            $keys[] = $key;
            $books[] = [
                'key' => $key,
                'label' => (string) $b['label'],
                'start' => (string) $b['start'],
                'end' => (string) $b['end'],
            ];
        }

        // Resolve page tags: imageMenuId => bookIndex → imageMenuId => key.
        // A page id that is not the store's is a hard reject; a tag pointing at a
        // book that no longer exists is silently dropped.
        $ownedIds = ImageMenu::where('store_id', $store->id)->pluck('id')
            ->map(fn ($id) => (int) $id)->all();
        $pageBooks = [];
        foreach (($validated['page_books'] ?? []) as $imageMenuId => $bookIndex) {
            $id = (int) $imageMenuId;
            if (! in_array($id, $ownedIds, true)) {
                throw ValidationException::withMessages([
                    'page_books' => ___('That menu page does not belong to this store.'),
                ]);
            }
            $idx = (int) $bookIndex;
            if (isset($keys[$idx])) {
                $pageBooks[(string) $id] = $keys[$idx];
            }
        }

        StoreOption::updateStoreOption($store->id, 'restaurant_folio_books', (string) json_encode($books));
        StoreOption::updateStoreOption($store->id, 'restaurant_folio_page_books', (string) json_encode($pageBooks));

        return response()->json(['ok' => true, 'folio' => $this->folioState($store)]);
    }

    /**
     * Folio B4: save the WHOLE hotspot map for a store's flip-book pages.
     *
     * Request `hotspots` is `{imageMenuId: [{x,y,w,h,item}]}`. Coordinates are
     * clamped to 0–1 and rects below 0.02 on either side are dropped (stray
     * taps). A page id that is not the store's, or an item id that is not the
     * store's, is a hard reject. ≤20 rects per page (validation rule). Stored as
     * `restaurant_folio_hotspots` JSON, which folio_hotspots_payload() reads.
     * Tenant-scoped via ownRestaurant(); plain auth throttle (no upload limiter).
     */
    public function folioHotspots(Request $request, int $storeId): JsonResponse
    {
        $store = $this->ownRestaurant($storeId);

        $validated = $request->validate([
            'hotspots' => ['nullable', 'array'],
            'hotspots.*' => ['array', 'max:20'],
            'hotspots.*.*.x' => ['required', 'numeric'],
            'hotspots.*.*.y' => ['required', 'numeric'],
            'hotspots.*.*.w' => ['required', 'numeric'],
            'hotspots.*.*.h' => ['required', 'numeric'],
            'hotspots.*.*.item' => ['required', 'integer'],
        ]);

        $ownedPages = ImageMenu::where('store_id', $store->id)->pluck('id')
            ->map(fn ($id) => (int) $id)->all();
        $ownedItems = Menu::where('store_id', $store->id)->pluck('id')
            ->map(fn ($id) => (int) $id)->all();
        $clamp = static fn ($v): float => min(1.0, max(0.0, (float) $v));

        $clean = [];
        foreach (($validated['hotspots'] ?? []) as $imageMenuId => $rects) {
            $pid = (int) $imageMenuId;
            if (! in_array($pid, $ownedPages, true)) {
                throw ValidationException::withMessages([
                    'hotspots' => ___('That menu page does not belong to this store.'),
                ]);
            }
            $list = [];
            foreach ($rects as $rect) {
                $item = (int) ($rect['item'] ?? 0);
                if (! in_array($item, $ownedItems, true)) {
                    throw ValidationException::withMessages([
                        'hotspots' => ___('That menu item does not belong to this store.'),
                    ]);
                }
                $w = $clamp($rect['w']);
                $h = $clamp($rect['h']);
                if ($w < 0.02 || $h < 0.02) {
                    continue; // too small to tap — drop silently
                }
                $list[] = [
                    'x' => round($clamp($rect['x']), 4),
                    'y' => round($clamp($rect['y']), 4),
                    'w' => round($w, 4),
                    'h' => round($h, 4),
                    'item' => $item,
                ];
            }
            if ($list !== []) {
                $clean[(string) $pid] = $list;
            }
        }

        StoreOption::updateStoreOption($store->id, 'restaurant_folio_hotspots', (string) json_encode($clean));

        return response()->json(['ok' => true]);
    }

    /**
     * FP B5: receive the panel's client-rastered PDF page images and store them
     * for the storefront to serve directly (no pdf.js on the diner's device).
     *
     * The panel renders the just-uploaded PDF in the OWNER's browser to JPEGs
     * and posts them here as pages[]. The whole directory is wiped and rewritten
     * as p1.jpg … pN.jpg — every filename is built from the INT post id + an INT
     * counter (never the client's filename), mirroring folioPdf()'s basename
     * fence so a poisoned upload name can never traverse. `restaurant_folio_pdf_pages`
     * records the count; folio_pdf_pages() reads it. Tenant-scoped via ownRestaurant().
     */
    public function folioPdfPages(Request $request, int $storeId): JsonResponse
    {
        $store = $this->ownRestaurant($storeId);

        $validator = Validator::make($request->all(), [
            'pages' => ['required', 'array', 'min:1', 'max:60'],
            'pages.*' => ['file', 'image', 'mimes:jpg,jpeg,png', 'max:2048'], // 2 MB each
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false, 'message' => implode('<br>', $validator->errors()->all()),
            ], 422);
        }

        $dir = public_path('storage/folio-pdf-pages/'.$store->id);
        $this->wipeFolioPdfPages($store->id);
        File::ensureDirectoryExists($dir);

        $n = 0;
        foreach ($request->file('pages') as $file) {
            $n++;
            // Output name is int-only (p<n>.jpg) — the client filename is ignored.
            $file->move($dir, 'p'.$n.'.jpg');
        }

        StoreOption::updateStoreOption($store->id, 'restaurant_folio_pdf_pages', (string) $n);

        return response()->json(['success' => true, 'count' => $n]);
    }

    /**
     * FP B7: generate a branded cover page for the flip-book from the store's
     * own colours + logo + title, as an ImageMenu row that sorts first.
     *
     * GD-composes at 400×566 then 2× upscales to 800×1131 (the proven showcase
     * technique) using built-in GD fonts (no font files / no external deps),
     * stores it through the rid-aware storage/menu pipeline, and creates a
     * name='Cover' page at position min-1. Idempotent: the previously generated
     * row id is remembered in `restaurant_folio_cover_id`, and a regenerate
     * deletes that row first so its delete hook cleans the old file(s).
     * Tenant-scoped via ownRestaurant().
     */
    public function folioCover(Request $request, int $storeId): JsonResponse
    {
        $store = $this->ownRestaurant($storeId);

        $state = $this->folioState($store);
        $bytes = $this->composeFolioCover($store, (string) $state['bg'], (string) $state['accent']);
        if ($bytes === '') {
            return response()->json(['success' => false, 'message' => ___('Could not generate the cover.')], 422);
        }

        $tmp = (string) tempnam(sys_get_temp_dir(), 'fcover').'.png';
        file_put_contents($tmp, $bytes);
        $uploaded = new UploadedFile($tmp, 'cover.png', 'image/png', null, true);
        $name = image_upload($uploaded, 'storage/menu/', '1000', null, null, $store->id);
        if ($name) {
            folio_make_variant($uploaded, (string) $name, (int) $store->id);
        }
        @unlink($tmp);

        if (! $name) {
            return response()->json(['success' => false, 'message' => ___('Could not generate the cover.')], 422);
        }

        // Idempotent replace: drop the prior generated row. Delete the INSTANCE
        // (not a mass ->where()->delete()) so the model's `deleted` hook fires and
        // cleans the old cover file + its -w900 variant.
        $oldId = (int) (store_options($store->id, 'restaurant_folio_cover_id') ?: 0);
        if ($oldId > 0) {
            $old = ImageMenu::where('id', $oldId)->where('store_id', $store->id)->first();
            $old?->delete();
        }

        $minPos = (int) (ImageMenu::where('store_id', $store->id)->min('position') ?? 0);
        $row = ImageMenu::create([
            'store_id' => $store->id,
            'name' => 'Cover',
            'image' => $name,
            'active' => '1',
            'position' => $minPos - 1,
        ]);
        StoreOption::updateStoreOption($store->id, 'restaurant_folio_cover_id', (string) $row->id);

        return response()->json(['success' => true, 'id' => $row->id, 'image' => $name]);
    }

    /**
     * FP B7: GD-compose the cover PNG bytes at 400×566 then 2× upscale to
     * 800×1131. Built-in GD bitmap fonts only (ASCII); the store logo is centred
     * when a real local file exists, otherwise the cover composes without it.
     * Returns the PNG binary, or '' when GD is unavailable.
     */
    private function composeFolioCover(Store $store, string $bg, string $accent): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return '';
        }

        $rgb = static function (string $h): array {
            $h = ltrim(trim($h), '#');
            if (strlen($h) === 3) {
                $h = $h[0].$h[0].$h[1].$h[1].$h[2].$h[2];
            }
            if (strlen($h) !== 6) {
                $h = '1f2430';
            }

            return [(int) hexdec(substr($h, 0, 2)), (int) hexdec(substr($h, 2, 2)), (int) hexdec(substr($h, 4, 2))];
        };
        [$br, $bgc, $bb] = $rgb($bg);
        [$ar, $ag, $ab] = $rgb($accent);

        $w = 400;
        $h = 566;
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, $br, $bgc, $bb));
        $accentCol = imagecolorallocate($im, $ar, $ag, $ab);
        imagefilledrectangle($im, 0, 468, $w, 476, $accentCol);

        // Optional store logo, centred in the upper third.
        $logoRel = 'storage/store/logo/'.$store->main_image;
        if ($store->main_image && $store->main_image !== 'default.png' && is_file(public_path($logoRel))) {
            $logo = @imagecreatefromstring((string) @file_get_contents(public_path($logoRel)));
            if ($logo !== false) {
                $lw = imagesx($logo);
                $lh = imagesy($logo);
                $scale = 170 / max(1, max($lw, $lh));
                $nw = max(1, (int) ($lw * $scale));
                $nh = max(1, (int) ($lh * $scale));
                imagecopyresampled($im, $logo, (int) (($w - $nw) / 2), 150, 0, 0, $nw, $nh, $lw, $lh);
                imagedestroy($logo);
            }
        }

        // Title + subtitle in the built-in bitmap font (ASCII).
        $ink = imagecolorallocate($im, 245, 245, 245);
        $font = 5;
        $cw = imagefontwidth($font);
        $title = mb_substr((string) $store->title, 0, 40);
        $sub = mb_substr((string) ($store->sub_title ?? ''), 0, 60);
        imagestring($im, $font, (int) max(6, ($w - strlen($title) * $cw) / 2), 358, $title, $ink);
        if ($sub !== '') {
            imagestring($im, $font, (int) max(6, ($w - strlen($sub) * $cw) / 2), 384, $sub, $accentCol);
        }

        $big = imagecreatetruecolor($w * 2, $h * 2);
        imagecopyresampled($big, $im, 0, 0, 0, 0, $w * 2, $h * 2, $w, $h);

        ob_start();
        imagepng($big);
        $bytes = (string) ob_get_clean();

        imagedestroy($im);
        imagedestroy($big);

        return $bytes;
    }

    /**
     * Phase 3 (theme library): switch the store's template from inside the
     * editor. Mirrors the store-settings validation: enabled templates plus
     * the store's current one (grandfathered if disabled after selection).
     */
    public function setTemplate(Request $request, int $storeId): JsonResponse
    {
        $store = $this->ownRestaurant($storeId);
        $template = (string) $request->input('template');
        // rev/multi-vertical Lane C (C4): the enabled-template list is scoped to
        // this store's vertical theme set — a retail store cannot switch itself
        // onto crave, and a restaurant cannot switch onto shelf/bookla. The
        // store's OWN current template is always allowed (grandfathered), same
        // as the pre-existing "disabled after selection" grandfather clause.
        $available = app(VerticalThemeSets::class)->filterFor(
            app(StorefrontThemeService::class)->available(),
            $store->store_type ?? null
        );
        $allowed = array_merge(
            array_keys($available),
            array_filter([$this->template($store)])
        );
        if (! in_array($template, $allowed, true)) {
            return response()->json(['ok' => false, 'message' => 'Unknown template.'], 422);
        }
        StoreOption::updateStoreOption($store->id, 'store_template', $template);
        StorefrontMenuCache::bust($store->id); // storefront theme changed → drop its caches

        return response()->json(['ok' => true, 'template' => $template]);
    }

    public function saveDraft(Request $request, int $storeId)
    {
        $store = $this->ownRestaurant($storeId);
        $values = (array) $request->input('values', []);
        $ts = ThemeCustomizerService::saveDraft($store, $this->template($store), $values);

        return response()->json(['ok' => true, 'draft' => $ts->draft]);
    }

    public function publish(int $storeId)
    {
        $store = $this->ownRestaurant($storeId);
        $template = $this->template($store);
        $ts = ThemeCustomizerService::publish($store, $template);
        StorefrontMenuCache::bust($store->id); // published look changed → drop storefront caches

        // WI-T6 / K4: the contrast check is WARN-ONLY. Publish has already
        // happened; the warnings ride back on the response so the editor can show
        // the failing pairs and their one-click fixes without ever blocking.
        return response()->json([
            'ok' => true,
            'settings' => $ts->settings,
            'contrast' => $this->localizeContrast(
                $this->contrastFor($template, $ts->settings ?? [])
            ),
        ]);
    }

    /**
     * P3-01: a theme with dark support is judged in both schemes on the derived
     * colours too (and the save refuses a failing change); every other theme keeps the
     * four-pair warn-only check.
     *
     * @param  array<string,mixed>  $values
     * @return array<int,array<string,mixed>>
     */
    private function contrastFor(string $template, array $values): array
    {
        return Tokens::schemeMode($template, $values) !== null
            ? ContrastGuard::failures($template, $values)
            : ContrastGuard::warnings($template, $values);
    }

    /**
     * The guard names its pairs in English (they are analysis output, not owner
     * data); translate the labels for the editor the same way schema labels are
     * localized, so the whole banner reads in the dashboard's language.
     *
     * @param  array<int,array<string,mixed>>  $warnings
     * @return array<int,array<string,mixed>>
     */
    private function localizeContrast(array $warnings): array
    {
        foreach ($warnings as $i => $warning) {
            $warnings[$i]['label'] = ___((string) ($warning['label'] ?? ''));
            $warnings[$i]['pair'] = $warnings[$i]['label'];
        }

        return $warnings;
    }

    /**
     * WI-T6: preview the contrast warnings for a candidate value set, so the
     * editor can surface them BEFORE publish too. Read-only, ownership-scoped.
     */
    public function contrast(Request $request, int $storeId)
    {
        $store = $this->ownRestaurant($storeId);
        $template = $this->template($store);
        $values = ThemeKeys::fold($template, (array) $request->input('values', []));

        return response()->json([
            'ok' => true,
            'contrast' => $this->localizeContrast(
                $this->contrastFor($template, $values)
            ),
        ]);
    }

    /**
     * WI-U3: the CSS custom properties a candidate value set would produce.
     *
     * The live preview needs the derived ramp for every keystroke, and the mix
     * maths that produces it lives in one place — `Tokens`. Rather than
     * reimplement it in the editor's JavaScript and let the two drift, the editor
     * asks for the computed map and forwards it to the preview frame. Values are
     * validated against the schema exactly as a draft save is, so nothing the
     * client sends can widen what the preview may render.
     */
    public function tokens(Request $request, int $storeId)
    {
        $store = $this->ownRestaurant($storeId);
        $template = $this->template($store);
        $values = (array) $request->input('values', []);

        $values = ThemeKeys::fold($template, $values);
        $errors = ThemeCustomizerService::validate(ThemeCustomizerService::schemaFor($template), $values);

        return response()->json([
            'ok' => $errors === [],
            'errors' => $errors,
            // Invalid keys are dropped rather than failing the whole call: the
            // owner is mid-typing, and a half-finished hex should not blank the
            // preview.
            'vars' => Tokens::forTemplate(
                $template,
                array_diff_key($values, $errors)
            ),
            // P3-01: the dark block of an `auto` store (the same names, for the
            // preview frame's prefers-color-scheme: dark rule); [] otherwise.
            'dark_vars' => Tokens::darkBlock($template, array_diff_key($values, $errors)),
        ]);
    }

    /**
     * WI-S4: propose a colour palette extracted from the store's logo. Returns a
     * proposal keyed by role (a suggestion the owner applies, never auto-saved).
     */
    public function paletteFromLogo(int $storeId)
    {
        $store = $this->ownRestaurant($storeId);

        // The logo is the restaurant's main_image. Local uploads are written by
        // StorageService to public_path('storage/...') (the project-root storage
        // dir the webroot serves) — NOT Laravel's public disk, whose root is
        // core/storage/app/public and never held these files; reading the disk
        // 422'd this endpoint for every locally-stored logo. S3-stored logos are
        // not on the local filesystem, so they fall through to the same clean
        // "could not read" response as before.
        $logo = (string) ($store->main_image ?? '');
        $path = $logo !== '' && $logo !== 'default.png'
            ? public_path('storage/store/logo/'.$logo)
            : '';
        if ($path !== '' && ! is_file($path)) {
            $path = '';
        }

        $palette = $path !== '' ? PaletteFromLogo::propose($path) : null;
        if ($palette === null) {
            return response()->json(['ok' => false, 'error' => ___('Could not read a colour from your logo.')], 422);
        }

        // Shape it as the schema value ids the editor already applies.
        return response()->json(['ok' => true, 'values' => [
            'colors.primary' => $palette['primary'],
            'colors.accent' => $palette['accent'],
            'colors.background' => $palette['background'],
            'colors.surface' => $palette['surface'],
            'colors.text' => $palette['text'],
        ]]);
    }

    /**
     * WI-S1: stage a preset as the working draft. The preset's values are merged
     * over the theme defaults and saved as a draft — the owner keeps editing from
     * there ("customize from here"), and nothing is published until they publish.
     */
    public function applyPreset(Request $request, int $storeId)
    {
        $store = $this->ownRestaurant($storeId);
        $template = $this->template($store);
        $schema = ThemeCustomizerService::schemaFor($template);

        $values = ThemeCustomizerService::presetValues($schema, (string) $request->input('preset'));
        if ($values === null) {
            return response()->json(['ok' => false, 'error' => ___('Unknown preset.')], 422);
        }

        $ts = ThemeCustomizerService::saveDraft($store, $template, $values);

        return response()->json(['ok' => true, 'values' => $values, 'draft' => $ts->draft]);
    }

    /**
     * WI-V3: the owner's OTHER stores on the same template family — the eligible
     * targets for "apply to another store".
     */
    public function copyTargets(int $storeId)
    {
        $store = $this->ownRestaurant($storeId);
        $family = ThemeCustomizerService::settingsKey($this->template($store));

        $targets = [];
        foreach (Store::whereIn('id', dashboard_store_ids())->where('id', '!=', $store->id)->get(['id', 'title']) as $other) {
            if (ThemeCustomizerService::settingsKey((string) (store_options($other->id)->store_template ?? '')) === $family) {
                $targets[] = ['id' => $other->id, 'title' => (string) $other->title];
            }
        }

        return response()->json(['ok' => true, 'targets' => $targets]);
    }

    /**
     * WI-V3: copy this store's published settings onto another of the owner's
     * stores. Both ends are ownership-scoped, the target must be on the same
     * template family, and the copy STAGES a draft on the target (never
     * auto-publishes onto a live store). Image/media fields are re-validated
     * against the target rather than assumed portable.
     */
    public function copyTo(Request $request, int $storeId)
    {
        $source = $this->ownRestaurant($storeId);
        $targetId = (int) $request->input('target');
        $target = $this->ownRestaurant($targetId); // 404s if not the owner's

        $sourceTemplate = $this->template($source);
        $targetTemplate = $this->template($target);
        if (ThemeCustomizerService::settingsKey($sourceTemplate) !== ThemeCustomizerService::settingsKey($targetTemplate)) {
            return response()->json([
                'ok' => false,
                'error' => ___('That store uses a different theme, so these settings do not apply.'),
            ], 422);
        }

        $settings = ThemeSetting::where('store_id', $source->id)
            ->where('template', ThemeCustomizerService::settingsKey($sourceTemplate))->value('settings');
        $settings = is_array($settings) ? $settings : ThemeCustomizerService::defaults(ThemeCustomizerService::schemaFor($sourceTemplate));

        // Re-validate against the target (drops anything its schema rejects).
        [$accepted, $dropped] = ThemeCustomizerService::sanitizeImport($targetTemplate, $settings);
        ThemeCustomizerService::saveDraft($target, $targetTemplate, $accepted);

        return response()->json(['ok' => true, 'dropped' => $dropped, 'target' => $target->id]);
    }

    /**
     * WI-V2: download the store's published theme settings as a JSON file, so an
     * owner can archive a look or move it to another store.
     */
    public function export(int $storeId)
    {
        $store = $this->ownRestaurant($storeId);
        $template = $this->template($store);
        $ts = ThemeSetting::where('store_id', $store->id)
            ->where('template', ThemeCustomizerService::settingsKey($template))->first();

        $payload = [
            'customizer' => 'v2',
            'template' => $template,
            'exported_at' => now()->toIso8601String(),
            'settings' => $ts->settings ?? ThemeCustomizerService::defaults(ThemeCustomizerService::schemaFor($template)),
        ];

        $name = 'theme-'.($store->slug ?? $store->id).'-'.ThemeCustomizerService::settingsKey($template).'.json';

        return response()->json($payload, 200, [
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ], JSON_PRETTY_PRINT);
    }

    /**
     * WI-V2: import a settings file. It is validated against the CURRENT schema —
     * unknown keys are dropped and reported, values are schema-checked — and the
     * survivors are STAGED AS A DRAFT (never published straight in). A file from a
     * different template family is rejected cleanly.
     */
    public function import(Request $request, int $storeId)
    {
        $store = $this->ownRestaurant($storeId);
        $template = $this->template($store);

        $request->validate(['file' => 'required|file|max:512']);
        $raw = json_decode((string) $request->file('file')->get(), true);
        if (! is_array($raw) || ! is_array($raw['settings'] ?? null)) {
            return response()->json(['ok' => false, 'error' => ___('That is not a theme settings file.')], 422);
        }

        // Cross-template guard: the family must match (marketplace versionless key).
        $importedKey = ThemeCustomizerService::settingsKey((string) ($raw['template'] ?? ''));
        if ($importedKey !== '' && $importedKey !== ThemeCustomizerService::settingsKey($template)) {
            return response()->json([
                'ok' => false,
                'error' => ___('This file was exported from a different theme and cannot be imported here.'),
            ], 422);
        }

        [$accepted, $dropped] = ThemeCustomizerService::sanitizeImport($template, $raw['settings']);
        $ts = ThemeCustomizerService::saveDraft($store, $template, $accepted);

        return response()->json([
            'ok' => true,
            'values' => $accepted,
            'dropped' => $dropped,
            'draft' => $ts->draft,
        ]);
    }

    /** WI-V1: the published version history for the editor's History panel. */
    public function history(int $storeId)
    {
        $store = $this->ownRestaurant($storeId);

        return response()->json([
            'ok' => true,
            'history' => ThemeCustomizerService::versionHistory($store, $this->template($store)),
        ]);
    }

    /** WI-V1: the changed keys between one version and the live published state. */
    public function versionDiff(int $storeId, int $versionId)
    {
        $store = $this->ownRestaurant($storeId);

        return response()->json([
            'ok' => true,
            'diff' => ThemeCustomizerService::versionDiff($store, $this->template($store), $versionId),
        ]);
    }

    /** WI-V1: revert to a past version — publishes it as a new version, never destructive. */
    public function revert(int $storeId, int $versionId)
    {
        $store = $this->ownRestaurant($storeId);
        $ts = ThemeCustomizerService::revertToVersion($store, $this->template($store), $versionId);
        if ($ts === null) {
            return response()->json(['ok' => false, 'error' => ___('That version was not found.')], 404);
        }

        return response()->json(['ok' => true, 'settings' => $ts->settings]);
    }

    /** Throw away the unpublished draft and go back to what the storefront shows. */
    public function discardDraft(int $storeId)
    {
        $store = $this->ownRestaurant($storeId);
        $ts = ThemeCustomizerService::discardDraft($store, $this->template($store));

        return response()->json([
            'ok' => true,
            'settings' => $ts->settings ?? ThemeCustomizerService::defaults(
                ThemeCustomizerService::schemaFor($this->template($store))
            ),
        ]);
    }
}
