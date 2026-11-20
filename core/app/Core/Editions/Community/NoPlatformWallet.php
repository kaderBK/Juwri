<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Editions\Community;

use App\Models\Order;
use Juwri\Core\Contracts\Marketplace\PlatformWalletTender;

/** grocery-nits2 Community default: no platform wallet, so no order carries that tender and nothing goes back. */
final class NoPlatformWallet implements PlatformWalletTender
{
    public function returnPart(Order $order, float $amount, string $key): bool
    {
        return false;
    }

    public function reverseOrder(Order $order, float $keep = 0.0): float
    {
        return 0.0;
    }
}
