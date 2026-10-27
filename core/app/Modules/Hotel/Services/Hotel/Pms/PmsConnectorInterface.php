<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hotel\Services\Hotel\Pms;

use Juwri\Modules\Hotel\Models\HotelStay;
use App\Models\Order;

/**
 * FEATURES-RUN-4 T1 / H10 — the PMS (property-management-system) connector seam.
 *
 * The marquee "advanced" hotel integration is folio-posting into OPERA Cloud /
 * Mews: guest validation against the PMS in-house list, and pushing each
 * charge-to-room order onto the PMS folio so it settles at PMS checkout. This
 * interface is that seam — NO live network calls ship in FEATURES-RUN-4. The
 * default binding is {@see NullPmsConnector} (a safe no-op). Real drivers
 * (opera_cloud, mews) are DEFERRED-LIVE: they need OPERA Cloud / Mews sandbox
 * credentials before they can be written.
 *
 * Mirrors the driver-manager shape used by PosBridge / Aggregators / payments:
 * per-restaurant config lives in a {@see \Juwri\Core\Models\Credential} row with
 * feature='pms' and a provider key; {@see PmsManager::for()} resolves the driver.
 */
interface PmsConnectorInterface
{
    /** Provider key, e.g. 'null' | 'opera_cloud' | 'mews' | 'manual'. */
    public function key(): string;

    /** Human label for the owner UI. */
    public function label(): string;

    /**
     * Validate that a guest with $name is in-house for $room against the PMS.
     * Returns a {@see GuestDto} when the PMS confirms the guest, else null.
     * Must NOT throw on a normal "not found" — return null.
     */
    public function validateGuest(string $room, string $name): ?GuestDto;

    /**
     * Post a charge-to-room order onto the guest's PMS folio (folio-posting hook).
     * Called from the H6 charge path when a PMS driver is configured. The Null
     * driver is a no-op (the local unified-ledger folio remains authoritative).
     */
    public function postCharge(HotelStay $stay, Order $order): void;

    /**
     * Fetch current in-house stays from the PMS (for a future reconcile/import).
     * Returns an iterable of {@see GuestDto}. The Null driver yields nothing.
     *
     * @return iterable<GuestDto>
     */
    public function fetchStays(): iterable;
}
