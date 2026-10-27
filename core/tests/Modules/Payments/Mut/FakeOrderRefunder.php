<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments\Mut;

use App\Models\Order;
use Juwri\Modules\Payments\Services\Refunds\GatewayRefunder;

/** P6-28: a live order refunder (config refunds.drivers) whose answer each test sets. */
class FakeOrderRefunder implements GatewayRefunder
{
    /** @var array<string,mixed>|\Throwable */
    public static array|\Throwable $result = ['ok' => true, 'reference' => 'drv-1'];

    public function gateway(): string
    {
        return 'my_pay';
    }

    public function refund(Order $order, float $amount): array
    {
        if (self::$result instanceof \Throwable) {
            throw self::$result;
        }

        return self::$result;
    }
}
