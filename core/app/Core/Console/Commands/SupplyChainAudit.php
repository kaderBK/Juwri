<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * P5-2: the patch-cadence heartbeat. Runs `composer audit` weekly (scheduled),
 * logs a warning with the advisory count so it lands in monitoring (P5-3),
 * and writes a fresh SBOM snapshot (installed packages + exact versions) to
 * storage — the record of what was running when.
 */
class SupplyChainAudit extends Command
{
    protected $signature = 'platform:supply-chain-audit';

    protected $description = 'Run composer audit, log advisory counts, and refresh the SBOM snapshot';

    public function handle(): int
    {
        $base = base_path();

        // 1. SBOM snapshot: every installed package with its exact version.
        $show = Process::fromShellCommandline('composer show --format=json --locked', $base);
        $show->setTimeout(120)->run();
        if ($show->isSuccessful()) {
            $dir = storage_path('app/sbom');
            @mkdir($dir, 0755, true);
            file_put_contents($dir.'/composer-sbom-'.now()->format('Y-m-d').'.json', $show->getOutput());
            $this->info('SBOM snapshot written.');
        }

        // 2. Advisory audit → log for the monitoring surface.
        $audit = Process::fromShellCommandline('composer audit --format=json --locked', $base);
        $audit->setTimeout(120)->run();
        $decoded = json_decode($audit->getOutput(), true) ?: [];
        $advisories = $decoded['advisories'] ?? [];
        $packages = count($advisories);
        $total = array_sum(array_map('count', $advisories));

        if ($total > 0) {
            Log::warning("[supply-chain] {$total} security advisories across {$packages} packages", [
                'packages' => array_keys($advisories),
            ]);
            $this->warn("{$total} advisories across {$packages} packages.");
        } else {
            $this->info('No known advisories.');
        }

        return self::SUCCESS;
    }
}
