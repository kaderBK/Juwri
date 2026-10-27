<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Contracts\Editions;

use Juwri\Core\Editions\Community\CommunityStoreLimits;
use Juwri\Core\Editions\Limits\StoreLimitGuard;
use Juwri\Core\Editions\Limits\StoreLimitRules;
use Juwri\Core\Editions\Limits\StoreLimits;

/**
 * Which store/branch limits this installation runs under (owner 2026-10-05, DECISIONS "Community limits + self-hosted
 * Chain licence"). Community default ({@see CommunityStoreLimits}): one store of each store type per business, each
 * with up to three branches, unlimited staff. The Enterprise package binds a policy that reads the verified licence
 * (a Chain licence lifts the limits up to its `max_branches`).
 *
 * A binding only names the limits; the decision itself is {@see StoreLimitRules} (one class, pure functions) and every
 * creation path reaches it through {@see StoreLimitGuard}.
 */
interface StoreLimitPolicy
{
    public function limits(): StoreLimits;
}
