<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Contracts;

use Juwri\Modules\Agents\Services\Agents\StoreAgentPolicies;

/**
 * P1-13 13h extension point (EDITIONS: Agents split): the rules an AI agent runs under. Community answers from each
 * store's own settings ({@see StoreAgentPolicies}, today's per-store rules); ee may bind org-wide governance (policies
 * across an organisation's stores, spend caps, audit) — not built yet.
 */
interface AgentPolicyStore
{
    /** May the public on-menu chat agent (and its speech-to-text) answer for this store? */
    public function chatEnabled(int $storeId): bool;

    /** May an agent spend a verified diner's store credit at this store (decision B)? */
    public function walletSpendAllowed(int $storeId): bool;
}
