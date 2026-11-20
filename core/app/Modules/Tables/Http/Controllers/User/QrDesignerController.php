<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Tables\Http\Controllers\User;

use App\Models\StoreTable;
use App\Support\BioLinkOutbound;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Juwri\Core\Http\Controllers\Concerns\ResolvesCurrentStore;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Modules\Tables\Models\QrDesign;
use Juwri\Modules\Tables\Services\Qr\QrPresets;
use Juwri\Modules\Tables\Services\Qr\QrRenderService;
use Juwri\Modules\Tables\Services\Qr\QrRotationService;
use Juwri\Modules\Tables\Services\Qr\QrScanGuard;
use Juwri\Modules\Tables\Services\Qr\QrSheetPdfService;

/**
 * PARITY-RUN T10: the QR Designer.
 *
 * Dashboard-only, so `feature:qr_designer` middleware on the route group is the
 * right gate here (the rule that bit this repo three times — never put
 * feature:/entitled: on a route an anonymous customer reaches — applies to the
 * STOREFRONT, which this is not). Storefront-visible QR rendering degrades to
 * the plain design through `store_qr_settings()` instead, and is never gated.
 *
 * Tenant scoping is the shared ResolvesCurrentStore trait: every action
 * resolves the acting store from ?store_id / the session store and 403s if
 * the owner does not control it.
 */
class QrDesignerController extends Controller
{
    use ResolvesCurrentStore;

    public function __construct(
        private QrRenderService $renderer,
        private QrScanGuard $guard,
        private QrRotationService $rotator,
        private QrSheetPdfService $sheets,
    ) {}

    /* ------------------------------------------------------------------ page */

    public function index(Request $request)
    {
        $store = $this->currentRestaurant($request);
        $design = QrDesign::forStore($store->id);
        $settings = $design ? $design->settings() : QrDesign::plain();

        $presets = [];
        foreach (QrPresets::all() as $key => $attrs) {
            $presets[] = [
                'key' => $key,
                'label' => ___($attrs['label']),
                'svg' => $this->renderer->svg($this->storeUrl($store), QrPresets::settings($key), ['size' => 160, 'frame' => 'none']),
            ];
        }

        return view('owner.qr.designer', [
            'store' => $store,
            'rid' => $store->id,
            'design' => $design,
            // NOT 'settings': the app-wide $settings object is shared by a view
            // composer that overrides controller data at render time. Under
            // APP_INSTALLED=false the composer never runs, so tests saw the array
            // and the real dashboard 500'd on stdClass-as-array.
            'qrSettings' => $settings,
            'presets' => $presets,
            'previewSvg' => $this->renderer->svg($this->storeUrl($store), $settings),
            'storeUrl' => $this->storeUrl($store),
            'tables' => StoreTable::where('store_id', $store->id)->orderBy('table_no')->get(),
            'branches' => Branch::where('store_id', $store->id)->orderBy('position')->get(['id', 'name']),
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
            // biolink-tpl lane-d (T2-1): the Bio-page QR card renders only when
            // the store's bio page is live; $bioSvg is its preview code.
            'bioLive' => $bioLive = (bool) BioLinkOutbound::isLive($store),
            'bioSvg' => $bioLive ? $this->renderer->svg((string) BioLinkOutbound::url($store, 'qr'), $settings, ['size' => 160, 'frame' => 'none']) : null,
        ]);
    }

    /**
     * Debounced live preview: renders the UNSAVED design and returns the guard's
     * verdict with it, so the owner sees "this will not scan" while dragging a
     * colour picker rather than after pressing Save.
     */
    public function preview(Request $request)
    {
        $store = $this->currentRestaurant($request);
        $settings = QrDesign::normalize($this->input($request));
        $verdict = $this->guard->verify($this->storeUrl($store), $settings);

        return response()->json([
            'svg' => $this->renderer->svg($this->storeUrl($store), $settings, ['size' => 320]),
            'ok' => $verdict['ok'],
            'reason' => $verdict['reason'],
            'message' => $verdict['message'],
            'contrast' => $verdict['contrast'],
            'ec' => $settings['ec'],
            // Editor-shaped echo, so clicking a preset can adopt its values instead
            // of leaving the controls showing the design the owner just replaced.
            'applied' => [
                'fg_color' => $settings['fg'],
                'bg_color' => $settings['bg'],
                'eye_color' => $settings['eye'],
                'gradient_on' => (bool) $settings['gradient_to'],
                'gradient_to' => $settings['gradient_to'] ?: '#4338ca',
                'gradient_type' => $settings['gradient_type'],
                'dot_style' => $settings['dot_style'],
                'eye_style' => $settings['eye_style'],
                'logo_file' => $settings['logo_file'] ?: '',
                'logo_scale' => $settings['logo_scale'],
                'frame' => $settings['frame'],
                'frame_text' => $settings['frame_text'] ?: '',
                'quiet_zone' => $settings['quiet_zone'],
                'size' => $settings['size'],
            ],
        ]);
    }

    /** Save the store's one design — refusing anything that fails a test scan. */
    public function save(Request $request)
    {
        $store = $this->currentRestaurant($request);
        $raw = $this->input($request);
        $settings = QrDesign::normalize($raw);

        $verdict = $this->guard->verify($this->storeUrl($store), $settings);
        if (! $verdict['ok']) {
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'message' => $verdict['message']], 422);
            }

            return back()->withInput()->with('error', $verdict['message']);
        }

        $design = QrDesign::forStore($store->id) ?: new QrDesign(['store_id' => $store->id, 'is_default' => true]);
        $design->fill([
            'store_id' => $store->id,
            'is_default' => true,
            'name' => mb_substr((string) $request->input('name', 'Default'), 0, 80) ?: 'Default',
            'preset' => in_array($request->input('preset'), QrPresets::keys(), true) ? $request->input('preset') : null,
            'fg_color' => $settings['fg'],
            'bg_color' => $settings['bg'],
            'eye_color' => $settings['eye'],
            'gradient_to' => $settings['gradient_to'],
            'gradient_type' => $settings['gradient_type'],
            'dot_style' => $settings['dot_style'],
            'eye_style' => $settings['eye_style'],
            'logo_file' => $settings['logo_file'],
            'logo_scale' => $settings['logo_scale'],
            'frame' => $settings['frame'],
            'frame_text' => $settings['frame_text'],
            'size' => $settings['size'],
            'quiet_zone' => $settings['quiet_zone'],
        ]);
        /* The previous logo_file is NOT unlinked here. storage/menu is a shared
           library — the same file routinely backs menu items — and MediaReferences
           is the only thing allowed to decide a file is orphaned. */
        $design->save();

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', ___('QR design saved. Every QR code now uses it.'));
    }

    /** Back to plain black-on-white. */
    public function reset(Request $request)
    {
        $store = $this->currentRestaurant($request);
        QrDesign::where('store_id', $store->id)->delete();

        return back()->with('success', ___('QR design reset to the classic code.'));
    }

    /* -------------------------------------------------------------- downloads */

    /** One QR as PNG or SVG. `target` = store | table | branch (+ `id`). */
    public function download(Request $request)
    {
        $store = $this->currentRestaurant($request);
        [$url, $label] = $this->target($request, $store);
        $settings = store_qr_settings($store->id, array_filter([
            'size' => $request->integer('size') ?: null,
        ]));

        if ($request->get('format') === 'svg') {
            return response($this->renderer->svg($url, $settings), 200, [
                'Content-Type' => 'image/svg+xml',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
                'Content-Disposition' => 'attachment; filename="'.$this->filename($store, $label, 'svg').'"',
            ]);
        }

        $png = $this->renderer->png($url, $settings, ['size' => max(512, $settings['size'])]);
        abort_if($png === '', 500, ___('This design could not be rendered.'));

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'attachment; filename="'.$this->filename($store, $label, 'png').'"',
        ]);
    }

    /** A4 print sheet of every table QR, with cut marks. */
    public function sheet(Request $request)
    {
        $store = $this->currentRestaurant($request);
        $settings = store_qr_settings($store->id, ['size' => 360]);

        $cells = [];
        foreach ($this->tablesFor($request, $store) as $table) {
            $cells[] = [
                'png' => $this->renderer->png(store_public_qr_url($store, $table->key), $settings),
                'label' => $this->tableLabel($table),
            ];
        }

        if (! $cells) {
            // A sheet of nothing is a confusing empty PDF; the store QR is the
            // useful fallback for a store that has not created tables yet.
            $cells[] = [
                'png' => $this->renderer->png($this->storeUrl($store), $settings),
                'label' => (string) $store->title,
            ];
        }

        $pdf = $this->sheets->sheet($cells, $store->title.' — '.___('Table QR codes'));

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->filename($store, 'tables', 'pdf').'"',
        ]);
    }

    /** Every table QR as PNG + SVG in one ZIP. */
    public function zip(Request $request)
    {
        abort_unless(class_exists(\ZipArchive::class), 501, ___('ZIP downloads are not available on this server.'));

        $store = $this->currentRestaurant($request);
        $settings = store_qr_settings($store->id, ['size' => 1024]);

        $path = tempnam(sys_get_temp_dir(), 'qrzip');
        $zip = new \ZipArchive;
        abort_unless($zip->open($path, \ZipArchive::OVERWRITE | \ZipArchive::CREATE) === true, 500);

        $zip->addFromString('store.svg', $this->renderer->svg($this->storeUrl($store), $settings));
        $zip->addFromString('store.png', $this->renderer->png($this->storeUrl($store), $settings));

        // biolink-tpl lane-d (T2-1): include the bio-page QR only when the bio
        // page is live — a dark bio leaves the archive byte-for-byte as today.
        if ($bioUrl = BioLinkOutbound::liveUrl($store, 'qr')) {
            $zip->addFromString('bio.svg', $this->renderer->svg($bioUrl, $settings));
            $zip->addFromString('bio.png', $this->renderer->png($bioUrl, $settings));
        }

        foreach ($this->tablesFor($request, $store) as $table) {
            $url = store_public_qr_url($store, $table->key);
            $name = Str::slug($this->tableLabel($table)) ?: ('table-'.$table->id);
            $zip->addFromString($name.'.svg', $this->renderer->svg($url, $settings));
            $zip->addFromString($name.'.png', $this->renderer->png($url, $settings));
        }
        $zip->close();

        return response()->download($path, $this->filename($store, 'qr-codes', 'zip'), [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    /* --------------------------------------------------------------- rotation */

    /** New key for one table — the printed code stops resolving to it. */
    public function rotateTable(Request $request)
    {
        $store = $this->currentRestaurant($request);
        $table = StoreTable::where('store_id', $store->id)->findOrFail($request->integer('table_id'));

        $this->rotator->rotateTable($table);

        return back()->with('success', ___('QR code regenerated — the old code no longer opens this table.'));
    }

    /** New keys for every table (optionally one branch). */
    public function rotateAll(Request $request)
    {
        $store = $this->currentRestaurant($request);
        $branchId = $request->integer('branch_id') ?: null;
        if ($branchId && ! Branch::where('id', $branchId)->where('store_id', $store->id)->exists()) {
            $branchId = null;
        }

        $count = $this->rotator->rotateAll($store->id, $branchId);

        return back()->with('success', ___('Regenerated :count QR codes — every printed code is now dead.', ['count' => $count]));
    }

    /* --------------------------------------------------------------- internals */

    /** Everything the designer form can send, in QrDesign::normalize()'s shape. */
    private function input(Request $request): array
    {
        // A preset click seeds the values; explicit fields then override it, so the
        // gallery is a starting point rather than a mode.
        $base = in_array($request->input('preset'), QrPresets::keys(), true)
            ? QrPresets::attributes($request->input('preset'))
            : [];

        $fields = [
            'fg_color', 'bg_color', 'eye_color', 'gradient_to', 'gradient_type',
            'dot_style', 'eye_style', 'logo_file', 'logo_scale', 'frame', 'frame_text',
            'size', 'quiet_zone',
        ];

        $given = [];
        foreach ($fields as $field) {
            if ($request->has($field) && $request->input($field) !== null && $request->input($field) !== '') {
                $given[$field] = $request->input($field);
            }
        }

        // An explicitly cleared gradient/logo must be able to turn the feature OFF,
        // which the "non-empty only" filter above would otherwise make impossible.
        foreach (['gradient_to', 'logo_file', 'eye_color', 'frame_text'] as $clearable) {
            if ($request->has($clearable) && ($request->input($clearable) === '' || $request->input($clearable) === null)) {
                $given[$clearable] = null;
            }
        }

        return $given + $base;
    }

    /** @return array{0:string,1:string} [url, label] */
    private function target(Request $request, Store $store): array
    {
        $id = $request->integer('id');

        if ($request->get('target') === 'table' && $id) {
            $table = StoreTable::where('store_id', $store->id)->findOrFail($id);

            return [store_public_qr_url($store, $table->key), $this->tableLabel($table)];
        }

        if ($request->get('target') === 'branch' && $id) {
            $branch = Branch::where('store_id', $store->id)->findOrFail($id);

            return [reseller_store_url(route('publicView', $store->slug), $store).'?branch_id='.$branch->id, (string) $branch->name]; // R4b: reseller host
        }

        // biolink-tpl lane-d (T2-1): a bio-page QR is only a valid target while
        // this store's bio page is actually live (feature+kill-switch+plan+enable
        // switch). A dark/dead bio falls through to the menu QR — today's exact
        // behavior — so a forged `target=bio` can never mint a link to a page the
        // owner has not published. The `?src=qr` bucket lets the bio page's own
        // insights count these scans; the storefront `?qr-id` exemption means a
        // bio-first interstitial can never hijack a menu QR, so this explicit bio
        // QR is the only route to the bio page from a printed code.
        if ($request->get('target') === 'bio' && ($bioUrl = BioLinkOutbound::liveUrl($store, 'qr'))) {
            return [$bioUrl, 'bio'];
        }

        return [$this->storeUrl($store), 'menu'];
    }

    /** @return \Illuminate\Support\Collection<int, StoreTable> */
    private function tablesFor(Request $request, Store $store)
    {
        $query = StoreTable::where('store_id', $store->id);
        if ($branchId = $request->integer('branch_id')) {
            $query->where('branch_id', $branchId);
        }

        return $query->orderBy('table_no')->get();
    }

    private function tableLabel(StoreTable $table): string
    {
        $label = $table->label ?: ($table->isRoom() ? ___('Room').' '.$table->table_no : ___('Table').' '.$table->table_no);

        return (string) $label;
    }

    private function storeUrl(Store $store): string
    {
        return store_public_qr_url($store, null, store_options($store->id)->qr_source_label ?? null);
    }

    private function filename(Store $store, string $label, string $ext): string
    {
        $slug = Str::slug($store->slug.'-'.$label) ?: 'qr';

        return $slug.'.'.$ext;
    }
}
