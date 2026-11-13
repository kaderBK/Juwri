<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront\Drops;

use App\Models\StoreTable;
use Keepsuit\Liquid\Drop;

/**
 * P2-2: the QR table the diner scanned. Nil off-QR. Exposes the display number
 * only — the table `key` (order-routing identifier) is intentionally NOT
 * exposed to theme markup.
 */
class TableDrop extends Drop
{
    public function __construct(protected StoreTable $table) {}

    public function id(): int
    {
        return (int) $this->table->id;
    }

    public function table_no(): ?string
    {
        return (string) $this->table->table_no;
    }
}
