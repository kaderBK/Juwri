<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods;

use Juwri\Modules\Payments\Services\Gateways\TapAdapter;

/** P1-23 part 5: Tap — see TapAdapter (same routes and callback URLs as the hand-written controller it replaced). */
class TapController extends GatewayAdapterController
{
    protected static function adapterClass(): string
    {
        return TapAdapter::class;
    }
}
