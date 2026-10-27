<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Providers;

use Illuminate\Database\ConnectionInterface;
use Juwri\Core\Contracts\Tenancy\StoreData;
use Juwri\Core\Contracts\Tenancy\StoreTable;
use Juwri\Core\Contracts\Tenancy\StoreTransferContext;
use Juwri\Core\Services\Tenancy\StoreDataRegistry;
use Juwri\Modules\Payments\Models\PaylinkOnboarding;
use Juwri\Modules\Payments\Services\GatewaySecretVault;
use Juwri\Modules\Payments\Services\StorePaymentGateways;

/**
 * P1-21 (J29-8): the Payments module's store-owned tables for the store-data registry.
 *
 *  - store_payment_gateways: on transfer every gateway's credentials and settings but the diner-facing title are
 *    removed and the gateway switched off (audit key `options`); the BNPL switches are store settings and stay. A
 *    duplicate copies the rows without credentials and without owner settings (titles, BNPL switches).
 *  - paylink_onboardings: the previous owner's Paylink merchant application (their national id, IBAN, CR number,
 *    merchant id) is cleared back to a draft — a later Paylink activation would otherwise provision THEIR merchant
 *    keys onto the store. Never copied.
 *  - Owner settings: every vaulted secret, and every gateway setting but its title (install flags, public keys,
 *    merchant/wallet ids, legacy plaintext secrets).
 */
final class PaymentsStoreData extends StoreData
{
    /** Gateway setting fields that stay with the store: the diner-facing label. */
    private const KEEP_FIELDS = ['title'];

    public function tables(): array
    {
        return [
            // P1-13 13g: the payments ledger (order payments follow the store; was registered by Billing).
            StoreTable::data('billing_receipts'),
            StoreTable::data('check_payments'),
            StoreTable::data('disputes'),
            StoreTable::platform('ledger_accounts', 'polymorphic owner (owner_type/owner_id) — follow-up: key by account'),
            StoreTable::data('order_refund_items'),
            StoreTable::connection('paylink_onboardings', self::resetPaylink(...)),
            StoreTable::connection('store_payment_gateways', self::resetGateways(...), 'options')->copy(self::copyGateway(...))
                ->secret(['credentials']),
            StoreTable::account('inapp_accounts'), // P5-17: the owner's in-app billing account token
            StoreTable::account('inapp_purchases'), // P5-17: the owner's App Store / Google Play subscriptions
            StoreTable::account('wallet_transactions'), // D-P1-21-15: account_id = the wallet user's account
            StoreTable::data('transaction'), // P1-13 13g (was Billing's)
            StoreTable::connection('terminal_accounts', fn (\Illuminate\Database\ConnectionInterface $db, \Juwri\Core\Contracts\Tenancy\StoreTransferContext $ctx) => $db->table('terminal_accounts')->where('store_id', $ctx->storeId)->delete())->secret(['credentials']), // P5-21: the new owner connects their own acquirer
            StoreTable::data('terminal_payments'), // P5-21: the store's SoftPOS records
        ];
    }

    public function isOwnerSetting(string $name): bool
    {
        if (GatewaySecretVault::isSecret($name)) {
            return true;
        }
        $parsed = StorePaymentGateways::parse($name);

        return $parsed !== null && in_array($parsed['gateway'], self::ownerGateways(), true)
            && $parsed['field'] !== 'title' && ! str_ends_with($parsed['field'], '_title');
    }

    /** @return list<string> gateways whose settings are the owner's merchant account (BNPL switches are not) */
    public static function ownerGateways(): array
    {
        return array_values(array_diff(StorePaymentGateways::GATEWAYS, ['bnpl']));
    }

    /** @return list<string> the legacy keys that were reset */
    public static function resetGateways(ConnectionInterface $db, StoreTransferContext $ctx): array
    {
        $reset = StorePaymentGateways::resetForTransfer($ctx->storeId, self::ownerGateways(), self::KEEP_FIELDS);
        GatewaySecretVault::forgetMemo();

        return $reset;
    }

    public static function resetPaylink(ConnectionInterface $db, StoreTransferContext $ctx): int
    {
        return $db->table('paylink_onboardings')->where('store_id', $ctx->storeId)->update([
            'status' => PaylinkOnboarding::STATUS_DRAFT,
            'provider_merchant_id' => null,
            'provider_status' => null,
            'cr_number' => null,
            'owner_id_number' => null,
            'iban' => null,
            'reject_reason' => null,
            'submitted_at' => null,
            'activated_at' => null,
            'updated_at' => now(),
        ]);
    }

    /**
     * A duplicated gateway row: no credentials, no owner settings; dropped when nothing is left.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    public static function copyGateway(array $row): ?array
    {
        $config = is_string($row['config'] ?? null) ? json_decode($row['config'], true) : null;
        $kept = [];
        foreach (is_array($config) ? $config : [] as $field => $value) {
            if (! StoreDataRegistry::isOwnerSetting('restaurant_'.$row['gateway'].'_'.$field)) {
                $kept[$field] = $value;
            }
        }
        if ($kept === []) {
            return null;
        }
        $row['config'] = json_encode($kept);
        $row['credentials'] = null;
        $row['enabled'] = filter_var($kept['install'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return $row;
    }
}
