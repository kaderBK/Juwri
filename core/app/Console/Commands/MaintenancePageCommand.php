<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Juwri\Core\Support\MaintenanceStaticPage;

/** P6-112: (re)build the static maintenance.html at the web root. Run it on deploy and after changing APP_NAME. */
class MaintenancePageCommand extends Command
{
    protected $signature = 'juwri:maintenance-page {--check : Only report whether the file is up to date}';

    protected $description = 'Write the branded static maintenance.html (served when PHP itself is down).';

    public function handle(): int
    {
        $path = MaintenanceStaticPage::path();
        $html = MaintenanceStaticPage::build();
        $current = is_file($path) ? (string) file_get_contents($path) : null;

        if ($this->option('check')) {
            $this->line($current === $html ? 'maintenance.html is up to date.' : 'maintenance.html is missing or out of date.');

            return $current === $html ? self::SUCCESS : self::FAILURE;
        }

        file_put_contents($path, $html);
        $this->info('Wrote '.$path);

        return self::SUCCESS;
    }
}
