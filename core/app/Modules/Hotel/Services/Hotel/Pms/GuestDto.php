<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hotel\Services\Hotel\Pms;




/**
 * FEATURES-RUN-4 T1 / H10 — a PMS-validated guest. Returned by
 * {@see PmsConnectorInterface::validateGuest()} when the property-management
 * system confirms a guest is in-house for a room. Deliberately tiny: the seam
 * only needs enough to greet + match a charge-to-room guest.
 */
class GuestDto
{
    public function __construct(
        public string $name,
        public string $room,
        public ?string $reservationId = null,
        public ?string $folioId = null,
        /** @var array<string,mixed> raw provider payload for debugging/extension */
        public array $raw = [],
    ) {}
}
