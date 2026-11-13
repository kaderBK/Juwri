<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Http\Controllers\User;

use App\Models\ImportBatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\SsrfGuard;
use Juwri\Modules\Customers\Services\Import\CustomerImporter;
use Juwri\Modules\Integrations\Services\Import\GloriaFoodImporter;

/**
 * T7 GloriaFood migration importer (owner). Upload the GloriaFood menu CSV export →
 * dry-run preview (nothing written) → confirm to create the menu. Idempotent per file.
 */
class GloriaFoodImportController extends Controller
{
    private function storeIds(): array
    {
        return dashboard_store_ids();
    }

    private function currentRestaurantId(Request $request): int
    {
        $ids = $this->storeIds();
        $rid = (int) ($request->query('store_id') ?: $request->input('store_id') ?: (dashboard_current_store()->id ?? $ids[0] ?? 0));
        abort_unless(in_array($rid, $ids, true), 403);

        return $rid;
    }

    public function page(Request $request)
    {
        $rid = $this->currentRestaurantId($request);

        return view('owner.import.gloriafood', [
            'rid' => $rid,
            'preview' => null,
            'csv' => null,
            'batches' => ImportBatch::where('store_id', $rid)->latest('id')->limit(10)->get(),
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }

    public function preview(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:4096']]);

        $csv = file_get_contents($request->file('file')->getRealPath());
        $preview = app(GloriaFoodImporter::class)->preview($rid, $csv);

        return view('owner.import.gloriafood', [
            'rid' => $rid,
            'preview' => $preview,
            'csv' => base64_encode($csv), // carried to the confirm step (no re-upload)
            'batches' => ImportBatch::where('store_id', $rid)->latest('id')->limit(10)->get(),
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }

    public function import(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $request->validate(['csv' => ['required', 'string']]);

        $csv = base64_decode((string) $request->input('csv'), true);
        if ($csv === false) {
            app_alert_error(___('Could not read the uploaded data.'));

            return redirect()->route('stores.import.gloriafood', ['store_id' => $rid]);
        }

        $batch = app(GloriaFoodImporter::class)->import($rid, $csv);
        app_alert_success(___('Imported').": {$batch->items_created} ".___('items').", {$batch->categories_created} ".___('categories')." ({$batch->skipped} ".___('skipped').')');

        return redirect()->route('stores.import.gloriafood', ['store_id' => $rid]);
    }

    /** T7-P2: import the customer list (no marketing opt-in). */
    public function customers(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:4096']]);
        $csv = file_get_contents($request->file('file')->getRealPath());

        $batch = app(CustomerImporter::class)->import($rid, $csv);
        if ($batch->already_imported) {
            app_alert_info(___('This file was already imported. No changes were made.'));

            return redirect()->route('stores.import.gloriafood', ['store_id' => $rid]);
        }
        app_alert_success(___('Imported')." {$batch->items_created} ".___('customers')." ({$batch->skipped} ".___('skipped').'). '.___('They are not opted into marketing.'));

        return redirect()->route('stores.import.gloriafood', ['store_id' => $rid]);
    }

    /** T7-P2: fallback — scrape the public GloriaFood ordering-widget menu by URL. */
    public function widget(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $data = $request->validate(['widget_url' => ['required', 'url', 'max:512']]);

        if (! SsrfGuard::isSafePublicUrl($data['widget_url'])) {
            app_alert_error(___('That URL is not allowed.'));

            return redirect()->route('stores.import.gloriafood', ['store_id' => $rid]);
        }

        try {
            // Guard already validated the initial host; also re-validate each redirect
            // hop so a public host can't 30x the fetch onward to an internal address.
            $body = Http::timeout(10)
                ->withOptions(SsrfGuard::pinnedOptions($data['widget_url']) ?? throw new \RuntimeException('SSRF: URL is not a safe public https endpoint'))
                ->get($data['widget_url'])->body();
            $json = $this->extractWidgetJson($body);
        } catch (\Throwable $e) {
            $json = null;
        }
        if (! $json) {
            app_alert_error(___('Could not read a menu from that page.'));

            return redirect()->route('stores.import.gloriafood', ['store_id' => $rid]);
        }

        $batch = app(GloriaFoodImporter::class)->importFromWidget($rid, $json);
        app_alert_success(___('Imported').": {$batch->items_created} ".___('items').", {$batch->categories_created} ".___('categories'));

        return redirect()->route('stores.import.gloriafood', ['store_id' => $rid]);
    }

    /** Best-effort: pull an embedded menu JSON blob out of the widget page HTML. */
    private function extractWidgetJson(string $html): ?array
    {
        if (preg_match('/"categories"\s*:\s*\[.*?\]/s', $html, $m)) {
            $decoded = json_decode('{'.$m[0].'}', true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        $decoded = json_decode($html, true);

        return is_array($decoded) ? $decoded : null;
    }
}
