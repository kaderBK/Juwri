<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Contracts\Editions;

use Juwri\Core\Editions\Community\CommunityEdition;

/**
 * P1-13 (plan-13 §3): which edition this installation runs. Community default
 * ({@see CommunityEdition}); the ee provider rebinds it from a verified licence.
 */
interface Edition
{
    /** 'community', or the licensed edition ('enterprise' | 'platform' | 'development' — local/testing only). */
    public function name(): string;

    public function isCommunity(): bool;

    /** Native and desktop clients (desktop, kiosk, owner app, driver app) ship with the licensed edition. */
    public function companionClients(): bool;

    /** Does the licence grant this ee feature (licence `features[]`)? Always false in Community. */
    public function licensed(string $feature): bool;

    /** Account cap of the licence (null = no cap). Community: a single account. */
    public function maxTenants(): ?int;

    /** Operator notice (admin banner) while the licence is in its grace period or is a development licence, else null. */
    public function notice(): ?string;

    /**
     * Who the licence was issued to (admin footer "Licensed to <licensee> · <licence_id>"): ['licensee' => ..., 'licence_id' => ...]
     * for a verified licence (a development licence too), null in Community.
     *
     * @return array{licensee: string, licence_id: string}|null
     */
    public function licensedTo(): ?array;
}
