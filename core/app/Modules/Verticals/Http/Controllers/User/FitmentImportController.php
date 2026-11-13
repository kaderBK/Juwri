<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Verticals\Http\Controllers\User;

use App\Services\Entitlements;
use App\Services\Verticals\VerticalRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Verticals\Jobs\Fitment\ImportFitmentsJob;
use Juwri\Modules\Verticals\Models\FitmentImport;
use Juwri\Modules\Verticals\Services\Fitment\FitmentImporter;
use Juwri\Modules\Verticals\Services\Fitment\FitmentImportException;

/**
 * P3-08b (D12): the owner's store-wide fitment CSV import — upload, preview (counts, sample rows, the rows that
 * failed, ignored columns), confirm, status and the error report. Every action re-checks the store's ownership and its
 * `fitment` capability (a store of another type, or another owner's, is a 404); an import is only reachable through the
 * store in the URL (`store_id` + public id), so another store's import id is "not found" too.
 */
class FitmentImportController extends Controller
{
    public function __construct(private readonly FitmentImporter $importer) {}

    private function authorizeStore(Request $request, Store $store): void
    {
        abort_unless((int) $store->user_id === (int) $request->user()->id, 404);
        abort_unless(app(VerticalRegistry::class)->has($store->store_type, 'fitment'), 404);
        abort_unless(Entitlements::allows($request->user(), 'multi_vertical'), 403);
    }

    private function import(Store $store, string $id): FitmentImport
    {
        return FitmentImport::query()->where('store_id', $store->id)->where('public_id', $id)->firstOrFail();
    }

    /** GET stores/{store}/fitments/import */
    public function index(Request $request, Store $store): View
    {
        $this->authorizeStore($request, $store);

        return view('owner.catalog.fitments.import', [
            'store' => $store,
            'recent' => FitmentImport::query()->where('store_id', $store->id)->latest('id')->limit(10)->get(),
            'maxMb' => FitmentImporter::MAX_BYTES / 1048576,
            'maxRows' => FitmentImporter::MAX_ROWS,
            'inlineRows' => FitmentImporter::INLINE_ROWS,
        ]);
    }

    /** GET .../import/template.csv — a sample file with the columns. */
    public function template(Request $request, Store $store): Response
    {
        $this->authorizeStore($request, $store);

        return response(FitmentImporter::TEMPLATE, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="fitments-template.csv"',
        ]);
    }

    /** POST .../import — store the file and show what it would do (nothing is added yet). */
    public function upload(Request $request, Store $store): RedirectResponse
    {
        $this->authorizeStore($request, $store);
        $request->validate(['csv_file' => ['required', 'file', 'mimes:csv,txt', 'max:'.(FitmentImporter::MAX_BYTES / 1024)]]);

        try {
            $import = $this->importer->preview($store, (int) $request->user()->id, $request->file('csv_file'));
        } catch (FitmentImportException $e) {
            app_alert_error($e->getMessage());

            return redirect()->route('retail.fitments.import', $store->id);
        }

        return redirect()->route('retail.fitments.import.show', [$store->id, $import->public_id]);
    }

    /** GET .../import/{import} — the preview, or the state of a running / finished import. */
    public function show(Request $request, Store $store, string $import): View
    {
        $this->authorizeStore($request, $store);

        return view('owner.catalog.fitments.import-show', [
            'store' => $store,
            'import' => $this->import($store, $import),
            'inlineRows' => FitmentImporter::INLINE_ROWS,
        ]);
    }

    /** POST .../import/{import}/confirm — add the new fitments (small files now, big ones by the queue). */
    public function confirm(Request $request, Store $store, string $import): RedirectResponse
    {
        $this->authorizeStore($request, $store);
        $row = $this->import($store, $import);

        // Only a preview can be confirmed (a second click or a replayed request never adds twice).
        $claimed = FitmentImport::query()->where('id', $row->id)->where('store_id', $store->id)->where('status', 'previewed')
            ->update(['status' => $row->total_rows > FitmentImporter::INLINE_ROWS ? 'queued' : 'running']);
        if ($claimed === 0) {
            return redirect()->route('retail.fitments.import.show', [$store->id, $row->public_id]);
        }
        $row->refresh();

        if ($row->total_rows > FitmentImporter::INLINE_ROWS) {
            ImportFitmentsJob::dispatch((int) $row->id, (int) $store->id);
            app_alert_success(___('The import is queued. It can take a few minutes: refresh this page to see its progress.'));
        } else {
            $done = $this->importer->run($row);
            if ($done->status === 'done') {
                app_alert_success(___(':count fitment row(s) imported.', ['count' => $done->imported_rows]));
            } else {
                app_alert_error((string) $done->message);
            }
        }

        return redirect()->route('retail.fitments.import.show', [$store->id, $row->public_id]);
    }

    /** GET .../import/{import}/errors.csv — the rows that failed, to fix and upload again. */
    public function errors(Request $request, Store $store, string $import): Response
    {
        $this->authorizeStore($request, $store);
        $row = $this->import($store, $import);

        return response($this->importer->errorsCsv($row), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="fitment-import-problems.csv"',
        ]);
    }

    /** DELETE .../import/{import} — drop a preview (or a finished report) and its file. */
    public function destroy(Request $request, Store $store, string $import): RedirectResponse
    {
        $this->authorizeStore($request, $store);
        $row = $this->import($store, $import);
        if ($row->isOpen()) {
            app_alert_error(___('This import is still running.'));

            return redirect()->route('retail.fitments.import.show', [$store->id, $row->public_id]);
        }
        $this->importer->discard($row);

        return redirect()->route('retail.fitments.import', $store->id);
    }
}
