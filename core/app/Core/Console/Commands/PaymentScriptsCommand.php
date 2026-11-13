<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Console\Commands;

use Illuminate\Console\Command;
use Juwri\Core\Pci\PaymentScriptInventory;

/**
 * PCI DSS 4.0 6.4.3: the generated script inventory of the payment pages — every authorised script, the pages it may
 * run on, how its integrity is assured (SRI hash, or the reason it cannot have one + the CSP pin) and its business
 * reason. `--json` for evidence files.
 */
class PaymentScriptsCommand extends Command
{
    protected $signature = 'pci:payment-scripts {--json : machine-readable}';

    protected $description = 'PCI DSS 6.4.3: list the authorised scripts of every payment page';

    public function handle(): int
    {
        $rows = [];
        foreach (PaymentScriptInventory::scripts() as $id => $entry) {
            $src = (string) ($entry['src'] ?? '');
            $firstParty = PaymentScriptInventory::isFirstParty($src);
            $rows[] = [
                'id' => (string) $id,
                'src' => $src,
                'party' => $firstParty ? 'first' : 'third',
                'integrity' => $firstParty ? 'same origin (content hash in 11.6.1 checks)'
                    : ((string) ($entry['integrity'] ?? '') !== '' ? (string) $entry['integrity'] : 'SRI exempt: '.(string) ($entry['sri_exempt'] ?? '')),
                'pages' => implode(', ', (array) ($entry['pages'] ?? [])),
                'reason' => (string) ($entry['reason'] ?? ''),
            ];
        }
        if ($this->option('json')) {
            $this->line((string) json_encode(['pages' => PaymentScriptInventory::pages(), 'scripts' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }
        $this->table(['id', 'src', 'party', 'integrity', 'pages', 'reason'], $rows);
        $problems = PaymentScriptInventory::problems();
        foreach ($problems as $problem) {
            $this->error($problem);
        }

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }
}
