<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods;

use Juwri\Modules\Payments\Services\Gateways\TdspAdapter;

/** P1-23 part 6: TDSP / T-Lync — see TdspAdapter (same routes and callback URLs as the hand-written controller it replaced). */
class TdspController extends GatewayAdapterController
{
    protected static function adapterClass(): string
    {
        return TdspAdapter::class;
    }
}
