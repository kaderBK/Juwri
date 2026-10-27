<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hotel\Services\Hotel\Pms;

use Juwri\Modules\Hotel\Models\HotelStay;
use App\Models\Order;

/**
 * FEATURES-RUN-4 T1 / H10 — the default, always-available PMS connector: a safe
 * no-op. When no live PMS driver is configured (the shipping state), the hotel
 * module runs entirely on its own unified-ledger folio (H6) and this connector's
 * hooks do nothing. Guest validation defers to the local stay list, so
 * validateGuest() intentionally returns null (no external confirmation).
 */
class NullPmsConnector implements PmsConnectorInterface
{
    public function key(): string
    {
        return 'null';
    }

    public function label(): string
    {
        return 'None (local folio only)';
    }

    public function validateGuest(string $room, string $name): ?GuestDto
    {
        // No external PMS: local HotelFolioService::guestNameMatches is the source of truth.
        return null;
    }

    public function postCharge(HotelStay $stay, Order $order): void
    {
        // No-op: the local folio ledger entry (HotelFolioService::chargeOrder) is authoritative.
    }

    public function fetchStays(): iterable
    {
        return [];
    }
}
