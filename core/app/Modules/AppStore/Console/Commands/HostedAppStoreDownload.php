<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\AppStore\Console\Commands;

use Illuminate\Console\Command;
use Juwri\Modules\AppStore\Services\Hosted\HostedAppStoreClient;

/** Self-hosted: download an App Store / theme package from juwri for one store (checksum verified). */
class HostedAppStoreDownload extends Command
{
    protected $signature = 'appstore:hosted-download {slug} {store : the installing store id} {--to= : directory (default storage/app/hosted-downloads)}';

    protected $description = 'Download an App Store / theme package from juwri for a store';

    public function handle(HostedAppStoreClient $client): int
    {
        $out = $client->download((string) $this->argument('slug'), (int) $this->argument('store'), (string) ($this->option('to') ?: storage_path('app/hosted-downloads')));
        if (is_array($out)) {
            $this->error($out['message'] !== '' ? $out['message'] : $out['code']);

            return self::FAILURE;
        }
        $this->info("Downloaded to {$out}.");

        return self::SUCCESS;
    }
}
