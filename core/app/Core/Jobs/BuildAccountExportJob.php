<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Services\Account\AccountExports;
use Juwri\Core\Services\Ops\BackupCrypto;
use Juwri\Core\Services\Tenancy\StoreDataExporter;
use Juwri\Core\Services\Tenancy\StoreDataScope;

/**
 * P4-07 (Terms 7.6, DPA 7.1/10.1): the owner's "all my data" export — the whole account as one store-data bundle
 * (every registered table, the files and media the rows own; secrets left out and listed). Written to the account's
 * own folder on the private disk ({@see AccountExports}); a half-written archive is never visible (renamed when done).
 */
class BuildAccountExportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public int $accountId, public string $token)
    {
        $this->onQueue('heavy');
    }

    public function handle(StoreDataExporter $exporter, AccountExports $exports): void
    {
        $final = $exports->path($this->accountId, $this->token);
        $part = $final.'.part';
        try {
            @mkdir(dirname($final), 0700, true);
            $exporter->export(DB::connection(), StoreDataScope::account($this->accountId), $part);
            BackupCrypto::sealInPlace($part); // P6-96b: encrypted at rest while stored; decrypted on download
            rename($part, $final);
        } catch (\Throwable $e) {
            @unlink($part);
            @file_put_contents($final.'.failed', '1');
            Log::error('Account export failed', ['account' => $this->accountId, 'error' => $e->getMessage()]);
        }
    }

    public function failed(\Throwable $e): void
    {
        $final = app(AccountExports::class)->path($this->accountId, $this->token);
        @mkdir(dirname($final), 0700, true);
        @unlink($final.'.part');
        @file_put_contents($final.'.failed', '1');
        Log::error('Account export failed', ['account' => $this->accountId, 'error' => $e->getMessage()]);
    }
}
