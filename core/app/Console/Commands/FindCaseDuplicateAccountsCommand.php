<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Juwri\Core\Auth\CaseDuplicateFinder;

/** P6-98: report accounts whose email/username differ only in case (they block the case-insensitive unique migration). */
class FindCaseDuplicateAccountsCommand extends Command
{
    protected $signature = 'accounts:find-case-duplicates {--json : Print the groups as JSON}';

    protected $description = 'List users, staff and customers whose email/username differ only in letter case (ids only, no values).';

    public function handle(CaseDuplicateFinder $finder): int
    {
        $groups = $finder->find();

        if ($this->option('json')) {
            $this->line((string) json_encode($groups, JSON_PRETTY_PRINT));

            return $groups === [] ? self::SUCCESS : self::FAILURE;
        }

        if ($groups === []) {
            $this->info('No case-only duplicates found.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($groups as $i => $group) {
            foreach ($group['rows'] as $row) {
                $rows[] = [$i + 1, $group['table'], $group['column'], $group['scope'] ?? '-', $row['id'], $row['created_at'] ?? '-', $row['last_login_at'] ?? '-'];
            }
        }
        $this->table(['group', 'table', 'column', 'store_id', 'id', 'created_at', 'last_login_at'], $rows);
        $this->warn(count($groups).' group(s) differ only in letter case. Resolve each (merge or change the address by hand), then re-run this command. See docs/ops/case-duplicate-accounts.md.');

        return self::FAILURE;
    }
}
