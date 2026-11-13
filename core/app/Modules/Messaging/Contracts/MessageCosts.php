<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Contracts;

use Juwri\Modules\Messaging\Models\MessageCost;

/**
 * The message cost ledger (`message_costs`), for other modules — e.g. Agents records a finished phone call (channel
 * `voice`). Resolves to the Messaging MessageCostLedger service.
 */
interface MessageCosts
{
    /**
     * @param  array<string, mixed>  $charge  the billing gate's charge plan, passed through to the event
     */
    public function record(
        string $channel,
        string $category,
        int $units = 1,
        ?int $storeId = null,
        ?int $accountId = null,
        ?int $userId = null,
        ?string $provider = null,
        ?string $feature = null,
        ?string $template = null,
        ?string $providerRef = null,
        ?string $country = null,
        ?float $unitCostMajor = null,
        ?string $currency = null,
        array $charge = [],
    ): ?MessageCost;
}
