<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Console\Commands;

use App\Models\Site;
use Juwri\Modules\Content\Services\SitePages\LegacyHomeBridge;
use Illuminate\Console\Command;

/**
 * HOME-PAGES HP-30: `php artisan site:seed-home` — ensures this install's Site
 * row exists and composes/refreshes the DRAFT home page reproducing today's
 * hardcoded legacy home. Never publishes (owner reviews + publishes in the
 * builder after the HP-31 parity gate).
 */
class SeedHomeFromLegacy extends Command
{
    protected $signature = 'site:seed-home
        {--site-key=default : Key for this install\'s Site row (created if missing)}
        {--force : Overwrite a hand-edited draft}';

    protected $description = 'Seed/refresh the draft block-composed home page from the legacy home (never publishes)';

    public function handle(LegacyHomeBridge $bridge): int
    {
        $key = (string) $this->option('site-key');

        $site = Site::firstOrCreate(
            ['key' => $key],
            ['name' => (string) (config('settings.site_title') ?? config('app.name')), 'active' => true]
        );
        $this->info("Site '{$site->key}' (id {$site->id})".($site->wasRecentlyCreated ? ' — created' : ''));

        $result = $bridge->seed($site, (bool) $this->option('force'));

        switch ($result['action']) {
            case 'created':
                $this->info("Draft home composed (page id {$result['page']->id}, ".count($result['page']->blocks['blocks'] ?? []).' blocks). NOT published.');
                break;
            case 'refreshed':
                $this->info("Draft home refreshed (page id {$result['page']->id}).");
                break;
            case 'skipped':
                $this->info('Draft already matches the bridge document — nothing to do.');
                break;
            case 'refused':
                $this->error('Draft has hand edits — re-run with --force to overwrite.');

                return self::FAILURE;
        }

        $this->line('Next: preview the draft in the builder, run the HP-31 parity gate, then publish per install.');

        return self::SUCCESS;
    }
}
