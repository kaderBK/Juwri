<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods;

use Juwri\Modules\Payments\Services\Gateways\FlutterwaveAdapter;

/** P1-23 part 6: Flutterwave — see FlutterwaveAdapter (same routes and callback URLs as the hand-written controller it replaced). */
class FlutterwaveController extends GatewayAdapterController
{
    protected static function adapterClass(): string
    {
        return FlutterwaveAdapter::class;
    }
}
