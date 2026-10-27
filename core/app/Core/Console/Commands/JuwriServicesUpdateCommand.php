<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Console\Commands;

use Illuminate\Console\Command;
use Juwri\Core\Services\HostedServices\HostedUpdateClient;

/** Self-hosted: check the juwri update server for a newer release and, with --download, fetch and verify it. */
class JuwriServicesUpdateCommand extends Command
{
    protected $signature = 'juwri:services:update {--channel=stable : stable|beta} {--download= : directory to download the package into}';

    protected $description = 'Check the juwri update server for a newer release (and download it with --download)';

    public function handle(HostedUpdateClient $updates): int
    {
        $current = (string) (config('app.version') ?: '0');
        $channel = (string) $this->option('channel');
        $dir = (string) $this->option('download');
        $result = $dir !== '' ? $updates->download($current, $dir, $channel) : $updates->check($current, $channel);
        if (is_array($result) && isset($result['code'])) {
            $this->error("{$result['code']}: {$result['message']}");

            return self::FAILURE;
        }
        if ($result === null || (is_array($result) && ($result['up_to_date'] ?? false) === true)) {
            $this->info("Up to date ({$current}).");
        } elseif (is_string($result)) {
            $this->info("Downloaded and verified: {$result}");
        } else {
            $this->info("juwri {$result['version']} is available (you run {$current}). Download it with --download=<dir>.");
        }

        return self::SUCCESS;
    }
}
