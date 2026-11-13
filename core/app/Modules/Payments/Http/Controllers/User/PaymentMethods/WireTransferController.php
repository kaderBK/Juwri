<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods;

use Juwri\Modules\Payments\Services\Gateways\WireTransferAdapter;

/** P1-23 part 6: bank (wire) transfer (manual tender) — see WireTransferAdapter. */
class WireTransferController extends GatewayAdapterController
{
    protected static function adapterClass(): string
    {
        return WireTransferAdapter::class;
    }
}
