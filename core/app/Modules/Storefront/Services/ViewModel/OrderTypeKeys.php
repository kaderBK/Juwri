<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\ViewModel;

/**
 * P3-02: the view model speaks the kit's order-type keys (viewmodel.schema.json `context.order_types[].key`);
 * core stores its fulfilment mode keys (config/verticals.php `fulfillment`). One map, both ways, so the storefront
 * JS (P3-03) and the headless API (P3-06b) send back exactly what core accepts.
 *
 * `in_store` (goods bought or collected at the counter, no slot) is the kit's `pickup`. A food store has no `in_store`:
 * there the kit's `pickup` is the cafe's collection — core `takeaway` (kit/api README §7 food: schedule-ahead
 * `/delivery/slots?order_type=pickup`; the food-pickup examples) — see {@see toCoreFor()}.
 */
final class OrderTypeKeys
{
    /** core fulfilment mode => kit order-type key */
    public const TO_KIT = [
        'on-table' => 'dine_in',
        'takeaway' => 'takeaway',
        'delivery' => 'delivery',
        'room_service' => 'room_service',
        'ship' => 'ship',
        'pickup_slot' => 'pickup_slot',
        'appointment' => 'appointment',
        'at_home' => 'at_home',
        'in_store' => 'pickup',
    ];

    /** Kiosk screens never offer these (THEME-CONTRACT §2.5: no delivery, ship or address flows at a kiosk). */
    public const NOT_AT_KIOSK = ['delivery', 'ship', 'at_home', 'room_service'];

    public static function toKit(string $core): ?string
    {
        return self::TO_KIT[$core] ?? null;
    }

    public static function toCore(string $kit): ?string
    {
        $core = array_search($kit, self::TO_KIT, true);

        return $core === false ? null : $core;
    }

    /**
     * The core mode of a kit key for a store offering `$offered` (core keys). Same as {@see toCore()}, except the kit's
     * `pickup` at a store without `in_store` but with `takeaway` (every food store) is that store's `takeaway`; a store
     * offering `in_store` keeps it. Null when the store offers neither.
     *
     * @param  array<int, string>  $offered
     */
    public static function toCoreFor(string $kit, array $offered): ?string
    {
        $core = self::toCore($kit);
        if ($kit === 'pickup' && ! in_array('in_store', $offered, true) && in_array('takeaway', $offered, true)) {
            return 'takeaway';
        }

        return $core !== null && in_array($core, $offered, true) ? $core : null;
    }
}
