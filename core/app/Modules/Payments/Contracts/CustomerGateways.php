<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Contracts;

use Illuminate\Support\Collection;

/**
 * P1-23 public surface: the gateways a store's own customers pay the store through outside an order (gift cards,
 * store-credit top-ups, reservation and catering deposits) — always the STORE's gateways, charged with the store's
 * credentials. Implemented by Payments\Services\StoreCustomerGateways.
 */
interface CustomerGateways
{
    /** @return Collection<int, object{id: string, payment_folder: string, payment_title: string}> */
    public function forStore(int $storeId): Collection;

    /** The chosen gateway's folder when the store offers it to its customers, else null. */
    public function resolve(int $storeId, mixed $choice): ?string;
}
