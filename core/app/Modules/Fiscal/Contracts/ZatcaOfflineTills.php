<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Contracts;

/**
 * P6-94: the offline POS tills that are their own ZATCA EGS units (OP-12), as fiscal:zatca-check and the owner ZATCA
 * page see them. Bound by the Pos module; absent when it is not installed. Read-only, never calls ZATCA.
 */
interface ZatcaOfflineTills
{
    /**
     * One row per till unit of the store (active, or retired with invoices still pending).
     *
     * @return list<array{till: string, status: string, last_sync: ?\DateTimeInterface, pending: int, oldest_pending_hours: ?int, cert_expires_at: ?\DateTimeInterface}>
     */
    public function status(int $storeId): array;

    /**
     * Problems per till, all stores or one.
     *
     * @return list<array{level: string, store_id: int, till: string, detail: string}>
     */
    public function findings(?int $storeId = null): array;
}
