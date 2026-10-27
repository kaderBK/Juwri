<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Console\Commands;

use Juwri\Core\Services\LangPipelineService;
use Illuminate\Console\Command;

/**
 * Prints per-locale platform-chrome translation coverage as a console table
 * (or JSON via --json), reading through LangPipelineService::coverage().
 */
class LangStatus extends Command
{
    protected $signature = 'lang:status {--json : Output the coverage payload as JSON}';

    protected $description = 'Show per-locale platform-chrome translation coverage.';

    public function handle(): int
    {
        $svc = app()->bound(LangPipelineService::class)
            ? app(LangPipelineService::class)
            : LangPipelineService::forApp();

        $cov = $svc->coverage();

        if ($this->option('json')) {
            $this->line((string) json_encode($cov, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("Source (en) keys: {$cov['en_total']}");

        $rows = [];
        foreach ($cov['locales'] as $l) {
            $rows[] = [
                $l['code'],
                $l['pct_done'].'%',
                $l['human'],
                $l['machine'],
                $l['review'],
                $l['placeholder'],
                $l['absent'],
            ];
        }

        if ($rows) {
            $this->table(['Locale', 'Done', 'Human', 'Machine', 'Review', 'Placeholder', 'Absent'], $rows);
        } else {
            $this->comment('No active target locales.');
        }

        return self::SUCCESS;
    }
}
