<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Contracts;

use App\Models\Order;
use Juwri\Modules\Ordering\Models\ReturnRequest;

/**
 * P3-08 (aisle §10, D7): goods returns inside the store's returns window. With returns refunds off (the default) a
 * return resolves as an exchange or store credit; a refund to the original payment only when the owner turned
 * `refunds_returns` on. Every refusal throws \DomainException with a stable code as its message.
 */
interface Returns
{
    /**
     * What the shopper may return on this order now: `offered` (window open), `until` (window end, ISO 8601 or
     * null), `resolutions` (allowed) and `lines` [{order_item_id, name, qty_returnable, unit_minor}].
     *
     * @return array{offered: bool, until: ?string, resolutions: list<string>, lines: list<array{order_item_id: int, name: string, qty_returnable: int, unit_minor: int}>}
     */
    public function eligibility(Order $order): array;

    /**
     * Open a return request for the given lines ([{order_item_id, qty}]). Codes: returns_closed, resolution,
     * lines, line_qty.
     *
     * @param  list<array{order_item_id: int|string, qty: int|string}>  $lines
     */
    public function request(Order $order, array $lines, string $resolution, ?string $reason = null): ReturnRequest;

    /** Owner: approve or reject a requested return. Code: status. */
    public function decide(ReturnRequest $return, bool $approve, ?string $note = null): ReturnRequest;

    /** Owner: the goods came back (optionally put back on stock). Code: status. */
    public function receive(ReturnRequest $return, bool $restock = true): ReturnRequest;

    /**
     * Owner: complete a received return — store credit is issued, a refund goes back through the store's own
     * gateway, an exchange moves no money. Codes: status, no_customer, store_credit_off, currency, refund_refused,
     * refund_cap.
     */
    public function complete(ReturnRequest $return): ReturnRequest;
}
