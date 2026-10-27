<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Console\Commands;

use Illuminate\Console\Command;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\HostedServices\HostedServiceClient;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaOnboarding;

/**
 * Self-hosted KSA store: onboard one EGS unit (a branch's device) with juwri's hosted ZATCA signing service — juwri
 * makes and keeps the unit's key, gets the compliance CSID with the FATOORA one-time code, then the production CSID.
 * Here only the device row is kept (no key, no certificate secret). Then set FISCAL_SA_SIGNING=juwri_hosted.
 */
class ZatcaHostedOnboard extends Command
{
    protected $signature = 'fiscal:zatca-hosted-onboard {store : store id} {branch : branch id (the EGS unit\'s branch)} {device_key : EGS serial} {otp : FATOORA one-time code} {vat : 15-digit VAT number}';

    protected $description = 'Onboard an EGS unit with juwri\'s hosted ZATCA signing service (the key stays at juwri)';

    public function handle(HostedServiceClient $client, ZatcaOnboarding $onboarding): int
    {
        $store = Store::query()->find((int) $this->argument('store'));
        $branch = Branch::query()->where('id', (int) $this->argument('branch'))->where('store_id', (int) $this->argument('store'))->first();
        if ($store === null || $branch === null) {
            $this->error('No such store or branch (the branch must belong to the store).');

            return self::FAILURE;
        }
        if (! $client->connected()) {
            $this->error('Connect this installation first: php artisan juwri:services:connect');

            return self::FAILURE;
        }
        $serial = (string) $this->argument('device_key');
        $res = $client->send('POST', '/api/hosted/v1/zatca/units', ['otp' => (string) $this->argument('otp'), 'egs_serial' => $serial, 'vat' => (string) $this->argument('vat'),
            'common_name' => $serial, 'organization' => (string) ($store->title ?? 'Store')], $store, (int) $branch->id);
        if (! $res->successful()) {
            $this->error((string) ($res->json('message') ?: 'juwri refused the onboarding.'));

            return self::FAILURE;
        }
        $prod = $client->send('POST', '/api/hosted/v1/zatca/units/'.rawurlencode($serial).'/production', [], $store, (int) $branch->id);
        if (! $prod->successful()) {
            $this->error((string) ($prod->json('message') ?: 'juwri could not get the production certificate.'));

            return self::FAILURE;
        }
        $device = $onboarding->ensureDevice((int) $store->id, $serial, (int) $branch->id, ['hosted' => true]);
        $device->forceFill(['meta' => array_merge((array) $device->meta, ['hosted' => true])])->save();
        $this->info("EGS unit {$serial} onboarded at juwri for store {$store->id}, branch {$branch->id}. Set FISCAL_SA_SIGNING=juwri_hosted.");

        return self::SUCCESS;
    }
}
