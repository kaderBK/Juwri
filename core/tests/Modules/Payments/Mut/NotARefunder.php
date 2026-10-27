<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments\Mut;

use Juwri\Modules\Payments\Services\Refunds\GatewayRefunder;

/** P6-28: a class in refunds.drivers that is not a GatewayRefunder (must be ignored). */
class NotARefunder
{
    public function gateway(): string
    {
        return 'my_pay';
    }
}
