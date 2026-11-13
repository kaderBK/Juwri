<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Terminal;

use Juwri\Core\Models\Store;
use Juwri\Modules\Payments\Contracts\PaymentTerminal;
use Juwri\Modules\Payments\Contracts\TerminalRejected;
use Juwri\Modules\Payments\Models\TerminalPayment;
use Juwri\Modules\Payments\Services\Terminal\SoftPos\SoftPos;

/**
 * P5-21: the store-aware PaymentTerminal. Offline card (deferred authorisation) needs ALL of: the platform switch
 * (pos.offline.card_enabled, OFF until OP-0), the provider confirming it (softpos.<provider>.offline_supported), and
 * the store's enabled account. Without a SoftPOS provider it answers exactly like NoTerminal.
 */
final class StoreTerminals implements PaymentTerminal
{
    public function __construct(private readonly SoftPos $softPos) {}

    public function key(): string
    {
        return $this->softPos->enabledProviders()[0] ?? 'none';
    }

    public function supportsOffline(Store $store): bool
    {
        if (! (bool) config('pos.offline.card_enabled', false)) {
            return false;
        }
        $account = $this->softPos->activeAccount($store);

        return $account !== null && (bool) config('softpos.'.SoftPos::PROVIDERS[$account->provider].'.offline_supported', false);
    }

    public function offlineStatus(Store $store, string $reference): array
    {
        $row = TerminalPayment::query()->where('store_id', $store->id)->where('provider_reference', $reference)->where('kind', 'charge')->first();
        if ($row === null) {
            return ['state' => self::UNKNOWN];
        }
        try {
            $this->softPos->reconcile($row);
        } catch (TerminalRejected) {
            return ['state' => self::UNKNOWN];
        }
        $view = SoftPos::view($row);
        $state = in_array($view['state'], [self::AUTHORISED, self::CAPTURED, self::DECLINED, self::PENDING], true) ? $view['state'] : self::UNKNOWN;

        return ['state' => $state] + array_intersect_key($view, array_flip(['masked_pan', 'scheme', 'auth_code']));
    }
}
