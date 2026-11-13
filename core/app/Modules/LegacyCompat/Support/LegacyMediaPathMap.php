<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Support;

/**
 * M6: maps a legacy media path (as stored by the source product, with or without the public disk prefix) to the
 * target tenant path in this install. Pure functions: no disk access, no config, no database.
 *
 * The target is a path relative to the target disk. The source file name is kept byte for byte; only the directory
 * shape changes. Unknown first segments are reported as unknown_area, never guessed.
 */
final class LegacyMediaPathMap
{
    /** Basenames that mean "no image": the source used them instead of a real file, so they are never copied. */
    public const PLACEHOLDER_NAMES = ['default.png', 'default_user.png'];

    /** The source product's store media area (a data folder name, not a brand). */
    private const STORE_AREA = 'restaurant';

    /**
     * Clean a source path to a relative one.
     *
     * One leading slash is dropped, then one leading `public/storage/` or `storage/` prefix. Null means the path is
     * unsafe or malformed: empty, NUL, backslash, an empty segment, or a `.`/`..` segment.
     */
    public static function normalize(string $source): ?string
    {
        if (str_starts_with($source, '/')) {
            $source = substr($source, 1);
        }

        if (str_starts_with($source, 'public/storage/')) {
            $source = substr($source, strlen('public/storage/'));
        } elseif (str_starts_with($source, 'storage/')) {
            $source = substr($source, strlen('storage/'));
        }

        if ($source === '' || str_contains($source, "\0") || str_contains($source, '\\')) {
            return null;
        }

        $segments = explode('/', $source);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        return $source;
    }

    /**
     * Map one source path to a target path or a reason it cannot be mapped.
     *
     * @return array{target: ?string, reason: ?string}
     */
    public static function map(string $source, ?int $accountId, ?int $storeId, ?int $userId): array
    {
        $path = self::normalize($source);
        if ($path === null) {
            return self::reason('invalid_path');
        }

        if (in_array(basename($path), self::PLACEHOLDER_NAMES, true)) {
            return self::reason('placeholder');
        }

        $segments = explode('/', $path);
        $area = $segments[0];

        if ($area === self::STORE_AREA) {
            return self::mapStoreArea($segments, $accountId, $storeId);
        }

        if ($area === 'profile') {
            if (count($segments) !== 2) {
                return self::reason('invalid_path');
            }
            if (! self::positive($userId)) {
                return self::reason('missing_ids');
            }

            return self::target('users/'.$userId.'/avatar/'.$segments[1]);
        }

        if ($area === 'menu' || $area === 'image_menu' || $area === 'media') {
            if (count($segments) !== 2) {
                return self::reason('invalid_path');
            }
            if (! self::positive($accountId) || ! self::positive($storeId)) {
                return self::reason('missing_ids');
            }

            $folder = match ($area) {
                'menu' => 'items',
                'image_menu' => 'image-menus',
                default => 'media',
            };

            return self::target('stores/'.$accountId.'/'.$storeId.'/'.$folder.'/'.$segments[1]);
        }

        if ($area === 'testimonials' || $area === 'blog') {
            $rest = implode('/', array_slice($segments, 1));
            if ($rest === '') {
                return self::reason('invalid_path');
            }

            return self::target('platform/'.$area.'/'.$rest);
        }

        return self::reason('unknown_area');
    }

    /**
     * @param  list<string>  $segments
     * @return array{target: ?string, reason: ?string}
     */
    private static function mapStoreArea(array $segments, ?int $accountId, ?int $storeId): array
    {
        if (count($segments) !== 3 || ! in_array($segments[1], ['logo', 'cover'], true)) {
            return self::reason('invalid_path');
        }

        if (! self::positive($accountId) || ! self::positive($storeId)) {
            return self::reason('missing_ids');
        }

        return self::target('stores/'.$accountId.'/'.$storeId.'/'.$segments[1].'/'.$segments[2]);
    }

    private static function positive(?int $id): bool
    {
        return $id !== null && $id > 0;
    }

    /** @return array{target: string, reason: null} */
    private static function target(string $target): array
    {
        return ['target' => $target, 'reason' => null];
    }

    /** @return array{target: null, reason: string} */
    private static function reason(string $reason): array
    {
        return ['target' => null, 'reason' => $reason];
    }
}
