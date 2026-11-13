<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Console\Commands;

use Illuminate\Console\Command;
use Juwri\Modules\Storefront\Models\StoreDomain;
use Juwri\Modules\Storefront\Services\DomainVerifier;
use Juwri\Modules\Storefront\Services\StoreDomains;

/**
 * P6-241: daily DNS recheck of every verified custom domain. A domain that stopped pointing at the platform for
 * hosts.release_after_days in a row is released (StoreDomains::recheck), so a lapsed domain no longer blocks its new owner.
 */
class RecheckDomains extends Command
{
    protected $signature = 'storefront:domains-recheck';

    protected $description = 'Recheck verified custom domains in DNS and release the ones that lapsed';

    public function handle(DomainVerifier $verifier): int
    {
        $counts = ['ok' => 0, 'failing' => 0, 'released' => 0, 'skipped' => 0];
        StoreDomain::query()->withoutGlobalScopes()->where('type', StoreDomain::TYPE_CUSTOM)->whereNotNull('verified_at')
            ->orderBy('id')->each(function (StoreDomain $domain) use ($verifier, &$counts): void {
                $counts[StoreDomains::recheck($domain, $verifier)]++;
            });
        $this->info(sprintf('ok %d, failing %d, released %d, skipped %d', $counts['ok'], $counts['failing'], $counts['released'], $counts['skipped']));

        return self::SUCCESS;
    }
}
