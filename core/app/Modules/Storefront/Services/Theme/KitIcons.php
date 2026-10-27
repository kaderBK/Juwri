<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\Theme;

/**
 * P3-05: the kit's icon names -> <x-ui.icon> names (THEME-CONTRACT D4 / J4: Tabler through the one icon component).
 *
 * MAP mirrors tools/themes/kit/icons-map.json (the kit's own map; KitIconsTest keeps the two equal). `flip` = the
 * icon is directional and mirrors in RTL (contract section 6). Themes print icons through KitView::icon() only.
 */
final class KitIcons
{
    /** @var array<string, array{0: string, 1: bool}> kit name => [x-ui.icon name, mirror in RTL] */
    public const MAP = [
        'alert-circle' => ['alert-circle', false],
        'alert-triangle' => ['alert-triangle', false],
        'apple' => ['brand-apple', false],
        'armchair' => ['armchair', false],
        'arrow-left' => ['arrow-left', true],
        'arrow-right' => ['arrow-right', true],
        'ban' => ['ban', false],
        'delete' => ['backspace', false],
        'banknote' => ['cash', false],
        'bell' => ['bell', false],
        'bell-ring' => ['bell-ringing', false],
        'bike' => ['bike', true],
        'calendar' => ['calendar', false],
        'calendar-clock' => ['calendar-time', false],
        'calendar-plus' => ['calendar-plus', false],
        'calendar-x' => ['calendar-x', false],
        'check' => ['check', false],
        'chevron-down' => ['chevron-down', false],
        'chevron-left' => ['chevron-left', true],
        'chevron-right' => ['chevron-right', true],
        'chevron-up' => ['chevron-up', false],
        'circle-check' => ['circle-check', false],
        'clock' => ['clock', false],
        'coffee' => ['coffee', false],
        'credit-card' => ['credit-card', false],
        'external-link' => ['external-link', true],
        'flame' => ['flame', false],
        'globe' => ['world', false],
        'heart' => ['heart', false],
        'help-circle' => ['help-circle', false],
        'home' => ['home', false],
        'hourglass' => ['hourglass', false],
        'house' => ['home', false],
        'info' => ['info-circle', false],
        'instagram' => ['brand-instagram', false],
        'layout-grid' => ['layout-grid', false],
        'lock' => ['lock', false],
        'map-pin' => ['map-pin', false],
        'map-pin-off' => ['map-pin-off', false],
        'message-circle' => ['message-circle', false],
        'minus' => ['minus', false],
        'moon' => ['moon', false],
        'navigation' => ['navigation', true],
        'package' => ['package', false],
        'person-standing' => ['accessible', false],
        'phone' => ['phone', false],
        'plus' => ['plus', false],
        'receipt' => ['receipt', false],
        'refresh-cw' => ['refresh', false],
        'repeat' => ['repeat', false],
        'rotate-ccw' => ['arrow-back-up', false],
        'ruler' => ['ruler', false],
        'scale' => ['scale', false],
        'scan-barcode' => ['barcode', false],
        'scissors' => ['scissors', false],
        'search' => ['search', false],
        'search-x' => ['search-off', false],
        'share-2' => ['share', false],
        'shopping-bag' => ['shopping-bag', false],
        'shopping-basket' => ['basket', false],
        'shopping-cart' => ['shopping-cart', false],
        'sliders' => ['adjustments-horizontal', false],
        'smartphone' => ['device-mobile', false],
        'sparkles' => ['sparkles', false],
        'star' => ['star', false],
        'store' => ['building-store', false],
        'tag' => ['tag', false],
        'ticket' => ['ticket', false],
        'timer' => ['stopwatch', false],
        'trash-2' => ['trash', false],
        'truck' => ['truck-delivery', true],
        'user' => ['user', false],
        'users' => ['users', false],
        'utensils' => ['tools-kitchen-2', false],
        'wifi-off' => ['wifi-off', false],
        'x' => ['x', false],
        'zap' => ['bolt', false],
    ];

    /** @return array{0: string, 1: bool}|null */
    public static function resolve(string $name): ?array
    {
        return self::MAP[$name] ?? null;
    }
}
