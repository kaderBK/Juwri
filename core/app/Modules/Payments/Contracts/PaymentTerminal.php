<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Contracts;

use Juwri\Core\Models\Store;

/**
 * In-person card acceptance through a certified SoftPOS provider SDK (payment-terminal design note). This first slice
 * covers what the offline POS needs (OFFLINE-POS-SPEC §7): whether the store's provider can take a card offline
 * (deferred authorisation: the SDK stores the encrypted transaction and forwards it later), and the provider's status of
 * a forwarded payment. We never see or store card data — only the provider's reference.
 */
interface PaymentTerminal
{
    public const AUTHORISED = 'authorised';

    public const CAPTURED = 'captured';

    public const DECLINED = 'declined';

    /** Not forwarded yet, or the provider has no answer yet. */
    public const PENDING = 'pending';

    public const UNKNOWN = 'unknown';

    /** Provider key, e.g. geidea_softpos, edfapay_softpos; 'none' when the store has no terminal provider. */
    public function key(): string;

    /** Can this store's provider take a card offline (deferred authorisation)? */
    public function supportsOffline(Store $store): bool;

    /**
     * The provider's status of a payment the SDK took offline (authoritative; the device only reports a hint).
     *
     * @return array{state: string, masked_pan?: string, scheme?: string, auth_code?: string}
     */
    public function offlineStatus(Store $store, string $reference): array;
}
