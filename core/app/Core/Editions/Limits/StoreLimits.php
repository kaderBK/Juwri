<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Editions\Limits;

/**
 * The store/branch limits of one installation (built only by {@see StoreLimitRules}). A null limit means "no limit".
 *
 * - $storesPerType     stores of one store type (vertical) per business
 * - $branchesPerStore  branches of one store
 * - $branchesTotal     branches across the whole business (Chain licence: `max_branches`)
 */
final class StoreLimits
{
    public function __construct(
        public readonly string $edition,
        public readonly ?int $storesPerType,
        public readonly ?int $branchesPerStore,
        public readonly ?int $branchesTotal,
    ) {}

    public function unlimited(): bool
    {
        return $this->storesPerType === null && $this->branchesPerStore === null && $this->branchesTotal === null;
    }

    /** @return array{edition: string, stores_per_type: int|null, branches_per_store: int|null, branches_total: int|null} */
    public function toArray(): array
    {
        return [
            'edition' => $this->edition,
            'stores_per_type' => $this->storesPerType,
            'branches_per_store' => $this->branchesPerStore,
            'branches_total' => $this->branchesTotal,
        ];
    }
}
