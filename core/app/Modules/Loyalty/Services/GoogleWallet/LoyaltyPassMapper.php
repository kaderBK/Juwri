<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Loyalty\Services\GoogleWallet;

use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyProgram;
use Juwri\Core\Models\Store;
use Juwri\Modules\Loyalty\Contracts\GoogleWalletPassIds;

/**
 * Maps a store's loyalty program + a customer's account into a Google Wallet
 * LoyaltyClass + LoyaltyObject payload.
 *
 * Class id  : {issuer}.loy{storeId}      (one per store)
 * Object id : {issuer}.loy{storeId}c{customerId}  (one per member)
 * (ids come from the bound GoogleWalletPassIds, so the JWT and the balance sync always agree)
 *
 * Both stamps and points programs render as a single labeled LoyaltyObject
 * balance (C0 decision): points → "N"; stamps → "N / threshold" (progress).
 */
class LoyaltyPassMapper
{
    use PassBranding;

    public static function classId(Store $store): string
    {
        return app(GoogleWalletPassIds::class)->classId($store, GoogleWalletPassIds::LOYALTY);
    }

    public static function objectId(Store $store, int $customerId): string
    {
        return app(GoogleWalletPassIds::class)->objectId($store, GoogleWalletPassIds::LOYALTY, $customerId);
    }

    /**
     * The LoyaltyClass definition (store-level branding).
     *
     * @return array<string, mixed>
     */
    public static function class(Store $store, LoyaltyProgram $program): array
    {
        $class = [
            'id' => self::classId($store),
            'issuerName' => self::issuerName($store),
            'programName' => self::programName($store, $program),
            'reviewStatus' => 'UNDER_REVIEW',
            'hexBackgroundColor' => self::hexColor($store),
        ];

        $logo = self::logoUri($store);
        if ($logo !== null) {
            $class['programLogo'] = ['sourceUri' => ['uri' => $logo]];
        }

        $reward = trim((string) ($program->reward_description ?? ''));
        if ($reward !== '') {
            $class['textModulesData'] = [[
                'header' => ___('Reward'),
                'body' => $reward,
                'id' => 'reward',
            ]];
        }

        // biolink-tpl lane-d (T3-1): optional bio-page link on the saved pass.
        $bioLinks = self::bioLinksModule($store);
        if ($bioLinks !== null) {
            $class['linksModuleData'] = $bioLinks;
        }

        return $class;
    }

    /**
     * The LoyaltyObject definition (per-member balance).
     *
     * @return array<string, mixed>
     */
    public static function object(Store $store, LoyaltyProgram $program, LoyaltyAccount $account, ?Customer $customer = null): array
    {
        $isStamps = $program->type === 'stamps';
        $balance = (int) $account->balance;
        $threshold = (int) $program->reward_threshold;

        $balanceString = $isStamps && $threshold > 0
            ? $balance.' / '.$threshold
            : (string) $balance;

        $object = [
            'id' => self::objectId($store, (int) $account->customer_id),
            'classId' => self::classId($store),
            'state' => 'ACTIVE',
            'loyaltyPoints' => [
                'label' => $isStamps ? ___('Stamps') : ___('Points'),
                'balance' => ['string' => $balanceString],
            ],
            'accountId' => 'LOY-'.(int) $account->id,
            'accountName' => self::accountName($customer),
            'barcode' => [
                'type' => 'QR_CODE',
                'value' => 'LOY-'.(int) $account->id,
            ],
        ];

        $tier = $program->tierFor($balance);
        if ($tier) {
            $object['textModulesData'] = [[
                'header' => ___('Tier'),
                'body' => $tier,
                'id' => 'tier',
            ]];
        }

        return $object;
    }

    /**
     * Full save-link payload: one class + one object.
     *
     * @return array<string, mixed>
     */
    public static function payload(Store $store, LoyaltyProgram $program, LoyaltyAccount $account, ?Customer $customer = null): array
    {
        return [
            'loyaltyClasses' => [self::class($store, $program)],
            'loyaltyObjects' => [self::object($store, $program, $account, $customer)],
        ];
    }

    private static function programName(Store $store, LoyaltyProgram $program): string
    {
        $reward = trim((string) ($program->reward_description ?? ''));

        return self::issuerName($store).' — '.($reward !== '' ? $reward : ___('Rewards'));
    }

    private static function accountName(?Customer $customer): string
    {
        if (! $customer) {
            return ___('Member');
        }
        $name = trim((string) ($customer->name ?? ''));

        return $name !== '' ? $name : ___('Member');
    }
}
