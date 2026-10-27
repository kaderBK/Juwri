<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Console;

use App\Services\PlatformMetrics;
use App\Support\SchedulerAlert;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Juwri\Core\Legal\LegalDocuments;
use Juwri\Core\Services\AlertDispatcher;
use Juwri\Core\Support\ScheduleSlots;
use Juwri\Modules\AppStore\Services\AppBillingService;
use Juwri\Modules\Storefront\Services\Api\StorefrontIdempotency;

class Kernel extends ConsoleKernel
{
    /** @var list<string> commands registered by class name (the rest are auto-discovered) */
    protected $commands = [
        'Juwri\Modules\Payments\Console\Commands\HourlyHousekeeping',
        'Juwri\Modules\Content\Console\Commands\DailyHousekeeping',
        'Juwri\Core\Console\Commands\CleanupUserBackups',
        'Juwri\Modules\Menu\Console\Commands\ResetSoldOut',
        'Juwri\Modules\Analytics\Console\Commands\SendWeeklyDigest',
        'Juwri\Modules\Analytics\Console\Commands\SendAnomalyAlerts',
        'Juwri\Modules\Menu\Console\Commands\ConvertToWebp',
        'Juwri\Modules\Loyalty\Console\Commands\SendBirthdayOffers',
        'Juwri\Modules\Menu\Console\Commands\PurgeTrashedMenu',
        'Juwri\Modules\Fiscal\Console\Commands\FiscalSlaWatch',
        'Juwri\Modules\Fiscal\Console\Commands\ZatcaHostedOnboard',
        'Juwri\Modules\Fiscal\Console\Commands\ZatcaCheck',
        'Juwri\Modules\Fiscal\Console\Commands\ZatcaSdkValidate',
        'Juwri\Modules\Ordering\Console\Commands\MealsGenerateDue',
        'Juwri\Modules\Analytics\Console\Commands\RollupStorePageViews', // P8
        'Juwri\Modules\Loyalty\Console\Commands\ExpireGiftCards', // B2
        'Juwri\Modules\Ordering\Console\Commands\PruneCustomerNotifications', // Lane A / A6
    ];

    /**
     * P6-134: without proc_open (typical shared host) the stock Schedule cannot start a single scheduled command, so the
     * commands run inside the cron process instead ({@see InProcessSchedule}). With proc_open nothing changes.
     */
    public function resolveConsoleSchedule()
    {
        if ($this->processesAvailable()) {
            return parent::resolveConsoleSchedule();
        }

        return tap(new InProcessSchedule($this->scheduleTimezone()), function ($schedule) {
            $this->schedule($schedule->useCache($this->scheduleCache()));
        });
    }

    /** Can this PHP start child processes? A host that lists proc_open in disable_functions makes this false. */
    protected function processesAvailable(): bool
    {
        return function_exists('proc_open');
    }

    /**
     * Define the application's command schedule.
     *
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // O3: scheduler failure alerting. Wrap a command name into an onFailure
        // callback that logs + records a scheduler.fail metric (see SchedulerAlert).
        $onFail = fn (string $command) => fn () => SchedulerAlert::fail($command);
        // P1-13: ee modules' entries are applied at their old positions by ScheduleSlots::apply('after:<previous entry>')
        // (app/Modules/<Module>/Console/<Module>Schedule); with ee absent a slot is empty.

        // O5: scheduler heartbeat — stamp every minute so scheduler_alive()
        // (and /healthz) can detect a dead `schedule:run` cron. Generous 900s TTL
        // so a brief blip isn't a false alarm, but a stopped cron goes stale.
        // P6-174: the stamp also records whether the CLI cron or the web cron ran it (scheduler_web_cron_only()).
        $schedule->call(fn () => scheduler_stamp_heartbeat())
            ->name('scheduler:heartbeat')->everyMinute();

        // Drain queued jobs on hosts that only have the scheduler cron (no
        // dedicated queue worker). Skipped whenever a DEDICATED daemon
        // heartbeat exists (queue_daemon_alive) — a second concurrent consumer
        // would race the daemon for jobs and interleave the shared
        // progress-cache writes. NOT queue_worker_alive(): that key is stamped
        // by these very drains, so gating on it let the default drain's own
        // heartbeat starve the heavy drain forever (2026-09-01 prod incident:
        // a TranslateMenuJob sat attempts=0 for 5h while the heartbeat
        // refreshed every ~181s from the empty default drain).
        // H7: the run's own budget is --max-time=50, so a 2-minute mutex is ample for a
        // clean run and self-heals within 2 minutes if the process is killed (deploy
        // restart, OOM, cron reaper) and never releases the lock. The framework default
        // is 1440 minutes — a killed run would otherwise disable the ONLY queue consumer
        // on a worker-less host for 24 hours.
        // P6-119c: the cap is per hosting profile (QUEUE_DEFAULT_MAX_TIME), kept 30 s under the default retry_after.
        $defaultMaxTime = max(10, min((int) config('hosting.default_max_time', 50), (int) config('queue.connections.'.config('queue.default', 'database').'.retry_after', 150) - 30));
        $schedule->command('queue:work --stop-when-empty --max-time='.$defaultMaxTime)
            ->everyMinute()->withoutOverlapping(intdiv($defaultMaxTime, 60) + 2)
            ->when(fn () => ! queue_daemon_alive())
            ->onFailure($onFail('queue:work'));

        // P6-123: heavy drain cap comes from the hosting profile (docker/cpanel-vps 1800, shared 600) or
        // QUEUE_HEAVY_MAX_TIME, always kept 60 s under the heavy retry_after so a job is never handed out twice.
        $heavyMaxTime = max(60, min((int) config('hosting.heavy_max_time', 1800), (int) config('queue.connections.heavy.retry_after', 1860) - 60));

        // Separate fallback drain for the `heavy` queue (WI-B3). The default
        // drain above omits --queue=heavy, so store backups (and every other
        // heavy job) sit pending forever on hosts that rely on the scheduler
        // cron instead of a dedicated worker. A long max-time is used so a big
        // backup is not killed mid-run by the 50 s budget; kept distinct from
        // the default drain and skipped when a dedicated daemon heartbeat
        // exists (queue_daemon_alive — see the note on the default drain).
        // P6-64b: dedicated `queue:work --queue=default` + `queue:work heavy --queue=heavy` workers are still the
        // recommended production setup — ship it via
        // deploy/supervisor/juwri-worker.conf (P10). When that daemon runs its
        // heartbeat makes both fallback drains above self-skip.
        // H7: --max-time=1800 (30 min) + 5 min headroom. See the note on the default drain.
        $schedule->command('queue:work heavy --queue=heavy --stop-when-empty --max-time='.$heavyMaxTime) // P6-64b: heavy connection (retry_after 1860)
            ->everyMinute()->withoutOverlapping(intdiv($heavyMaxTime, 60) + 5)
            ->when(fn () => ! queue_daemon_alive())
            ->onFailure($onFail('queue:work heavy --queue=heavy'));

        $schedule->command('cron:hourly')->hourly()->onFailure($onFail('cron:hourly'));
        ScheduleSlots::apply('after:cron:hourly', $schedule, $onFail);

        /* ---- AI actions (C6, hotel-pms) --------------------------------------
           Apply due scheduled AI menu actions and re-arm recurring ones. Hourly at
           :05 (whole-hour granularity is enough — a scheduled change is a menu edit,
           not a time-critical event), row-isolated inside the command. */
        $schedule->command('ai:run-menu-actions')->hourlyAt(5)->onFailure($onFail('ai:run-menu-actions'));
        // P6-220: over-limit branches/staff off after a downgrade/expiry, back on after an upgrade (never deletes).
        // P6-291: drop holiday / special-hours rows that no timezone can still need.
        $schedule->command('branches:prune-hour-exceptions')->dailyAt('03:30')->onFailure($onFail('branches:prune-hour-exceptions'));
        $schedule->command('plan-limits:sync')->hourlyAt(20)->withoutOverlapping(30)->onFailure($onFail('plan-limits:sync'));
        /* Blog Autopilot A7: hourly tick — drafts queued platform topics; in
           full-auto also tops up topics and publishes within the rolling 7-day
           cap. No-op unless blog_autopilot_enabled='1'. withoutOverlapping:
           a slow multi-LLM tick must never stack on the next one. */
        $schedule->command('ai:blog-autopilot')->hourlyAt(25)->withoutOverlapping(120)->onFailure($onFail('ai:blog-autopilot'));
        /* B4 (upsell-visibility): app trial reminders. Daily rather than hourly —
           the stages are whole days, and every stage is stamped once, so a
           second run the same day sends nothing. */
        $schedule->command('apps:trial-reminders')->dailyAt('09:00')->onFailure($onFail('apps:trial-reminders'));

        // P1-23: announce a newly published Terms version (config/legal.php) to every account owner, once per version,
        // 30 days' notice for a material change (Terms 14.2). JUWRI LTD's own install only (LEGAL_PAGES); a refused
        // short-notice version exits 1 and alerts.
        $schedule->command('legal:notify-terms-change')->dailyAt('09:15')
            ->when(fn () => LegalDocuments::enabled())->onFailure($onFail('legal:notify-terms-change'));

        // MC-30: daily FX snapshot for display-only currency conversion (Frankfurter).
        // A failure keeps the last-known rates, so the worst case is a stale feed.
        $schedule->command('currency:refresh-rates')->dailyAt('04:10')->onFailure($onFail('currency:refresh-rates'));
        $schedule->command('cron:daily')->daily()->onFailure($onFail('cron:daily'));
        // google-partner Lane A (A5): nightly GBP FoodMenus sync fan-out. Dark no-op when
        // no store has menu-sync enabled (dispatches zero jobs / zero HTTP).
        $schedule->command('gbp:sync-menus')->dailyAt('02:20')->withoutOverlapping(60)->onFailure($onFail('gbp:sync-menus'));
        // B2: flip lapsed gift cards active → expired so reporting/UX can see them.
        $schedule->command('giftcards:expire')->dailyAt('03:30')->onFailure($onFail('giftcards:expire'));
        // BIOLINK-RUN A6 (G8): reconcile bio_blocks.clicks_cached from bio_clicks nightly.
        $schedule->command('bio:aggregate')->dailyAt('03:40')->onFailure($onFail('bio:aggregate'));
        ScheduleSlots::apply('after:bio:aggregate', $schedule, $onFail);
        // Abuse Guard P6: prune old abuse_events (retention) nightly.
        $schedule->command('abuse:events-prune')->dailyAt('03:20')->onFailure($onFail('abuse:events-prune'));
        // Lane A / A6: customer inbox retention (read >90d, cap 200/customer, orphans).
        $schedule->command('notifications:prune-customer')->daily()->onFailure($onFail('notifications:prune-customer'));
        ScheduleSlots::apply('after:notifications:prune-customer', $schedule, $onFail);
        $schedule->command('cron:cleanup-user-backups')->daily()->onFailure($onFail('cron:cleanup-user-backups'));
        $schedule->command('backups:cleanup-store')->dailyAt('03:40')->onFailure($onFail('backups:cleanup-store'));
        $schedule->command('backups:run-scheduled')->dailyAt('03:00')->onFailure($onFail('backups:run-scheduled'));
        // O7: platform DB backup (mysql/mariadb/pgsql; a no-op on sqlite/CI).
        // C2: explicit 40-minute overlap window. A bare withoutOverlapping() is
        // Laravel's 1440-minute default, so a killed run (deploy restart, OOM)
        // leaves the mutex held and the backup self-skips for a whole DAY. Same
        // class as WI-H7 lines 62/76 — fixed here because C2 owns this line.
        // P6-202: this mutex only spaces scheduler starts; it can expire during a
        // long PHP-only dump. The command's own RunLock is what stops a second dump.
        $schedule->command('platform:db-backup')->dailyAt('02:15')->withoutOverlapping(40)->onFailure($onFail('platform:db-backup'));

        // M7: nightly off-box media mirror. Incremental; inert until the operator
        // enables `platform_media_backup_enabled` (Settings → General). Runs after
        // the DB dump so a single night's artifacts are close together in time.
        // Explicit overlap window — a bare withoutOverlapping() is Laravel's
        // 1440-minute default (WI-H7) and a killed run would self-skip for a DAY.
        $schedule->command('platform:media-backup')->dailyAt('02:45')
            ->withoutOverlapping(150)->onFailure($onFail('platform:media-backup'));

        ScheduleSlots::apply('after:platform:media-backup', $schedule, $onFail);
        // H10: fill orders.subtotal/tax_total/grand_total for rows that predate the
        // R1c snapshot migration (nullable, never backfilled) or written outside a
        // snapshotting choke point. Idempotent — snapshotTotals() writes only on a
        // real change, and by default only touches rows still missing a grand_total,
        // so a caught-up fleet is a cheap no-op scan. Explicit overlap TTL (never a
        // bare withoutOverlapping(): the arg-less call self-skips for 1440 min — WI-H7).
        $schedule->command('orders:snapshot-totals --chunk=500')->dailyAt('02:05')
            ->withoutOverlapping(120)->onFailure($onFail('orders:snapshot-totals'));

        // P6-118: password_reset_tokens was never pruned. One entry per configured password broker.
        foreach (array_keys((array) config('auth.passwords', [])) as $broker) {
            $schedule->command('auth:clear-resets '.$broker)->daily()->onFailure($onFail('auth:clear-resets '.$broker));
        }
        // O6: prune failed jobs older than 168h (7 days).
        $schedule->command('queue:prune-failed --hours=168')->weekly()->onFailure($onFail('queue:prune-failed'));
        ScheduleSlots::apply('after:queue:prune-failed', $schedule, $onFail);

        // TAX-RUN F0-P2: flag fiscal documents nearing their authority SLA.
        $schedule->command('fiscal:sla-watch')->hourly()->onFailure($onFail('fiscal:sla-watch'));
        // RB-11: ZATCA certificate expiry / config / device readiness (read-only); FAIL rows alert via the scheduler channel.
        // P6-94: --alert also sends the FAIL rows (incl. offline tills, with store + till) to the fiscal channel.
        $schedule->command('fiscal:zatca-check --alert')->dailyAt('06:10')->onFailure($onFail('fiscal:zatca-check'));
        // SYSTEMS-RUN H-P1: staff document expiry scan.
        $schedule->command('staff:documents-expiry-alert')->dailyAt('06:30')->onFailure($onFail('staff:documents-expiry-alert'));
        // SYSTEMS-RUN C-P1: recompute customer auto-tags nightly.
        $schedule->command('crm:auto-tag')->dailyAt('02:20')->onFailure($onFail('crm:auto-tag'));
        // SYSTEMS-RUN T-P2: generate the day's checklist runs.
        $schedule->command('checklists:generate')->dailyAt('04:00')->onFailure($onFail('checklists:generate'));
        ScheduleSlots::apply('after:checklists:generate', $schedule, $onFail);
        // Coupons & Wallets P6-2: nightly ledger integrity check (read-only —
        // reports drift and exits non-zero, never rewrites the append-only ledger).
        // Runs after the nightly money jobs so it sees the day's postings.
        $schedule->command('ledger:reconcile')->dailyAt('04:20')->withoutOverlapping(60)->onFailure($onFail('ledger:reconcile'));
        // P6-09 V-18 / V-20 (Community Agents module, feature:ai_phone_agent): nightly retention purge, hourly cost-anomaly check.
        $schedule->command('voice:purge-retention')->dailyAt('03:10')->onFailure($onFail('voice:purge-retention'));
        $schedule->command('voice:cost-anomaly-check')->hourly()->onFailure($onFail('voice:cost-anomaly-check'));
        // P6-33: hourly phone-agent error budget (failed calls, handoff failures, callback rejects/5xx, answer p95).
        $schedule->command('voice:error-budget-check')->hourly()->onFailure($onFail('voice:error-budget-check'));
        // SYSTEMS-RUN T-P3: remind staff of shifts starting soon.
        $schedule->command('shifts:remind')->hourly()->onFailure($onFail('shifts:remind'));
        // SYSTEMS-RUN S-P1: dispatch post-order feedback requests.
        $schedule->command('feedback:dispatch')->hourly()->onFailure($onFail('feedback:dispatch'));
        // Hourly + --scheduled so each store's sold-out items reset at ITS OWN
        // local midnight, not one global platform-midnight (store-tz correctness).
        $schedule->command('menu:reset-sold-out --scheduled')->hourly()->onFailure($onFail('menu:reset-sold-out'));
        // P8: roll up yesterday's raw store_page_views into store_page_view_daily (and prune raw
        // rows past POST_VIEW_RETENTION_DAYS, if the owner has enabled it).
        $schedule->command('postviews:rollup')->dailyAt('02:50')->withoutOverlapping(60)->onFailure($onFail('postviews:rollup'));
        // theme-pack-blade F1: spawn orders for due recurring meal plans (UNPAID).
        $schedule->command('meals:generate-due')->dailyAt('05:00')->withoutOverlapping(60)->onFailure($onFail('meals:generate-due'));
        // T1: release scheduled (pre-)orders to the kitchen at their fire time.
        $schedule->command('orders:release-scheduled')->everyMinute()->withoutOverlapping(3)->onFailure($onFail('orders:release-scheduled'));
        // P3-06b (API-10, API-32): headless hosted orders unpaid after their 15-minute window, lapsed late card holds.
        $schedule->command('orders:expire-hosted-payments')->everyMinute()->withoutOverlapping(3)->onFailure($onFail('orders:expire-hosted-payments'));
        // P3-06b: stored answers of the headless storefront API's writes are kept 24 h (idempotent retries).
        $schedule->call(fn () => StorefrontIdempotency::prune())
            ->name('storefront-api-idempotency-prune')->dailyAt('03:40')->withoutOverlapping(30)->onFailure($onFail('storefront-api-idempotency-prune'));
        // T2: deliver scheduled gift cards (birthday sends) + retry failed deliveries.
        $schedule->command('giftcards:deliver-scheduled')->everyFiveMinutes()->withoutOverlapping(8)->onFailure($onFail('giftcards:deliver-scheduled'));
        // FEATURES-5 Track C: purge orphaned (unattached, >24h) voice-note uploads.
        $schedule->command('voice-notes:prune')->dailyAt('03:40')->onFailure($onFail('voice-notes:prune'));
        // P2-6: nightly anonymized peer-benchmark cohort medians (owner dashboard tile) — P1-13 13h: ee (Analytics).
        ScheduleSlots::apply('after:voice-notes:prune', $schedule, $onFail);
        // GOOGLE-PARTNER Lane C: nightly Reserve-with-Google feed build + SFTP drop.
        // Self-gates to a no-op when the integration is dark (RwgService::enabled off).
        $schedule->command('rwg:feeds')->dailyAt('04:05')->withoutOverlapping(60)->onFailure($onFail('rwg:feeds'));
        // P5-20: nightly Google Ordering Redirect feed build + SFTP drop.
        // Self-gates to a no-op when the integration is dark (GoogleOrderingService::enabled off).
        $schedule->command('google:ordering-feeds')->dailyAt('04:15')->withoutOverlapping(60)->onFailure($onFail('google:ordering-feeds'));
        $schedule->command('analytics:send-weekly-digest')->weeklyOn(1, '08:00')->onFailure($onFail('analytics:send-weekly-digest'));
        $schedule->command('analytics:send-anomaly-alerts')->dailyAt('09:00')->onFailure($onFail('analytics:send-anomaly-alerts'));
        ScheduleSlots::apply('after:analytics:send-anomaly-alerts', $schedule, $onFail);
        // P3-4: renew paid third-party app installations (suspend on failure).
        $schedule->call(fn () => app(AppBillingService::class)->renewDue())
            ->name('apps:renew')->dailyAt('02:30')->withoutOverlapping(60)->onFailure($onFail('apps:renew'));
        ScheduleSlots::apply('after:apps:renew', $schedule, $onFail);
        // P5-3: daily platform alert evaluation (render failures, abuse signals).
        $schedule->call(fn () => PlatformMetrics::evaluateAlerts())
            ->name('platform:metrics-alerts')->dailyAt('07:00')->onFailure($onFail('platform:metrics-alerts'));

        /* ===== google-partner Lane B additive line — GBP reviews daily pull =====
           DARK no-op: gbp:pull-reviews dispatches PullGbpReviewsJob only for stores
           with a connected GBP location (Credential feature='gbp' + location_id) and
           the gbp_link entitlement; a creds-less platform dispatches zero jobs and
           makes zero HTTP calls. Only this lane edits this line. */
        $schedule->command('gbp:pull-reviews')->dailyAt('04:40')
            ->withoutOverlapping(60)->onFailure($onFail('gbp:pull-reviews'));
        /* ===== end google-partner Lane B line ===== */

        // H7: queue backlog is the one alert whose latency matters — a stalled drain
        // takes down every async side effect. Evaluate it hourly; the full daily sweep
        // at 07:00 still covers render/abuse/backup/failed-jobs.
        $schedule->call(function () {
            foreach (PlatformMetrics::queueAlerts() as $alert) {
                AlertDispatcher::send('queue', $alert);
            }
        })->name('platform:queue-alerts')->hourly()->withoutOverlapping(5)->onFailure($onFail('platform:queue-alerts'));
        ScheduleSlots::apply('after:platform:queue-alerts', $schedule, $onFail);
        $schedule->command('birthday-offers:send')->dailyAt('09:00')->onFailure($onFail('birthday-offers:send'));
        $schedule->command('marketing:win-back')->dailyAt('10:00')->onFailure($onFail('marketing:win-back'));
        $schedule->command('analytics:forecast')->dailyAt('03:30')->onFailure($onFail('analytics:forecast'));
        ScheduleSlots::apply('after:analytics:forecast', $schedule, $onFail);
        $schedule->command('menu:purge-trashed --days=30')->dailyAt('03:45')->onFailure($onFail('menu:purge-trashed')); // D4
        $schedule->command('marketing:cart-recovery')->hourly()->onFailure($onFail('marketing:cart-recovery'));
        // P6-319: recover campaigns stranded in 'sending' by a worker that died mid-build.
        $schedule->command('campaigns:sweep-stuck')->everyTenMinutes()->onFailure($onFail('campaigns:sweep-stuck'));
        $schedule->command('studio:prune')->hourly()->onFailure($onFail('studio:prune')); // Menu Studio guest-privacy purge (48h TTL)
        $schedule->command('whatsapp:purge')->dailyAt('03:00')->onFailure($onFail('whatsapp:purge'));
        ScheduleSlots::apply('after:whatsapp:purge', $schedule, $onFail);
        $schedule->command('apps:dunning')->dailyAt('09:00')->onFailure($onFail('apps:dunning')); // polish: T-3/T-1 app trial reminders
        ScheduleSlots::apply('after:apps:dunning', $schedule, $onFail);

        /* ===== HPMS lane E — growth / platform-gap daily jobs (disjoint hunk) ===== */
        // E3: soft monthly-order quota — one owner notification/month at 100% (never blocks).
        $schedule->command('quota:order-soft-notify')->dailyAt('06:20')->onFailure($onFail('quota:order-soft-notify'));
        // E4: expire loyalty points older than the store's window (negative ledger adjustment).
        $schedule->command('loyalty:expire-points')->dailyAt('03:50')->onFailure($onFail('loyalty:expire-points'));
        // E8: per-store low-stock digest (notification + optional WhatsApp), deduped.
        $schedule->command('inventory:low-stock-digest')->dailyAt('07:10')->onFailure($onFail('inventory:low-stock-digest'));
        // E11: prune api_request_logs older than 7 days.
        $schedule->command('api:prune-request-logs')->dailyAt('03:15')->onFailure($onFail('api:prune-request-logs'));
        $schedule->command('store-imports:prune')->dailyAt('03:17')->onFailure($onFail('store-imports:prune')); // P6-316
        $schedule->command('accounts:purge-deleted')->dailyAt('04:10')->withoutOverlapping(60)->onFailure($onFail('accounts:purge-deleted'));
        // BL-036 round 7: a closure whose staff step failed is retried (staff stay signed in until it succeeds).
        $schedule->command('accounts:retry-staff-close')->everyTenMinutes()->withoutOverlapping(9)->onFailure($onFail('accounts:retry-staff-close'));
        // Owner decision 2026-10-07: a deleted account's security logs go 12 months after its purge.
        $schedule->command('accounts:prune-security-logs')->dailyAt('04:25')->withoutOverlapping(60)->onFailure($onFail('accounts:prune-security-logs'));
        // P6-86 (Privacy section 8): marketing contacts inactive 24 months are removed.
        $schedule->command('marketing:prune-inactive-contacts')->dailyAt('04:30')->withoutOverlapping(60)->onFailure($onFail('marketing:prune-inactive-contacts'));
        // P4-21: Odoo connector retry queue (new rows are sent at once; this re-sends due retries with backoff).
        $schedule->command('odoo:sync-due')->everyFiveMinutes()->withoutOverlapping(8)->onFailure($onFail('odoo:sync-due'));
        // P4-22: switching-import previews nobody committed hold personal data: deleted after a day.
        $schedule->command('switch:prune-previews')->dailyAt('04:40')->onFailure($onFail('switch:prune-previews'));
        $schedule->command('owner-pwa:prune-screen-views')->dailyAt('03:25')->onFailure($onFail('owner-pwa:prune-screen-views'));
        ScheduleSlots::apply('after:owner-pwa:prune-screen-views', $schedule, $onFail);
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
