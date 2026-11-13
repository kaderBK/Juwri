<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Laundry\Services\Laundry;

use Juwri\Modules\Verticals\Services\Fulfillment\RetailOrderBoardService;

/**
 * rev/multi-vertical Lane D — D3: service-board state labels (DECISIONS A9:
 * "states received→processing→ready→out_for_delivery→done").
 *
 * {@see RetailOrderBoardService} (Lane B, consumed — never forked) owns the
 * STATE MACHINE (new→packing→ready→shipped|collected→done) and its dashboard
 * board (posts.orders.board / owner/orders/board.blade.php)
 * hardcodes goods labels for it ("New"/"Packing"/.../"Shipped"/"Collected").
 * That view is Lane B's own file — this pack's hard rule is to consume Lane
 * B's seams, never fork or edit them — so Lane D renders the SAME state
 * machine through its OWN page (LaundryServiceBoardController +
 * orders/service-board.blade.php) with these translated words instead.
 *
 * P3-11 (F3): a laundry order that is brought back (`delivery`) now ends in `shipped` ("Out for delivery", see
 * RetailOrderBoardService::terminalHandoffState); one the customer picks up (`pickup_slot`) ends in `collected`
 * ("Collected"). Both columns are real, so both are listed.
 */
class ServiceBoardVocab
{
    /**
     * @return array<string,string> RetailOrderBoardService::STATES => translated label
     */
    public static function labels(): array
    {
        return [
            'new' => ___('Received'),
            'packing' => ___('Processing'),
            'ready' => ___('Ready'),
            'shipped' => ___('Out for delivery'),
            'collected' => ___('Collected'),
            'done' => ___('Done'),
        ];
    }

    public static function label(string $state): string
    {
        return self::labels()[$state] ?? $state;
    }
}
