<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Contracts\Tenancy;

/**
 * P1-21 (J29-8): what happens to the rows of one store-/account-owned table when the store-data engine runs an
 * operation on a store.
 *
 * Transfer (the store joins another account; one transaction):
 *  - Move: the store's rows follow the store (account_id re-keyed).
 *  - Reset: the rows follow the store, but the previous owner's connection is reset (deleted, switched off or
 *    cleared) by the table's reset handler — the new owner reconnects.
 *  - StayWithOldAccount: the rows keep the previous account (cost ledgers, account-level rows).
 *
 * Duplicate (a copy of the store under the same account):
 *  - Copy: the rows are copied, every reference remapped onto the copies.
 *  - Exclude: nothing is copied (orders, customers, secrets, domains, integrations, logs, …).
 */
enum StoreDataPolicy: string
{
    case Move = 'move';
    case Copy = 'copy';
    case Reset = 'reset';
    case Exclude = 'exclude';
    case StayWithOldAccount = 'stay-with-old-account';

    /** @return list<self> */
    public static function forTransfer(): array
    {
        return [self::Move, self::Reset, self::StayWithOldAccount];
    }

    /** @return list<self> */
    public static function forDuplicate(): array
    {
        return [self::Copy, self::Exclude];
    }
}
