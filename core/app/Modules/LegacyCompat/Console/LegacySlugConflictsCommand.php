<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Services\Tenancy\TenantContext;
use Juwri\Modules\LegacyCompat\Support\LegacyCompatRoutes;
use Juwri\Modules\LegacyCompat\Support\LegacySlugConflicts;

/**
 * Re-check every imported store slug against this install's CURRENT route table and store the slugs a newer path
 * shadows (plan §5.4, R4). `legacy:import` does this once; run it again after every upgrade (new versions add
 * paths), after changing LEGACY_COMPAT_HOSTS, then rebuild the route cache. Prints the list as JSON.
 */
class LegacySlugConflictsCommand extends Command
{
    protected $signature = 'legacy:slug-conflicts
        {--dry-run : Print the list without storing it}';

    protected $description = 'List (and store) imported store slugs that this install\'s routes would shadow';

    public function handle(): int
    {
        if (! LegacyCompatRoutes::enabled()) {
            $this->error('The LegacyCompat module is off (legacy_compat.enabled).');

            return self::FAILURE;
        }
        if (! Schema::hasTable(LegacySlugConflicts::TABLE)) {
            $this->error('Run the migrations first ('.LegacySlugConflicts::TABLE.' is missing).');

            return self::FAILURE;
        }

        $conflicts = TenantContext::bypass(static fn (): array => LegacySlugConflicts::scan());
        if (! $this->option('dry-run')) {
            LegacySlugConflicts::persist($conflicts);
        }
        $this->line((string) json_encode([
            'stored' => ! $this->option('dry-run'),
            'hosts' => LegacySlugConflicts::hosts(),
            'slug_conflicts' => $conflicts,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if (! $this->option('dry-run') && $conflicts !== [] && LegacySlugConflicts::hosts() === []) {
            $this->warn('No old platform host is configured (LEGACY_COMPAT_HOSTS): these slugs stay shadowed.');
        }

        return self::SUCCESS;
    }
}
