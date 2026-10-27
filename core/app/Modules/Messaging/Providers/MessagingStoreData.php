<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Providers;

use Illuminate\Database\ConnectionInterface;
use Juwri\Core\Contracts\Tenancy\StoreData;
use Juwri\Core\Contracts\Tenancy\StoreTable;
use Juwri\Core\Contracts\Tenancy\StoreTransferContext;

/**
 * P1-21 (J29-8): the Messaging module's store-owned tables for the store-data registry.
 *
 *  - whatsapp_routes: the previous owner's sender ids routed to the store are deleted on transfer.
 *  - push_subscriptions: the previous owner's devices (owner app + installed dashboard: rows with a user_id) are
 *    unsubscribed — they kept receiving the store's order pushes after a transfer. Staff devices stay.
 *  - message_costs, whatsapp_usage: cost/usage ledgers stay with the account that incurred them.
 *  - Conversations, consents, opt-outs, templates and logs follow the store. Never copied.
 */
final class MessagingStoreData extends StoreData
{
    public function tables(): array
    {
        return [
            StoreTable::data('canned_replies'),
            StoreTable::data('customer_notifications'),
            StoreTable::data('customer_push_subscriptions'),
            StoreTable::ledger('message_costs'),
            StoreTable::connection('push_subscriptions', fn (ConnectionInterface $db, StoreTransferContext $ctx): int => $db->table('push_subscriptions')->where('store_id', $ctx->storeId)->whereNotNull('user_id')->delete())
                ->secret(['auth_token']),
            StoreTable::data('sms_logs'),
            StoreTable::data('sms_optouts'),
            StoreTable::data('whatsapp_consents'),
            StoreTable::data('whatsapp_conversations'),
            StoreTable::data('whatsapp_inbound_messages'),
            StoreTable::data('whatsapp_logs'),
            StoreTable::data('whatsapp_number_healths'),
            StoreTable::data('whatsapp_optouts'),
            StoreTable::connection('whatsapp_routes', fn (ConnectionInterface $db, StoreTransferContext $ctx): int => $db->table('whatsapp_routes')->where('store_id', $ctx->storeId)->delete()),
            StoreTable::data('whatsapp_templates'),
            StoreTable::ledger('whatsapp_usage'),
            StoreTable::data('whatsapp_windows'),
        ];
    }
}
