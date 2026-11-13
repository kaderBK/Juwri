<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Juwri\Modules\LegacyCompat\Import\ImportFailed;
use Juwri\Modules\LegacyCompat\Import\ImportGuard;
use Juwri\Modules\LegacyCompat\Import\LegacyImporter;

/**
 * J52b: import the old platform's database into this install (plan §1, §6 runbook). Runs only on the migration host
 * with a production snapshot restored there (J19 / R6: never in development); it refuses unless every
 * {@see ImportGuard} check passes. `--dry-run` writes nothing (one rolled-back transaction) and prints the report.
 *
 * The source is a configured connection: `--source=legacy_import_source` uses the env-only connection from
 * config/legacy_compat.php (`import.connection`, no default host); any connection in config/database.php may be named.
 */
class LegacyImportCommand extends Command
{
    /** The env-built source connection name (config legacy_compat.import.connection). */
    public const SOURCE_CONNECTION = 'legacy_import_source';

    protected $signature = 'legacy:import
        {--source= : Source database connection name (required)}
        {--dry-run : Run every step inside a transaction that is rolled back; print the report}
        {--store=* : Only these source store ids (repeatable)}
        {--report= : Also write the JSON report to this file}';

    protected $description = 'One-off import of the old platform database into this install (LegacyCompat; off by default).';

    public function handle(): int
    {
        $sourceName = $this->option('source');
        $sourceName = is_string($sourceName) && $sourceName !== '' ? $sourceName : null;
        if ($sourceName === self::SOURCE_CONNECTION && ! is_array(config('database.connections.'.self::SOURCE_CONNECTION))) {
            $connection = (array) config('legacy_compat.import.connection', []);
            $host = $connection['host'] ?? null;
            if (is_string($host) && $host !== '') {
                config(['database.connections.'.self::SOURCE_CONNECTION => $connection]);
            }
        }

        $target = DB::connection();
        $reasons = ImportGuard::check($target, $sourceName);
        if ($reasons !== []) {
            $this->error('legacy:import refused:');
            foreach ($reasons as $reason) {
                $this->line('  - '.$reason);
            }

            return self::FAILURE;
        }

        $stores = array_values(array_filter(array_map('intval', (array) $this->option('store')), static fn (int $id): bool => $id > 0));
        $importer = new LegacyImporter(DB::connection((string) $sourceName), $target, [
            'dry_run' => (bool) $this->option('dry-run'),
            'stores' => $stores === [] ? null : $stores,
            'chunk' => (int) config('legacy_compat.import.chunk', 500),
            'gap' => (int) config('legacy_compat.import.sequence_gap', 1000),
            'source_timezone' => (string) config('legacy_compat.import.source_timezone'),
            'source_app_key' => (string) config('legacy_compat.import.source_app_key', ''),
            'source_cipher' => (string) config('legacy_compat.import.source_cipher', 'AES-256-CBC'),
            'enabled_gateways' => array_values((array) config('legacy_compat.import.enabled_gateways', ['stripe', 'paypal'])),
        ]);
        try {
            $report = $importer->run()->toArray();
        } catch (ImportFailed $e) {
            // Safe text only (phase, class, SQL state): never the database message with its row values.
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $json = (string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $file = $this->option('report');
        if (is_string($file) && $file !== '') {
            file_put_contents($file, $json."\n");
        }
        $this->line($json);

        $blocked = array_filter(array_keys($report['conflicts']), static fn (string $kind): bool => str_starts_with($kind, 'blocked:'));

        return $blocked === [] ? self::SUCCESS : self::FAILURE;
    }
}
