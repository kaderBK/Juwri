<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * E2E only (P1-12 item 9 h): installs the `stub` payment_gateways catalog row — and nothing else — so a journey can
 * pay with the fake gateway on an instance armed with E2E_STUBS=1 (local/testing). Unarmed, the row is inert: the
 * store pay page does not offer it, plan checkout hides it, and StubController 404s. Idempotent. Also run by
 * E2ePlatformV2Seeder (which seeds much more).
 *
 *   php artisan db:seed --class='Juwri\Modules\Storefront\Database\Seeders\E2eStubGatewaySeeder'
 */
class E2eStubGatewaySeeder extends Seeder
{
    public const FOLDER = 'stub';

    public function run(): void
    {
        DB::table('payment_gateways')->updateOrInsert(
            ['payment_folder' => self::FOLDER],
            ['payment_install' => '1', 'payment_title' => 'E2E Stub Gateway']
        );
    }
}
