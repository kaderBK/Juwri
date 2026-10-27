<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods;

use Juwri\Modules\Payments\Services\Gateways\NoonAdapter;

/** P1-23 part 5: Noon — see NoonAdapter (same routes and callback URLs as the hand-written controller it replaced). */
class NoonController extends GatewayAdapterController
{
    protected static function adapterClass(): string
    {
        return NoonAdapter::class;
    }
}
