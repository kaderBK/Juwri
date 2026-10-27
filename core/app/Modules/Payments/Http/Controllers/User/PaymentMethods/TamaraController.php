<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods;

use Juwri\Modules\Payments\Services\Gateways\TamaraAdapter;

/** P1-23 part 5: Tamara — see TamaraAdapter (same routes and callback URLs as the hand-written controller it replaced). */
class TamaraController extends GatewayAdapterController
{
    protected static function adapterClass(): string
    {
        return TamaraAdapter::class;
    }
}
