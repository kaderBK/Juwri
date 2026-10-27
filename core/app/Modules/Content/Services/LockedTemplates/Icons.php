<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Services\LockedTemplates;

/**
 * The icon library for `icon` slots (Lucide, ISC licence — a name => inner-SVG map at
 * assets/locked-templates/_runtime/icons/icons.json). A user only ever
 * stores an icon NAME; the SVG markup always comes from this trusted file, so an
 * icon slot can never carry user-authored markup.
 */
class Icons
{
    public const NAME_RE = '/^[a-z0-9][a-z0-9-]{0,59}$/';

    /** @var array<string, string>|null name => inner SVG, memoised per process */
    private static ?array $icons = null;

    private static ?string $loadedFrom = null;

    public static function path(): string
    {
        return public_path('assets/locked-templates/_runtime/icons/icons.json');
    }

    /** @return array<string, string> */
    public static function all(): array
    {
        $path = self::path();
        // Re-read if the file changed (tests / rebuilt library), otherwise memoised.
        $stamp = $path.'@'.(@filemtime($path) ?: 0);
        if (self::$icons === null || self::$loadedFrom !== $stamp) {
            $raw = @file_get_contents($path);
            $data = is_string($raw) ? json_decode($raw, true) : null;
            self::$icons = is_array($data['icons'] ?? null) ? $data['icons'] : [];
            self::$loadedFrom = $stamp;
        }

        return self::$icons;
    }

    public static function has(string $name): bool
    {
        return preg_match(self::NAME_RE, $name) === 1 && isset(self::all()[$name]);
    }

    /**
     * Full <svg> for a library icon, inheriting the replaced icon's classes/size.
     * Stroke uses currentColor, so the icon keeps the template's text colour.
     */
    public static function svg(string $name, string $class = '', string $width = '', string $height = ''): ?string
    {
        if (! self::has($name)) {
            return null;
        }
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $attrs = 'xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
            .' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"';
        if ($class !== '') {
            $attrs .= ' class="'.$e($class).'"';
        }
        if ($width !== '') {
            $attrs .= ' width="'.$e($width).'"';
        }
        if ($height !== '') {
            $attrs .= ' height="'.$e($height).'"';
        }

        return '<svg '.$attrs.'>'.self::all()[$name].'</svg>';
    }
}
