<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Terminal;

use Juwri\Core\Models\Store;
use Juwri\Modules\Payments\Contracts\PaymentTerminal;

/** No terminal provider configured: no offline card (until a provider confirms deferred authorisation, owner Q1). */
final class NoTerminal implements PaymentTerminal
{
    public function key(): string
    {
        return 'none';
    }

    public function supportsOffline(Store $store): bool
    {
        return false;
    }

    public function offlineStatus(Store $store, string $reference): array
    {
        return ['state' => self::UNKNOWN];
    }
}
