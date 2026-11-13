<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

/** Storefront price callable that formats with the store's own money settings (`store_money_format`). */
final class StoreMoneyFormatter
{
    public function __construct(private mixed $storeId) {}

    public function __invoke(mixed $amount): mixed
    {
        return store_money_format($amount, $this->storeId);
    }
}
