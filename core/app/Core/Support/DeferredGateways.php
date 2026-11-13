<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

/**
 * J51a: gateways deferred past launch (Tap, Moyasar). Hidden everywhere while
 * `payments.show_deferred_gateways` is off; exposed only once it is switched on.
 */
final class DeferredGateways
{
    /**
     * The values filterOptions() treats as "on" for a store gateway switch.
     *
     * @var list<int|string|bool>
     */
    private const TRUTHY = [1, '1', true, 'on', 'yes'];

    /**
     * The gateway folders hidden unless the deferred-gateways flag is on.
     *
     * @return list<string>
     */
    public static function hidden(): array
    {
        if (config('payments.show_deferred_gateways')) {
            return [];
        }

        return array_values(array_unique((array) config('payments.deferred_gateways', [])));
    }

    public static function isHidden(string $key): bool
    {
        return in_array($key, self::hidden(), true);
    }

    /**
     * Remove hidden gateways from a map keyed by gateway folder.
     *
     * @param  array<string, mixed>  $byKey
     * @return array<string, mixed>
     */
    public static function visible(array $byKey): array
    {
        return array_diff_key($byKey, array_flip(self::hidden()));
    }

    /**
     * Force "off" any hidden store gateway switch (`restaurant_<folder>_install`)
     * a posted option map still carries as on. Everything else is untouched.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public static function filterOptions(array $options): array
    {
        foreach (self::hidden() as $folder) {
            $key = 'restaurant_'.$folder.'_install';
            if (array_key_exists($key, $options) && in_array($options[$key], self::TRUTHY, true)) {
                $options[$key] = '0';
            }
        }

        return $options;
    }
}
