<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods;

use Juwri\Modules\Payments\Services\Gateways\QrpayAdapter;

/** P1-23 part 6: QRPay — see QrpayAdapter (same routes and callback URLs as the hand-written controller it replaced). */
class QrpayController extends GatewayAdapterController
{
    protected static function adapterClass(): string
    {
        return QrpayAdapter::class;
    }
}
