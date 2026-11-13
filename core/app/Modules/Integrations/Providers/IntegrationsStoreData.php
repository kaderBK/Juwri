<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Providers;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Cache;
use Juwri\Core\Contracts\Tenancy\StoreData;
use Juwri\Core\Contracts\Tenancy\StoreTable;
use Juwri\Core\Contracts\Tenancy\StoreTransferContext;

/**
 * P1-21 (J29-8): the Integrations module's store-owned tables for the store-data registry.
 *
 *  - credentials: every vaulted secret of the store (gateway secrets, accounting, Google, POS + webhook tokens,
 *    aggregators, couriers, WhatsApp, SMS, telephony, push, social login, AI, storage, PMS, fiscal APIs) is DELETED
 *    on transfer — a switched-off vault row would be merged back by the next save (WI-06f). Platform and reseller
 *    rows have no store and are never touched. Never copied.
 *  - store_imports: the import's user follows the new owner.
 *  - Owner settings: the tracking ids and the Conversions API token send diners' data to the owner's accounts.
 */
final class IntegrationsStoreData extends StoreData
{
    private const OWNER_SETTINGS = ['meta_pixel_id', 'meta_capi_token', 'integration_ga_id', 'integration_meta_pixel_id'];

    public function tables(): array
    {
        return [
            StoreTable::owned('api_tokens')->notExported(),
            StoreTable::connection('credentials', self::deleteCredentials(...))->notExported(),
            StoreTable::data('gbp_reviews'),
            StoreTable::data('import_batches'),
            StoreTable::data('odoo_sync_records'),
            StoreTable::data('store_imports')->repoint(['user_id']),
            StoreTable::data('switch_imports')->repoint(['user_id'])->notExported(), // P4-22: encrypted import rows (personal data)
            StoreTable::child('webhook_deliveries', 'endpoint_id', 'webhook_endpoints'),
            StoreTable::owned('webhook_endpoints')->secret(['secret']),
        ];
    }

    public function isOwnerSetting(string $name): bool
    {
        return in_array($name, self::OWNER_SETTINGS, true);
    }

    /** Google Business performance data fetched with the previous owner's Google connection. */
    public function forgetStoreCaches(int $storeId): void
    {
        Cache::forget("gbp_perf:{$storeId}");
    }

    /** @return list<string> the features whose credentials were deleted */
    public static function deleteCredentials(ConnectionInterface $db, StoreTransferContext $ctx): array
    {
        $features = $db->table('credentials')->where('store_id', $ctx->storeId)->orderBy('feature')->pluck('feature')->all();
        if ($features !== []) {
            $db->table('credentials')->where('store_id', $ctx->storeId)->delete();
        }

        return array_values(array_unique(array_map('strval', $features)));
    }
}
