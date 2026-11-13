<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Contracts;

use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Models\StockAlert;

/**
 * P3-08 / N5: back-in-stock requests (marketing opt-in required; the store can switch them off). Implemented by
 * Services\Retail\StockAlerts.
 */
interface StockAlertRequests
{
    /** Are back-in-stock requests on for the store (on by default)? */
    public static function enabled(Store $store): bool;

    /** @throws \DomainException not_found | alerts_off | in_stock | consent_required | too_many | invalid_contact */
    public function subscribe(Store $store, Menu $item, ?MenuVariant $variant, string $channel, string $contact, bool $marketingOptIn, ?int $customerId = null, ?string $locale = null): StockAlert;
}
