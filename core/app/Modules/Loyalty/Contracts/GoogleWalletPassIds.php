<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Loyalty\Contracts;

use Juwri\Core\Models\Store;

/**
 * Google Wallet pass class/object ids. The ids are what Google keys a saved pass on, so the save-link JWT and the
 * balance sync must use the same id for the same pass. Bound to {@see \Juwri\Modules\Loyalty\Services\GoogleWallet\DefaultGoogleWalletPassIds}
 * (`{issuer}.loy{storeId}` / `{issuer}.gc{storeId}`, object = class + `c{subjectId}`); an install may decorate it
 * (e.g. to keep passes saved on phones before a platform migration working).
 */
interface GoogleWalletPassIds
{
    public const LOYALTY = 'loyalty';

    public const GIFT_CARD = 'gift_card';

    /** Class id of this store's passes of $kind ({@see LOYALTY} or {@see GIFT_CARD}). */
    public function classId(Store $store, string $kind): string;

    /** Object id of one pass: the customer id (loyalty) or the gift-card id (gift card). */
    public function objectId(Store $store, string $kind, int $subjectId): string;
}
