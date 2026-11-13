<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

use Juwri\Core\Models\User;

/**
 * Owner UI kit helpers (assets/owner-ui, <x-ui.*> components).
 *
 * The kit's accent is the store/reseller brand colour. Brand colours can be
 * anything, so the text colour drawn ON the accent is picked here for contrast
 * instead of hard-coding white.
 */
class OwnerUi
{
    public const INK = '#17171C';

    public const WHITE = '#FFFFFF';

    /**
     * Text colour for content on top of the brand accent. White wins whenever it
     * reaches 4:1 (brand purples/blues read as intended); otherwise the darker
     * ink is used (yellows, limes, light brand colours).
     */
    public static function onAccent(?string $hex): string
    {
        $rgb = self::rgb($hex);
        if ($rgb === null) {
            return self::WHITE;
        }

        $lum = self::luminance($rgb);
        $white = 1.05 / ($lum + 0.05);
        $ink = ($lum + 0.05) / (self::luminance(self::rgb(self::INK) ?? [23, 23, 28]) + 0.05);

        return ($white >= 4.0 || $white >= $ink) ? self::WHITE : self::INK;
    }

    /** The /dashboard/ui-kit reference page: local/testing for anyone signed in, admins elsewhere. */
    public static function kitPageAllowed(?User $user): bool
    {
        if (app()->environment(['local', 'testing'])) {
            return $user !== null;
        }

        return $user !== null && $user->isAdmin();
    }

    /** @return array{0:int,1:int,2:int}|null */
    private static function rgb(?string $hex): ?array
    {
        if (! is_string($hex) || ! preg_match('/^#([0-9A-Fa-f]{6})$/', $hex, $m)) {
            return null;
        }

        return [(int) hexdec(substr($m[1], 0, 2)), (int) hexdec(substr($m[1], 2, 2)), (int) hexdec(substr($m[1], 4, 2))];
    }

    /** @param array{0:int,1:int,2:int} $rgb */
    private static function luminance(array $rgb): float
    {
        $c = array_map(function (int $v): float {
            $s = $v / 255;

            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        }, $rgb);

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }
}
