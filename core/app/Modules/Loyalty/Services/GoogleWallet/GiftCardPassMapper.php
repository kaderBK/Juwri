<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Loyalty\Services\GoogleWallet;

use App\Models\GiftCard;
use Juwri\Core\Models\Store;
use Juwri\Modules\Loyalty\Contracts\GoogleWalletPassIds;

/**
 * Maps a store + one gift card into a Google Wallet GiftCardClass + GiftCardObject.
 *
 * Class id  : {issuer}.gc{storeId}          (one per store)
 * Object id : {issuer}.gc{storeId}c{cardId} (one per card; the CARD is the subject,
 *             not the customer — a card may be gifted to a recipient by email/phone)
 *
 * Balance is read from the ledger (GiftCard::balance()), never a stored column, and
 * carried as a Google Money (micros + currencyCode). State mirrors the card status.
 */
class GiftCardPassMapper
{
    use PassBranding;

    public static function classId(Store $store): string
    {
        return app(GoogleWalletPassIds::class)->classId($store, GoogleWalletPassIds::GIFT_CARD);
    }

    public static function objectId(Store $store, int $cardId): string
    {
        return app(GoogleWalletPassIds::class)->objectId($store, GoogleWalletPassIds::GIFT_CARD, $cardId);
    }

    /**
     * The GiftCardClass definition (store-level branding).
     *
     * @return array<string, mixed>
     */
    public static function class(Store $store): array
    {
        $class = [
            'id' => self::classId($store),
            'issuerName' => self::issuerName($store),
            'reviewStatus' => 'UNDER_REVIEW',
            'hexBackgroundColor' => self::hexColor($store),
            'programName' => self::issuerName($store).' — '.___('Gift Card'),
        ];

        $logo = self::logoUri($store);
        if ($logo !== null) {
            $class['programLogo'] = ['sourceUri' => ['uri' => $logo]];
        }

        // biolink-tpl lane-d (T3-1): optional bio-page link on the saved pass.
        $bioLinks = self::bioLinksModule($store);
        if ($bioLinks !== null) {
            $class['linksModuleData'] = $bioLinks;
        }

        return $class;
    }

    /**
     * The GiftCardObject definition (per-card balance).
     *
     * @return array<string, mixed>
     */
    public static function object(Store $store, GiftCard $card): array
    {
        $balance = (float) $card->balance();
        $currency = strtoupper((string) $card->currency);

        return [
            'id' => self::objectId($store, (int) $card->id),
            'classId' => self::classId($store),
            'state' => self::state($card),
            'cardNumber' => (string) $card->code,
            'balance' => [
                'micros' => (int) round($balance * 1000000),
                'currencyCode' => $currency,
            ],
            'balanceUpdateTime' => now()->toRfc3339String(),
            'barcode' => [
                'type' => 'QR_CODE',
                'value' => (string) $card->code,
            ],
        ];
    }

    /**
     * Full save-link payload: one class + one object.
     *
     * @return array<string, mixed>
     */
    public static function payload(Store $store, GiftCard $card): array
    {
        return [
            'giftCardClasses' => [self::class($store)],
            'giftCardObjects' => [self::object($store, $card)],
        ];
    }

    /** Map the card status onto the Google gift-card object state enum. */
    private static function state(GiftCard $card): string
    {
        return match ((string) $card->status) {
            'active' => 'ACTIVE',
            'expired' => 'EXPIRED',
            default => 'INACTIVE', // pending / disabled / anything else
        };
    }
}
