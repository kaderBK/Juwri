<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

use Juwri\Core\Models\Store;

/**
 * P6-46: pharmacy discretion for customer messages (SMS, WhatsApp, email). When a pharmacy store has
 * "discreet messages" on (the default for pharmacies; a plain store option, no migration), every customer
 * message builder that would name a product calls this helper instead, so the text says "N items" and never
 * the medicine name. Other store types are never affected.
 */
final class MessageDiscretion
{
    public const OPTION = 'pharmacy_discreet_messages';

    public static function enabled(Store|int|null $store): bool
    {
        $store = is_int($store) ? Store::query()->find($store) : $store;
        if ($store === null || (string) $store->store_type !== 'pharmacy') {
            return false;
        }

        return filter_var(store_options((int) $store->id, self::OPTION, '1'), FILTER_VALIDATE_BOOLEAN);
    }

    /** One product as a customer message should name it: the name, or "1 item" when discreet. */
    public static function itemLabel(Store|int|null $store, string $name, int $count = 1): string
    {
        return self::enabled($store) ? ___n('item', max(1, $count)) : $name;
    }

    /**
     * A line for several products: the names joined, or a neutral "N items" when discreet.
     *
     * @param  list<string>  $names
     */
    public static function itemsLine(Store|int|null $store, array $names): string
    {
        return self::enabled($store) ? ___n('item', count($names)) : implode(', ', $names);
    }
}
