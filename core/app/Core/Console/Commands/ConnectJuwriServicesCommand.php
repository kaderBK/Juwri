<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Console\Commands;

use Illuminate\Console\Command;
use Juwri\Core\Services\HostedServices\HostedServiceClient;
use Throwable;

/**
 * Connect this installation to the juwri-hosted services (owner 2026-10-05): the ONLY way the app registers with
 * juwri — run by the owner when they want a juwri service (delivery-app relay, ZATCA signing, AI credits, messaging,
 * App Store downloads, updates). Licensed installations connect with their licence; Community installations get a free
 * Community install ID. Nothing is sent to juwri before this runs (no phone-home).
 */
class ConnectJuwriServicesCommand extends Command
{
    protected $signature = 'juwri:services:connect {--force : connect again (replaces the stored secret)}';

    protected $description = 'Connect this installation to the juwri-hosted services (gets an install ID; nothing is sent before you run this)';

    public function handle(HostedServiceClient $client): int
    {
        if ($client->connected() && ! $this->option('force')) {
            $this->info('Already connected as '.$client->installId().'. Use --force to connect again.');

            return self::SUCCESS;
        }
        try {
            $identity = $client->register();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info('Connected to juwri services as '.$identity['install_id'].' ('.$identity['edition'].').');

        return self::SUCCESS;
    }
}
