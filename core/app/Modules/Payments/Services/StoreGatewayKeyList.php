<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services;

use Juwri\Modules\Payments\Contracts\StoreGatewayKeys;

/** {@see StoreGatewayKeys} over the one list in StorePaymentGateways / GatewaySecretVault. */
final class StoreGatewayKeyList implements StoreGatewayKeys
{
    public function parse(string $name): ?array
    {
        return StorePaymentGateways::parse($name);
    }

    public function isSecret(string $name): bool
    {
        return GatewaySecretVault::isSecret($name);
    }
}
