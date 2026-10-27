<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Accounting\Providers;

use Illuminate\Support\ServiceProvider;
use Juwri\Modules\Accounting\Models\GlJournalLine;
use Juwri\Modules\Accounting\Models\GlJournal;
use Juwri\Modules\Accounting\Models\GlAccount;
use Juwri\Modules\Accounting\Models\Expense;
use App\Services\Backup\StoreBackupService;
use Juwri\Core\Services\Tenancy\StoreDataRegistry;
use Juwri\Modules\Accounting\Console\Commands\AccountingBackfill;
use Juwri\Modules\Accounting\Console\Commands\ExpensesGenerateRecurring;
use Juwri\Modules\Accounting\Console\Commands\RefreshAccountingTokens;
use Juwri\Core\Support\ModuleRoutes;
use Juwri\Modules\Accounting\Jobs\SyncInvoiceJob;
use Juwri\Core\Contracts\Accounting\GeneralLedger;
use Juwri\Core\Services\Tenancy\TenantJobs;
use Juwri\Modules\Accounting\Services\Edition\AccountingLedger;
use Juwri\Modules\Accounting\Console\AccountingSchedule;

/**
 * Accounting module provider (P1-08e).
 */
class AccountingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        AccountingSchedule::register(); // P1-13: its console schedule entries (were in app/Console/Kernel)
        // P1-13: the store backup's financial tables owned here (were listed in StoreBackupService).
        StoreBackupService::extend('Accounting', 'suppliers', [
            'expenses' => ['model' => Expense::class, 'scope' => 'store_id', 'dataset' => 'financials', 'pii' => false],
            'gl_accounts' => ['model' => GlAccount::class, 'scope' => 'store_id', 'dataset' => 'financials', 'pii' => false],
            'gl_journals' => ['model' => GlJournal::class, 'scope' => 'store_id', 'dataset' => 'financials', 'pii' => false],
            'gl_journal_lines' => ['model' => GlJournalLine::class, 'parent' => ['journal_id', GlJournal::class, 'store_id'], 'dataset' => 'financials', 'pii' => false],
        ]);
        StoreDataRegistry::register('Accounting', new AccountingStoreData);
        $this->app->singleton(GeneralLedger::class, AccountingLedger::class);
        TenantJobs::classify(tenant: [SyncInvoiceJob::class]); // P1-13: was listed in Core's TenantJobs
        ModuleRoutes::register('Accounting', __DIR__.'/../Routes');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // app/Console/Kernel only auto-loads app/Console/Commands.
            $this->commands([AccountingBackfill::class, ExpensesGenerateRecurring::class, RefreshAccountingTokens::class]);
        }
    }
}
