<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Contracts;

/**
 * J52b: the public read of the per-store payment option names for modules above Payments (the LegacyCompat importer):
 * which `restaurant_<gateway>_<field>` names are gateway settings, and which option names are secrets. The one list
 * stays in StorePaymentGateways / GatewaySecretVault; this contract only exposes it.
 */
interface StoreGatewayKeys
{
    /**
     * Gateway + field of a legacy gateway option name, or null when the name is not a gateway setting.
     *
     * @return array{gateway: string, field: string}|null
     */
    public function parse(string $name): ?array;

    /** True when the option name is a credential (stored encrypted, never shown). */
    public function isSecret(string $name): bool;
}
