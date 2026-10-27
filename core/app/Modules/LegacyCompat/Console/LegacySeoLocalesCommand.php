<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Console;

use Illuminate\Console\Command;
use Juwri\Modules\LegacyCompat\Support\LegacyCompatRoutes;
use Juwri\Modules\LegacyCompat\Support\LegacySeo;

/**
 * BL-031: put this install's language set where the old site had it (root language, the other languages under
 * /{code}/, all of them active, hreflang = languages + x-default). The values come from config legacy_compat.seo.*
 * (LEGACY_COMPAT_SEO_*); the command writes the platform settings `lang` / `include_language_code` and the language
 * rows' active flag, idempotently. `--check` only reports (exit 1 when the install differs). Rebuild the route cache
 * afterwards: the language prefix is read when the routes are registered.
 */
class LegacySeoLocalesCommand extends Command
{
    protected $signature = 'legacy:seo-locales
        {--check : Report only; exit 1 when the install differs from the configured language set}';

    protected $description = 'Apply (or check) the old site\'s language set: root language, prefixed languages, all active';

    public function handle(): int
    {
        if (! LegacyCompatRoutes::enabled()) {
            $this->error('The LegacyCompat module is off (legacy_compat.enabled).');

            return self::FAILURE;
        }
        $plan = LegacySeo::plan();
        if ($plan === null) {
            $this->error('No language set configured (LEGACY_COMPAT_SEO_LOCALE_DEFAULT / LEGACY_COMPAT_SEO_LOCALES).');

            return self::FAILURE;
        }

        $apply = ! $this->option('check');
        $report = LegacySeo::reconcile($plan, $apply);
        $this->line((string) json_encode(['applied' => $apply] + $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($report['missing'] !== []) {
            $this->error('These languages have no language row on this install: '.implode(', ', $report['missing']));
        } elseif ($apply && $report['changes'] !== []) {
            $this->info('Applied. Rebuild the route cache (php artisan route:cache) so the prefix mode takes effect.');
        }

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
