<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Http\Controllers\User;

use App\Core\Support\UploadSniffer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Integrations\Models\SwitchImport;
use Juwri\Modules\Integrations\Services\Odoo\OdooClient;
use Juwri\Modules\Integrations\Services\Switching\OdooSwitchSource;
use Juwri\Modules\Integrations\Services\Switching\SwitchColumns;
use Juwri\Modules\Integrations\Services\Switching\SwitchError;
use Juwri\Modules\Integrations\Services\Switching\SwitchFile;
use Juwri\Modules\Integrations\Services\Switching\SwitchImports;

/**
 * P4-22: switching tools — bring a store's menu, customers and loyalty balances from a CSV / Excel file (juwri's
 * templates, Foodics-style and common exports) or from the previous Odoo. Always a dry run first; the owner then
 * imports it (once). The current dashboard store of its OWNER only (a staff session is refused by the staff route
 * map); an import of another store is a 404.
 */
class SwitchingController extends Controller
{
    private function store(): Store
    {
        abort_if(is_staff_dashboard(), 403);
        $current = dashboard_current_store();

        return Store::query()->where('user_id', auth()->id())->findOrFail((int) ($current->id ?? 0));
    }

    private function import(Store $store, int $id): SwitchImport
    {
        return SwitchImport::query()->where('store_id', $store->id)->findOrFail($id);
    }

    public function index(): View
    {
        $store = $this->store();
        $imports = SwitchImport::query()->where('store_id', $store->id)->orderByDesc('id')->limit(20)->get();

        return view('owner.integrations.switching', ['store' => $store, 'imports' => $imports, 'datasets' => SwitchColumns::DATASETS, 'import' => null]);
    }

    public function template(Request $request, string $dataset): Response
    {
        $this->store();
        try {
            $style = (string) $request->query('style', 'juwri');
            $csv = SwitchColumns::template($dataset, $style);
        } catch (SwitchError) {
            abort(404);
        }

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="juwri-'.$dataset.($style === 'juwri' ? '' : '-'.$style).'-template.csv"']);
    }

    public function preview(Request $request, SwitchImports $imports, OdooSwitchSource $odoo): RedirectResponse
    {
        $store = $this->store();
        $data = $request->validate([
            'dataset' => 'required|in:'.implode(',', SwitchColumns::DATASETS),
            'mode' => 'nullable|in:add,update',
            'source' => 'required|in:file,odoo',
            'file' => 'required_if:source,file|nullable|file|max:5120',
            'url' => 'required_if:source,odoo|nullable|url|max:255',
            'database' => 'required_if:source,odoo|nullable|string|max:120',
            'username' => 'required_if:source,odoo|nullable|string|max:190',
            'api_key' => 'required_if:source,odoo|nullable|string|max:255',
        ]);
        try {
            if ($data['source'] === 'odoo') {
                if (! OdooClient::urlAllowed((string) $data['url'])) {
                    return back()->withErrors(['url' => ___('The Odoo address must be a public https address.')])->withInput($request->except('api_key'));
                }
                $rows = $odoo->rows($data['dataset'], (string) $data['url'], (string) $data['database'], (string) $data['username'], (string) $data['api_key']);
                $source = 'odoo';
                $hash = null;
            } else {
                $file = $request->file('file');
                $ext = strtolower((string) $file->getClientOriginalExtension());
                if (! in_array($ext, ['csv', 'txt', 'xlsx'], true)) {
                    return back()->withErrors(['file' => ___('Upload a .csv or .xlsx file.')]);
                }
                $bytes = (string) file_get_contents($file->getRealPath());
                // P6-78: the extension is the client's word; check the bytes match it.
                $ok = $ext === 'xlsx' ? UploadSniffer::isZip($bytes) : UploadSniffer::isPlainText($bytes);
                if (! $ok) {
                    return back()->withErrors(['file' => ___('Upload a .csv or .xlsx file.')]);
                }
                $table = SwitchFile::read($bytes, $ext);
                $rows = SwitchColumns::rows($table['rows'], SwitchColumns::map($data['dataset'], $table['header']));
                $source = $ext === 'xlsx' ? 'xlsx' : 'csv';
                $hash = hash('sha256', $bytes);
            }
        } catch (SwitchError $e) {
            return back()->withErrors(['file' => $e->getMessage()])->withInput($request->except('api_key', 'file'));
        }
        $import = $imports->preview($store, (int) auth()->id(), $data['dataset'], $source, $rows, (string) ($data['mode'] ?? 'add'), $hash);

        return redirect()->route('integrations.switch.show', $import->id);
    }

    public function show(int $import): View
    {
        $store = $this->store();

        return view('owner.integrations.switching', ['store' => $store, 'import' => $this->import($store, $import),
            'imports' => collect(), 'datasets' => SwitchColumns::DATASETS]);
    }

    public function commit(int $import, SwitchImports $imports): RedirectResponse
    {
        $store = $this->store();
        try {
            $done = $imports->commit($store, $this->import($store, $import));
        } catch (SwitchError $e) {
            return back()->with('error', $e->getMessage());
        }

        return match ($done->status) {
            'committed' => redirect()->route('integrations.switch.show', $done->id)->with('success', ___('Imported.')),
            'failed' => redirect()->route('integrations.switch.show', $done->id)->with('error', (string) $done->error),
            default => redirect()->route('integrations.switch.show', $done->id)->with('success', ___('The import has started. You can leave this page; it continues in the background.')),
        };
    }

    /** P4-22b: progress of an import of the current store (polled by the page while it runs). */
    public function progress(int $import): JsonResponse
    {
        $row = $this->import($this->store(), $import);

        return response()->json([
            'status' => $row->status,
            'rows_done' => $row->rows_done,
            'rows_total' => $row->rows_total,
            'error' => $row->status === 'failed' ? $row->error : null,
            'counts' => $row->status === 'committed' ? ($row->summary['counts'] ?? null) : null,
        ])->header('Cache-Control', 'no-store');
    }

    public function discard(int $import): RedirectResponse
    {
        $store = $this->store();
        $row = $this->import($store, $import);
        if ($row->status === 'previewed') {
            $row->forceFill(['status' => 'discarded', 'rows' => null])->save();
        }

        return redirect()->route('integrations.switch.index');
    }
}
