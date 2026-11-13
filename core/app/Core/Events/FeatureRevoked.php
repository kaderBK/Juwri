<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** P1 (feature-platform): fired by EntitlementWriter on every revoke. */
// P6-61: dispatched only after the writer's transaction commits (dropped on rollback), so the listener's
// cache bust cannot be undone by a concurrent read re-caching the pre-commit state.
class FeatureRevoked implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $userId,
        public string $featureKey,
        public string $sourceType,
        public ?int $sourceId = null,
    ) {}
}
