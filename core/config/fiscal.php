<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Juwri\Modules\Fiscal\Services\Eta\EtaDriver;
use Juwri\Modules\Fiscal\Services\Jofotara\JofotaraDriver;
use Juwri\Modules\Fiscal\Services\PintAe\PintAeAdapter;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaDriver;

/*
| Fiscal module config (J25-8). Read by Juwri\Modules\Fiscal\Providers\FiscalServiceProvider.
|
| adapters: one FiscalAdapter per country (ISO 3166 alpha-2). `adapter` is the class the container builds;
| `enabled` false = not registered (documents of that country are never queued for submission — profile-only).
| Extension point: an Enterprise or Cloud certified connector replaces a country's adapter by pointing `adapter` at its own
| FiscalAdapter class (JO JoFotara, EG ETA and AE PINT AE are the expected ones); nothing in the module changes.
*/
return [
    'adapters' => [
        // KSA ZATCA Phase 2 (Fatoora): simplified = report within 24h, standard = clearance.
        // self-hosted on juwri's hosted ZATCA signing (FISCAL_SA_SIGNING=juwri_hosted): the EGS key stays at juwri
        'SA' => ['adapter' => env('FISCAL_SA_SIGNING') === 'juwri_hosted' ? \Juwri\Modules\Fiscal\Services\Zatca\HostedZatcaDriver::class : ZatcaDriver::class, 'enabled' => true],
        // Jordan JoFotara (ISTD): clear-before-print.
        'JO' => ['adapter' => JofotaraDriver::class, 'enabled' => (bool) env('FISCAL_JO_ENABLED', true)],
        // Egypt ETA e-Receipt: report-after-print within 24h.
        'EG' => ['adapter' => EtaDriver::class, 'enabled' => (bool) env('FISCAL_EG_ENABLED', true)],
        // UAE Peppol PINT AE via an accredited service provider — stub, voluntary Jul 2026, mandatory 2027.
        'AE' => ['adapter' => PintAeAdapter::class, 'enabled' => (bool) env('FISCAL_AE_PINT_ENABLED', false)],
        // Oman (OTA e-invoicing, later): placeholder — profile only, no adapter yet.
        'OM' => ['adapter' => null, 'enabled' => false],
    ],
];
