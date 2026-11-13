<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Services\Switching;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Models\Store;
use Juwri\Modules\Integrations\Jobs\CommitSwitchImportJob;
use Juwri\Modules\Integrations\Models\SwitchImport;

/**
 * P4-22: preview (dry run, nothing written to the store) → commit (once). The rows of a preview wait encrypted in the
 * store's own import row; the commit runs the same planner in chunked transactions (queued for big files, with
 * progress on the row) and clears them.
 */
final class SwitchImports
{
    public function __construct(private readonly SwitchPlanner $planner) {}

    /** @param list<array<string, string>> $rows */
    public function preview(Store $store, int $userId, string $dataset, string $source, array $rows, string $mode, ?string $hash): SwitchImport
    {
        $dataset = SwitchColumns::dataset($dataset);
        $mode = $mode === 'update' && $dataset === 'menu' ? 'update' : 'add';

        return SwitchImport::create([
            'store_id' => $store->id, 'user_id' => $userId, 'dataset' => $dataset, 'source' => $source, 'mode' => $mode, 'file_hash' => $hash,
            'status' => 'previewed', 'rows' => $rows, 'plan' => $this->planner->run($dataset, $store, $rows, false, $mode, $source),
        ]);
    }

    /** Up to this many rows the commit runs in the request; more go to the queue (CommitSwitchImportJob). */
    public const SYNC_ROWS = 300;

    /** Rows per transaction of a commit. */
    public const CHUNK = 200;

    /**
     * Start the commit — ONCE: the row lock + status move (previewed, or failed to resume) → queued means a double
     * click, a second tab or a retried request finds "queued" / "running" / "committed" and is refused. Small imports
     * finish here; big ones return "queued" and the job writes progress to the row.
     */
    public function commit(Store $store, SwitchImport $import): SwitchImport
    {
        $queued = DB::transaction(function () use ($store, $import) {
            $locked = SwitchImport::query()->where('store_id', $store->id)->lockForUpdate()->findOrFail($import->id);
            if (! in_array($locked->status, ['previewed', 'failed'], true)) {
                throw new SwitchError(in_array($locked->status, ['queued', 'running'], true)
                    ? ___('This import is already running.') : ___('This import was already done.'));
            }
            if ($locked->rows === null) {
                throw new SwitchError(___('The import stopped and was not continued within a day. Upload the file again.'));
            }
            $locked->forceFill(['status' => 'queued', 'rows_total' => count($locked->rows ?? []), 'error' => null])->save();

            return $locked;
        });
        if ($queued->rows_total - $queued->rows_done <= self::SYNC_ROWS) {
            return $this->run($store, $queued->id);
        }
        CommitSwitchImportJob::dispatch($queued->id, (int) $store->id);

        return $queued;
    }

    /**
     * Write a queued import in chunks (the job, or commit() for small files). A cache lock keyed by the import makes a
     * second worker / duplicate job a no-op; every chunk, its summary and rows_done commit together, so a failure
     * leaves the rows before it imported and the import "failed" — committing it again resumes at rows_done and never
     * repeats a row. The store comes from the caller (the job re-resolves it), never from the import row.
     */
    public function run(Store $store, int $importId): SwitchImport
    {
        $lock = Cache::lock('switch-import-commit:'.$importId, 3600);
        if (! $lock->get()) {
            return SwitchImport::query()->where('store_id', $store->id)->findOrFail($importId);
        }
        try {
            $import = SwitchImport::query()->where('store_id', $store->id)->findOrFail($importId);
            if (! in_array($import->status, ['queued', 'running'], true)) {
                return $import;
            }
            $import->forceFill(['status' => 'running'])->save();
            try {
                while (true) {
                    $more = DB::transaction(function () use ($store, $importId) {
                        $row = SwitchImport::query()->where('store_id', $store->id)->lockForUpdate()->findOrFail($importId);
                        $rows = $row->rows ?? [];
                        $chunk = array_slice($rows, $row->rows_done, self::CHUNK);
                        if ($chunk === []) {
                            $row->forceFill(['status' => 'committed', 'rows' => null, 'rows_done' => count($rows), 'committed_at' => now()])->save();

                            return false;
                        }
                        $part = $this->planner->run($row->dataset, $store, $chunk, true, $row->mode, $row->source);
                        $row->forceFill(['summary' => self::merge($row->summary, $part), 'rows_done' => $row->rows_done + count($chunk)])->save();

                        return true;
                    });
                    if (! $more) {
                        break;
                    }
                }
            } catch (\Throwable $e) {
                if (! $e instanceof SwitchError) {
                    Log::error('Switching import failed', ['import' => $importId, 'store' => $store->id, 'error' => $e->getMessage()]);
                }
                SwitchImport::query()->where('store_id', $store->id)->whereKey($importId)->update(['status' => 'failed',
                    'error' => mb_substr($e instanceof SwitchError ? $e->getMessage() : ___('The import stopped. The rows before it are imported; import again to continue.'), 0, 255)]);
            }

            return SwitchImport::query()->where('store_id', $store->id)->findOrFail($importId);
        } finally {
            $lock->release();
        }
    }

    /**
     * Add one chunk's result to the summary so far: counts summed, preview rows kept up to PREVIEW_ROWS, errors kept.
     *
     * @param  array<string, mixed>|null  $sum
     * @param  array<string, mixed>  $part
     * @return array<string, mixed>
     */
    public static function merge(?array $sum, array $part): array
    {
        if ($sum === null) {
            return $part;
        }
        $counts = (array) ($sum['counts'] ?? []);
        foreach ((array) $part['counts'] as $k => $n) {
            $counts[$k] = (int) ($counts[$k] ?? 0) + (int) $n;
        }

        return ['counts' => $counts,
            'rows' => array_slice([...(array) ($sum['rows'] ?? []), ...(array) $part['rows']], 0, SwitchPlanner::PREVIEW_ROWS),
            'errors' => [...(array) ($sum['errors'] ?? []), ...(array) $part['errors']]];
    }

    /** Previews nobody committed: deleted after a day (their rows hold personal data). */
    public static function prune(): int
    {
        SwitchImport::query()->where('status', 'failed')->whereNotNull('rows')->where('updated_at', '<', now()->subDay())
            ->update(['rows' => null, 'error' => mb_substr(___('The import stopped and was not continued within a day. Upload the file again.'), 0, 255)]);

        return SwitchImport::query()->where('status', 'previewed')->where('created_at', '<', now()->subDay())->delete();
    }
}
