<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Providers;

use App\Models\Order;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Juwri\Core\Services\Tenancy\StoreDataRegistry;
use Juwri\Core\Services\Tenancy\TenantJobs;
use Juwri\Core\Support\ModuleRoutes;
use Juwri\Modules\Auth\Events\CredentialsChanged;
use Juwri\Modules\Auth\Events\EmailChangeUndone;
use Juwri\Modules\Integrations\Console\Commands\GbpPullReviews;
use Juwri\Modules\Integrations\Console\Commands\OdooSyncDue;
use Juwri\Modules\Integrations\Console\Commands\PruneApiRequestLogs;
use Juwri\Modules\Integrations\Console\Commands\PruneStoreImports;
use Juwri\Modules\Integrations\Console\Commands\SwitchPrunePreviews;
use Juwri\Modules\Integrations\Console\Commands\SyncGbpMenus;
use Juwri\Modules\Integrations\Jobs\CommitSwitchImportJob;
use Juwri\Modules\Integrations\Jobs\CreateGbpPostJob;
use Juwri\Modules\Integrations\Jobs\DeliverWebhookJob;
use Juwri\Modules\Integrations\Jobs\ProcessStoreImportJob;
use Juwri\Modules\Integrations\Jobs\PullGbpReviewsJob;
use Juwri\Modules\Integrations\Jobs\SendMetaCapiEventJob;
use Juwri\Modules\Integrations\Jobs\SyncGbpMenuJob;
use Juwri\Modules\Integrations\Jobs\SyncOdooRecordJob;
use Juwri\Modules\Integrations\Listeners\RevokeAccessOnEmailChangeUndo;
use Juwri\Modules\Integrations\Listeners\RevokeTokensOnCredentialChange;
use Juwri\Modules\Integrations\Services\Odoo\OdooSyncQueue;
use Juwri\Modules\Ordering\Models\OrderAdjustment;

/**
 * Integrations module provider (P1-08f).
 */
class IntegrationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        StoreDataRegistry::register('Integrations', new IntegrationsStoreData);
        ModuleRoutes::register('Integrations', __DIR__.'/../Routes');
        TenantJobs::classify(tenant: [CommitSwitchImportJob::class, CreateGbpPostJob::class, DeliverWebhookJob::class, ProcessStoreImportJob::class, PullGbpReviewsJob::class, SendMetaCapiEventJob::class, SyncGbpMenuJob::class, SyncOdooRecordJob::class]);
    }

    public function boot(): void
    {
        // P1-17: dedoc/scramble's docs routes (/docs/api, /docs/api.json) are open in the local env only; elsewhere this gate
        // decides, and it is closed unless config('api.docs_public') (API_DOCS_PUBLIC) is switched on.
        // P6-75: a password reset/change or 2FA disable revokes owner-app tokens and flags integration tokens for review.
        Event::listen(CredentialsChanged::class, RevokeTokensOnCredentialChange::class);
        // BL-036 A1a: "This wasn't me — undo" removes API access minted since the email change.
        Event::listen(EmailChangeUndone::class, RevokeAccessOnEmailChangeUndo::class);

        Gate::define('viewApiDocs', fn ($user = null) => (bool) config('api.docs_public', false));

        if ($this->app->runningInConsole()) {
            // app/Console/Kernel only auto-loads app/Console/Commands (it lists some moved commands by name).
            $this->commands([
                GbpPullReviews::class,
                PruneApiRequestLogs::class,
                PruneStoreImports::class,
                SyncGbpMenus::class,
                OdooSyncDue::class,
                SwitchPrunePreviews::class,
            ]);
        }

        // P4-21: Odoo connector — a paid order (created paid or becoming paid) and each refund / void of a connected
        // store are queued for Odoo in the same transaction (best-effort: a queueing failure never fails a payment).
        $odoo = static function (callable $fn): void {
            try {
                $fn();
            } catch (\Throwable $e) {
                report($e);
            }
        };
        Order::created(function (Order $order) use ($odoo): void {
            if ($order->is_paid) {
                $odoo(fn () => OdooSyncQueue::orderPaid($order));
            }
        });
        Order::updated(function (Order $order) use ($odoo): void {
            if ($order->wasChanged('is_paid') && $order->is_paid) {
                $odoo(fn () => OdooSyncQueue::orderPaid($order));
            }
        });
        OrderAdjustment::created(fn (OrderAdjustment $a) => $odoo(fn () => OdooSyncQueue::adjusted($a)));
    }
}
