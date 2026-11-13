<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods;

use Juwri\Modules\Payments\Services\Gateways\PaylinkAdapter;

/** P1-23 part 5: Paylink — see PaylinkAdapter (same routes and callback URLs as the hand-written controller it replaced). */
class PaylinkController extends GatewayAdapterController
{
    protected static function adapterClass(): string
    {
        return PaylinkAdapter::class;
    }
}
