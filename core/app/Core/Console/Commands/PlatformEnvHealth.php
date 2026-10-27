<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Console\Commands;

use Juwri\Core\Services\Ops\EnvFileHealth;
use Illuminate\Console\Command;

/**
 * H8 — report .env file health from the CLI. Prints KEY NAMES and verdicts only,
 * never an env value. Exits non-zero when any issue is found so it can gate a
 * deploy or a monitoring cron. Companion to the superadmin System Info panel.
 */
class PlatformEnvHealth extends Command
{
    protected $signature = 'platform:env-health';

    protected $description = 'Check the .env file for a leaked APP_KEY, duplicate or unparseable keys (names only, never values)';

    public function handle(): int
    {
        $report = EnvFileHealth::report();

        if (! $report['readable']) {
            $this->error('.env file could not be read.');

            return self::FAILURE;
        }

        if ($report['ok']) {
            $this->info('.env file health: no issues found.');

            return self::SUCCESS;
        }

        $reasons = [
            'app_key_leak' => 'holds a copy of APP_KEY (client-exposed key leak)',
            'malformed_value' => 'malformed value (unquoted then contains a quote)',
            'duplicate_key' => 'defined more than once (only the first assignment is used)',
            'unquoted_unsafe_value' => 'unquoted value with a space or # (unparseable / truncated)',
        ];

        foreach ($report['issues'] as $issue) {
            $reason = $reasons[$issue['code']] ?? $issue['code'];
            $this->line($issue['key'].' — '.$reason);
        }

        return self::FAILURE;
    }
}
