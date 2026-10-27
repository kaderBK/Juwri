<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Providers;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

/**
 * P1-17 (J25-B): central queue routing with Queue::route(). One place says which queue a job class runs on; a class with
 * no entry stays on its connection's default queue.
 *
 * Today's jobs are routed to the queues they ALREADY use (no queue name changes): the slow AI / Chrome / backup / import
 * work goes to "heavy" (the lane the scheduler drains and Abuse Guard P5 counts), the blog autopilot jobs to the
 * configurable blog_autopilot.queue (default "heavy"). The jobs still call onQueue() themselves; an explicit onQueue()
 * wins over a route, so both say the same thing now and the constructors can drop theirs later.
 *
 * Class names are strings on purpose: Core must not import module classes (module-boundary rules), and the test
 * (QueueRoutingTest) checks every name still resolves to a class and that it agrees with the job's own onQueue().
 */
class QueueRoutingServiceProvider extends ServiceProvider
{
    /** Queue name of the slow AI / Chrome / backup / import lane. */
    public const HEAVY = 'heavy';

    /** @var array<string, string> ee modules' jobs (P1-13 13f: added by their providers, e.g. the Reseller fleet batch) */
    private static array $moduleRoutes = [];

    /** An ee module routes one of its jobs (call from its provider's register()). */
    public static function add(string $job, string $queue): void
    {
        self::$moduleRoutes[$job] = $queue;
    }

    /**
     * @return array<class-string, string> job class => queue
     */
    public static function routes(): array
    {
        $blog = (string) config('blog_autopilot.queue', self::HEAVY);

        return [
            'App\\Jobs\\RunThemeLighthouseGate' => self::HEAVY,
            'App\\Jobs\\DeleteUsersJob' => self::HEAVY, // P6-119e bulk user delete
            'Juwri\\Core\\Jobs\\GenerateStoreBackupJob' => self::HEAVY,
            'Juwri\\Core\\Jobs\\BuildAccountExportJob' => self::HEAVY, // P4-07 owner account export
            'Juwri\\Modules\\Content\\Jobs\\GenerateBlogPostJob' => $blog,
            'Juwri\\Modules\\Content\\Jobs\\TranslateBlogPostJob' => $blog,
            'Juwri\\Modules\\Biolink\\Jobs\\TranslateBioBlocksJob' => self::HEAVY,
            'Juwri\\Modules\\Integrations\\Jobs\\ProcessStoreImportJob' => self::HEAVY,
            'Juwri\\Modules\\Integrations\\Jobs\\CommitSwitchImportJob' => self::HEAVY, // P4-22b
            'Juwri\\Modules\\Ai\\Jobs\\LangChromeTranslateJob' => self::HEAVY,
            'Juwri\\Modules\\Menu\\Jobs\\TranslateMenuJob' => self::HEAVY,
            'Juwri\\Modules\\Menu\\Jobs\\GenerateMenuItemImagesJob' => self::HEAVY,
            'Juwri\\Modules\\Ordering\\Jobs\\TranscribeVoiceNote' => self::HEAVY,
            'Juwri\\Modules\\Verticals\\Jobs\\Fitment\\ImportFitmentsJob' => self::HEAVY, // P6-64b
        ] + self::$moduleRoutes;
    }

    public function boot(): void
    {
        Queue::route(self::routes());
    }
}
