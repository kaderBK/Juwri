<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support;

use App\Models\PartnerEarning;
use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Services\WalletService;

/**
 * AUDIT-money L-1: only matured sale earnings are withdrawable. Seeds a seller wallet with $amount of EARNED money
 * (the wallet credit plus the matching matured PartnerEarning row), the shape SellerPayoutService::creditSale() leaves
 * after the 14-day holdback.
 */
final class SellerEarnings
{
    public static function seedMatured(User $seller, float $amount, ?string $currency = null): void
    {
        $code = strtoupper((string) ($currency ?: WalletService::currency()));
        $ref = 'seed:'.$seller->id.':'.$code;
        WalletService::creditBack($seller, $amount, $code, 'seed', $ref);
        PartnerEarning::create([
            'partner_id' => $seller->id, 'source_type' => 'theme_sale', 'source_ref' => $ref,
            'gross' => $amount, 'share' => 1.0, 'net' => $amount, 'currency' => $code,
            'status' => 'available', 'available_at' => now()->subDay(),
        ]);
    }
}
