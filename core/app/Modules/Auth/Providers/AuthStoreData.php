<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Providers;

use Juwri\Core\Contracts\Tenancy\StoreData;
use Juwri\Core\Contracts\Tenancy\StoreTable;

/**
 * P1-21 (J29-8): the Auth module's owner-level tables for the store-data registry — the owner's sign-in audit and
 * passkeys (public keys) go with an account export/delete/restore.
 */
final class AuthStoreData extends StoreData
{
    public function tables(): array
    {
        return [
            StoreTable::owned('login_audits'),
            StoreTable::owned('passkeys'),
            // BL-036 A1a: pending email-change undo links die with the account and never travel in an export
            // (a signed link would not match on another install).
            StoreTable::owned('email_change_undos')->notExported(),
        ];
    }
}
