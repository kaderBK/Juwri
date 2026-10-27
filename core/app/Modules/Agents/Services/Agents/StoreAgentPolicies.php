<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Services\Agents;

use Juwri\Modules\Agents\Contracts\AgentPolicyStore;

/**
 * P1-13 13h Community default of {@see AgentPolicyStore}: per-store rules, read exactly as the callers read them
 * before (the store's `agent_chat_enabled` and `agent_wallet_spend` settings).
 */
final class StoreAgentPolicies implements AgentPolicyStore
{
    public function chatEnabled(int $storeId): bool
    {
        return (string) (store_options($storeId)->agent_chat_enabled ?? '') === '1';
    }

    public function walletSpendAllowed(int $storeId): bool
    {
        return (int) store_options($storeId, 'agent_wallet_spend') === 1;
    }
}
