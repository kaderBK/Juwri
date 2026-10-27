<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods;

use Juwri\Modules\Payments\Services\Gateways\WalletTransferAdapter;

/** P1-23 part 6: wallet transfer (manual tender) — see WalletTransferAdapter. */
class WalletTransferController extends GatewayAdapterController
{
    protected static function adapterClass(): string
    {
        return WalletTransferAdapter::class;
    }
}
