<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\Tenancy;

use App\Jobs\DispatchMerchantCampaign;
use App\Mails\MerchantCampaignMail;
use App\Mails\StoreOrderMail;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Context;
use Juwri\Core\Jobs\CreateNotificationJob;
use Juwri\Core\Mail\ResetPassword;
use Juwri\Core\Mail\StoreTransferOfferMail;
use Juwri\Core\Mail\UserDetails;
use Juwri\Core\Mail\VerifyEmail;
use Juwri\Core\Support\RequestEpoch;

/**
 * P1-04 slice 7 (J25-6, J29-4): queued work carries the tenant.
 *
 *  - Dispatch: a job (or queued mail / notification) created inside an active {@see TenantContext} carries the
 *    account + store limit in the Laravel Context payload (hidden key {@see self::KEY}, never logged).
 *  - Worker / sync queue: JobProcessing pins that subject (TenantContext::actAs) for the job; JobAttempted
 *    restores whatever context was there before (a sync job inside a request gives the request its context back).
 *  - A job dispatched outside a tenant context (platform, public, console, webhooks) runs unscoped, as before.
 *  - {@see self::PLATFORM} jobs always run unscoped, even when a tenant request dispatched them (they read
 *    platform or cross-account data by design). Every queued class must be listed in PLATFORM or TENANT —
 *    tests/Architecture/TenantJobsTest fails on an unclassified one.
 *  - P1-08b: Core may not import module classes, so a module lists its own queued classes from its provider
 *    ({@see self::classify()}); {@see self::platform()} / {@see self::tenant()} are the full lists.
 */
final class TenantJobs
{
    public const KEY = 'juwri.tenant';

    /** Queued classes that run without a tenant by design. @var list<class-string> */
    public const PLATFORM = [
        CreateNotificationJob::class,        // admin/platform notifications
        StoreTransferOfferMail::class,      // addressed across accounts
        UserDetails::class,                 // account e-mails (auth)
        ResetPassword::class,
        VerifyEmail::class,
        DispatchMerchantCampaign::class,
        MerchantCampaignMail::class,
    ];

    /** Queued classes that act for one store / account and restore the dispatcher's tenant. @var list<class-string> */
    public const TENANT = [
        StoreOrderMail::class,          // P1-13: PushPosOrderJob is classified by the Channels module
    ];

    /**
     * A module classifies its own queued classes (called from the module's service provider; P1-08b).
     *
     * @param  list<class-string>  $platform
     * @param  list<class-string>  $tenant
     */
    public static function classify(array $platform = [], array $tenant = []): void
    {
        TenantJobClasses::add($platform, $tenant);
    }

    /**
     * Every queued class that runs unscoped: Core's list + the modules'.
     *
     * @return list<class-string>
     */
    public static function platform(): array
    {
        return array_values(array_unique([...self::PLATFORM, ...TenantJobClasses::platform()]));
    }

    /**
     * Every queued class that restores the dispatcher's tenant: Core's list + the modules'.
     *
     * @return list<class-string>
     */
    public static function tenant(): array
    {
        return array_values(array_unique([...self::TENANT, ...TenantJobClasses::tenant()]));
    }

    /** @var list<array<string, mixed>> contexts saved while a job runs (sync jobs nest inside requests / jobs) */
    private static array $stack = [];

    private static ?int $memoApp = null;

    /** The stack belongs to one application instance (worker / test); a new one starts empty. */
    private static function scopeMemo(): void
    {
        $id = RequestEpoch::id();
        if (self::$memoApp !== $id) {
            self::$memoApp = $id;
            self::$stack = [];
        }
    }

    public static function register(Dispatcher $events): void
    {
        Context::dehydrating(function (ContextRepository $context): void {
            $subject = TenantContext::current()->subject();
            if ($subject !== null) {
                $context->addHidden(self::KEY, $subject);
            } else {
                $context->forgetHidden(self::KEY);
            }
        });
        // Registered after the framework's own JobProcessing listener, which hydrates the Context first.
        $events->listen(JobProcessing::class, fn (JobProcessing $e) => self::processing($e->job));
        $events->listen(JobAttempted::class, fn () => self::finished());
    }

    public static function processing(Job $job): void
    {
        self::scopeMemo();
        TenantFill::flushStores(); // another process may have re-keyed a store since the last job
        $ctx = TenantContext::current();
        self::$stack[] = $ctx->exportState();
        $subject = Context::getHidden(self::KEY);
        if (is_array($subject) && ! self::isPlatform($job)) {
            $ctx->actAs(
                isset($subject['account_id']) ? (int) $subject['account_id'] : null,
                isset($subject['store_ids']) && is_array($subject['store_ids']) ? array_map('intval', $subject['store_ids']) : null,
            );
        } else {
            $ctx->clear();
        }
    }

    public static function finished(): void
    {
        self::scopeMemo();
        $state = array_pop(self::$stack);
        if ($state !== null) {
            TenantContext::current()->importState($state);
        }
    }

    public static function isPlatform(Job $job): bool
    {
        return in_array(self::jobClass($job), self::platform(), true);
    }

    /** The class the payload runs: a job, or the mailable / notification inside a queued wrapper. */
    public static function jobClass(Job $job): string
    {
        $payload = $job->payload();

        return (string) ($payload['displayName'] ?? $job->resolveName());
    }
}
