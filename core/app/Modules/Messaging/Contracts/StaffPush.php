<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Contracts;

use Juwri\Modules\Messaging\Services\StaffPush\StaffPushService;

/** P1-08 L1 public surface: web push to a store's staff browsers. Implemented by {@see StaffPushService}. */
interface StaffPush
{
    /**
     * @param  array<string, mixed>  $payload
     * @return int the number of subscriptions notified
     */
    public function sendNewOrder(int $storeId, array $payload): int;
}
