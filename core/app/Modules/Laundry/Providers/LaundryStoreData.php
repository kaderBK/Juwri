<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Laundry\Providers;

use Juwri\Core\Contracts\Tenancy\StoreData;
use Juwri\Core\Contracts\Tenancy\StoreTable;

/** P3-11: the Laundry module's store-owned table: the slot holds of a store follow it on transfer and are never copied. */
final class LaundryStoreData extends StoreData
{
    public function tables(): array
    {
        return [StoreTable::data('laundry_slot_holds')];
    }
}
