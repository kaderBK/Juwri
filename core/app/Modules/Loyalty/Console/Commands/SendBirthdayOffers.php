<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Loyalty\Console\Commands;

use Illuminate\Console\Command;
use Juwri\Modules\Loyalty\Services\BirthdayOfferService;

class SendBirthdayOffers extends Command
{
    protected $signature = 'birthday-offers:send';

    protected $description = 'Send one-per-year birthday discount coupons to opted-in customers (#63)';

    public function handle(BirthdayOfferService $service): int
    {
        $result = $service->run();
        $this->info("Birthday offers: {$result['offers']} sent across {$result['stores']} enabled restaurant(s).");

        return self::SUCCESS;
    }
}
