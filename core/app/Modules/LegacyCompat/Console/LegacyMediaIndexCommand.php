<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Console;

use Illuminate\Console\Command;
use Juwri\Modules\LegacyCompat\Import\ImportGuard;
use Juwri\Modules\LegacyCompat\Support\LegacyCompatRoutes;
use Juwri\Modules\LegacyCompat\Support\LegacyMediaIndex;
use Juwri\Modules\LegacyCompat\Support\LegacyMediaManifest;
use RuntimeException;
use Throwable;

/**
 * M6-D: rebuild the compiled media index used by the old media redirect route from an existing manifest.
 */
class LegacyMediaIndexCommand extends Command
{
    protected $signature = 'legacy:media-index
        {--manifest= : Local manifest JSON path (falls back to legacy_compat.media.manifest)}';

    protected $description = 'Compile the sharded media index used by the old media redirect route.';

    public function handle(): int
    {
        if (! LegacyCompatRoutes::enabled()) {
            $this->error('The legacy-compat module is off.');

            return self::FAILURE;
        }

        $gate = ImportGuard::gateReasons();
        if ($gate !== []) {
            $this->error('Refused:');
            foreach ($gate as $reason) {
                $this->line('  - '.$reason);
            }

            return self::FAILURE;
        }

        $manifestPath = $this->option('manifest') ?: config('legacy_compat.media.manifest');

        if (! is_string($manifestPath) || $manifestPath === '') {
            $this->error('Missing option: --manifest is required.');

            return self::FAILURE;
        }

        try {
            $manifest = LegacyMediaManifest::read($manifestPath);
        } catch (Throwable $e) {
            if ($e instanceof RuntimeException) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            throw $e;
        }

        try {
            LegacyMediaIndex::build($manifest, $manifestPath);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('indexed='.count($manifest->redirectMap()));

        return self::SUCCESS;
    }
}
