<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Console\Commands;

use App\Support\LegalMarkerScanner;
use Illuminate\Console\Command;

/**
 * P1-23: list every {{FILL:key}} / {{VERIFY:key}} marker (key optional) still sitting in the website legal
 * source tree. Used by the release gate and `juwri:owner-todo` to know which keys the owner still owes
 * a value for. Exits 1 while any marker remains,
 * 0 when the tree is clean (suitable for CI).
 */
class LegalMarkersCommand extends Command
{
    protected $signature = "legal:markers {--path= : legal root (default resource_path('legal'))} {--json}";

    protected $description = 'List {{FILL}} / {{VERIFY}} markers still in the legal sources (exit 1 while any remain).';

    public function handle(LegalMarkerScanner $scanner): int
    {
        $root = (string) ($this->option('path') ?: resource_path('legal'));
        $markers = $scanner->scan($root);

        if ($this->option('json')) {
            $this->line((string) json_encode(
                [
                    'total' => count($markers),
                    'markers' => $markers,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return $markers === [] ? self::SUCCESS : self::FAILURE;
        }

        $byDocument = [];
        foreach ($markers as $m) {
            $byDocument[$m['document']][] = $m;
        }

        if ($byDocument === []) {
            $this->info('No {{FILL}} / {{VERIFY}} markers in '.$root.'.');

            return self::SUCCESS;
        }

        ksort($byDocument);
        foreach ($byDocument as $document => $entries) {
            $this->line($document);
            usort($entries, function (array $a, array $b): int {
                return [$a['locale'], $a['file'], $a['line'], $a['type'], $a['key']]
                    <=> [$b['locale'], $b['file'], $b['line'], $b['type'], $b['key']];
            });
            foreach ($entries as $m) {
                $this->line(sprintf(
                    '  [%s] %s:%d  %s %s  — %s',
                    $m['locale'],
                    $m['file'],
                    $m['line'],
                    $m['type'],
                    $m['key'],
                    $m['sentence'],
                ));
            }
        }
        $this->newLine();
        $this->info('Total: '.count($markers));

        return self::FAILURE;
    }
}
