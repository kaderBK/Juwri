<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Data;

/**
 * A recognised customer for the recommendation engine (item 26, kiosk ↔ loyalty): their id and the items they ordered
 * at this store, most ordered first (the caller reads the orders; the Menu module never reads Ordering's tables).
 */
final readonly class RecommendationCustomer
{
    /** @param  list<int>  $orderedItemIds */
    public function __construct(
        public int $id,
        public array $orderedItemIds,
    ) {}
}
