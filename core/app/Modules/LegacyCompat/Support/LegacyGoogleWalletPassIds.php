<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Support;

use Juwri\Core\Models\Store;
use Juwri\Modules\Loyalty\Contracts\GoogleWalletPassIds;

/**
 * J52b (plan §5.1 "Google Wallet pass object/class ids already saved on phones", R8): Google keys a saved pass on its
 * class/object id, so a migrated store must keep minting and updating passes under the SOURCE ids, or every pass on a
 * phone stops updating and a re-save creates a second card. Store, customer and gift-card ids are kept by the import,
 * so only the issuer id and the id shape can differ; both come from config (legacy_compat.google_wallet).
 *
 * Applies only while the module is on AND a valid legacy issuer id AND a positive max_store_id are configured, and
 * only for stores with id <= max_store_id (the imported range; stores created after cut-over get higher ids and the
 * platform ids). Anything missing or malformed = fail closed: the platform ids are used, nothing is guessed.
 * OPEN (plan Q11): which Google issuer account the source used and whether this install's service account may write
 * to it; until the owner answers, the defaults leave this off.
 */
final class LegacyGoogleWalletPassIds implements GoogleWalletPassIds
{
    /** Google issuer ids are numeric. */
    private const ISSUER_PATTERN = '/^[0-9]{1,32}$/';

    /** Id segments allowed by Google (alphanumerics, `_`, `-`); kept short. */
    private const PREFIX_PATTERN = '/^[A-Za-z0-9_-]{1,32}$/';

    private const SEPARATOR_PATTERN = '/^[A-Za-z0-9_-]{0,8}$/';

    public function __construct(private readonly GoogleWalletPassIds $inner) {}

    public function classId(Store $store, string $kind): string
    {
        $legacy = self::settings();
        if ($legacy === null || ! self::isLegacyStore($store, $legacy['max_store_id'])) {
            return $this->inner->classId($store, $kind);
        }

        $prefix = $kind === self::GIFT_CARD ? $legacy['gift_card_class_prefix'] : $legacy['loyalty_class_prefix'];

        return $legacy['issuer_id'].'.'.$prefix.(int) $store->id;
    }

    public function objectId(Store $store, string $kind, int $subjectId): string
    {
        $legacy = self::settings();
        if ($legacy === null || ! self::isLegacyStore($store, $legacy['max_store_id'])) {
            return $this->inner->objectId($store, $kind, $subjectId);
        }

        return $this->classId($store, $kind).$legacy['object_separator'].$subjectId;
    }

    /**
     * The validated legacy id settings, or null when the module is off or anything is missing or malformed.
     *
     * @return array{issuer_id: string, max_store_id: int, loyalty_class_prefix: string, gift_card_class_prefix: string, object_separator: string}|null
     */
    public static function settings(): ?array
    {
        if (! LegacyCompatRoutes::enabled()) {
            return null;
        }

        $cfg = config('legacy_compat.google_wallet');
        if (! is_array($cfg)) {
            return null;
        }

        $issuer = is_scalar($cfg['issuer_id'] ?? null) ? trim((string) $cfg['issuer_id']) : '';
        $max = filter_var($cfg['max_store_id'] ?? 0, FILTER_VALIDATE_INT);
        $loyalty = $cfg['loyalty_class_prefix'] ?? null;
        $giftCard = $cfg['gift_card_class_prefix'] ?? null;
        $separator = $cfg['object_separator'] ?? null;

        if (preg_match(self::ISSUER_PATTERN, $issuer) !== 1 || ! is_int($max) || $max < 1
            || ! is_string($loyalty) || preg_match(self::PREFIX_PATTERN, $loyalty) !== 1
            || ! is_string($giftCard) || preg_match(self::PREFIX_PATTERN, $giftCard) !== 1
            || ! is_string($separator) || preg_match(self::SEPARATOR_PATTERN, $separator) !== 1) {
            return null;
        }

        return [
            'issuer_id' => $issuer,
            'max_store_id' => $max,
            'loyalty_class_prefix' => $loyalty,
            'gift_card_class_prefix' => $giftCard,
            'object_separator' => $separator,
        ];
    }

    private static function isLegacyStore(Store $store, int $maxStoreId): bool
    {
        $id = (int) $store->id;

        return $id > 0 && $id <= $maxStoreId;
    }
}
