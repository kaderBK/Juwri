<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Contracts\Tenancy;

/**
 * P1-21: the store transfer a reset handler runs for ({@see StoreTable::resetWith()}): the store, its previous and
 * new owner (users) and accounts.
 */
final readonly class StoreTransferContext
{
    public function __construct(
        public int $storeId,
        public int $fromUserId,
        public int $toUserId,
        public ?int $fromAccountId,
        public ?int $toAccountId,
    ) {}
}
