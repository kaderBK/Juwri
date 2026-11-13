<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Loyalty\Services\GoogleWallet;

use App\Services\GoogleWallet\GoogleWalletService;
use Juwri\Core\Models\Store;
use Juwri\Modules\Loyalty\Contracts\GoogleWalletPassIds;

/**
 * The platform's own pass ids:
 *   loyalty   class `{issuer}.loy{storeId}`, object `{issuer}.loy{storeId}c{customerId}`
 *   gift card class `{issuer}.gc{storeId}`,  object `{issuer}.gc{storeId}c{cardId}`
 */
final class DefaultGoogleWalletPassIds implements GoogleWalletPassIds
{
    public function classId(Store $store, string $kind): string
    {
        return GoogleWalletService::issuerId().'.'.($kind === self::GIFT_CARD ? 'gc' : 'loy').(int) $store->id;
    }

    public function objectId(Store $store, string $kind, int $subjectId): string
    {
        return $this->classId($store, $kind).'c'.$subjectId;
    }
}
