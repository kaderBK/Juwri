<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Console\Commands;

use Illuminate\Console\Command;
use Juwri\Core\Services\Tenancy\TenantContext;
use Juwri\Modules\Integrations\Services\Switching\SwitchImports;

/** P4-22: delete import previews nobody committed within a day (their encrypted rows hold personal data). */
class SwitchPrunePreviews extends Command
{
    protected $signature = 'switch:prune-previews';

    protected $description = 'Delete switching-import previews older than a day';

    public function handle(): int
    {
        $n = TenantContext::bypass(fn () => SwitchImports::prune());
        $this->info($n.' preview(s) deleted.');

        return self::SUCCESS;
    }
}
