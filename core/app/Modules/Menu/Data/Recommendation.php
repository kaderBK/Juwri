<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Data;

/**
 * One recommended menu item: its id and the reason code of the rule that produced it
 * (see {@see \Juwri\Modules\Menu\Services\Recommendations\RecommendationEngine}).
 */
final readonly class Recommendation
{
    public const REASON_CHEAPEST_ADDON = 'cheapest_addon';

    public const REASON_SAME_CATEGORY = 'same_category';

    /** Item 26: what this recognised customer ordered most at this store (kiosk). */
    public const REASON_FAVOURITE = 'favourite';

    public function __construct(
        public int $itemId,
        public string $reason,
    ) {}
}
