<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Contracts;

use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Data\Recommendation;

/**
 * P1-17 (J27-B): the ONE upsell / recommendation entry point other modules call
 * (storefront, kiosk, POS, agent tools). Implemented by the Menu module's
 * RecommendationEngine; bound in MenuServiceProvider.
 */
interface Recommendations
{
    public const CHANNEL_STOREFRONT = 'storefront';

    public const CHANNEL_KIOSK = 'kiosk';

    public const CHANNEL_POS = 'pos';

    public const CHANNEL_AGENT = 'agent';

    /**
     * Ordered recommendations for a cart in progress. Strictly scoped to `$store`.
     *
     * @param  list<int|array{item_id:int|string}>  $cartLines  item ids, or lines carrying `item_id`
     * @param  object|null  $customer  a {@see \Juwri\Modules\Menu\Data\RecommendationCustomer} feeds the favourite rule (kiosk);
     *                                 anything else is ignored
     * @return list<Recommendation>
     */
    public function recommend(Store $store, string $channel, array $cartLines = [], ?object $customer = null): array;
}
