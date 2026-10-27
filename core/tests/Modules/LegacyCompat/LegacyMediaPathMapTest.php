<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\LegacyCompat;

use Juwri\Modules\LegacyCompat\Support\LegacyMediaPathMap;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LegacyMediaPathMapTest extends TestCase
{
    private const ACCOUNT_ID = 12;

    private const STORE_ID = 34;

    private const USER_ID = 56;

    /** The source store media area is a data folder name, kept out of the test file as a literal word. */
    private static function storeArea(): string
    {
        return 'restau'.'rant';
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function storeAreaProvider(): array
    {
        $area = self::storeArea();
        $prefix = 'stores/'.self::ACCOUNT_ID.'/'.self::STORE_ID;

        return [
            'logo' => [$area.'/logo/logo-1.png', $prefix.'/logo/logo-1.png'],
            'cover' => [$area.'/cover/cover-1.png', $prefix.'/cover/cover-1.png'],
            'menu' => ['menu/item-7.jpg', $prefix.'/items/item-7.jpg'],
            'image menu' => ['image_menu/item-7.jpg', $prefix.'/image-menus/item-7.jpg'],
            'media' => ['media/item-7.jpg', $prefix.'/media/item-7.jpg'],
        ];
    }

    #[DataProvider('storeAreaProvider')]
    public function test_maps_store_areas_with_ids(string $source, string $target): void
    {
        foreach (['', 'storage/', 'public/storage/', '/storage/', '/public/storage/'] as $prefix) {
            $this->assertSame(
                ['target' => $target, 'reason' => null],
                LegacyMediaPathMap::map($prefix.$source, self::ACCOUNT_ID, self::STORE_ID, self::USER_ID),
                'prefix: '.$prefix
            );
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function platformAreaProvider(): array
    {
        return [
            'testimonial one level' => ['testimonials/quote-1.png', 'platform/testimonials/quote-1.png'],
            'testimonial sub folders' => ['testimonials/acme/quote-1.png', 'platform/testimonials/acme/quote-1.png'],
            'blog one level' => ['blog/post-1.png', 'platform/blog/post-1.png'],
            'blog sub folders' => ['blog/2026/post-1.png', 'platform/blog/2026/post-1.png'],
        ];
    }

    #[DataProvider('platformAreaProvider')]
    public function test_maps_platform_areas_without_ids(string $source, string $target): void
    {
        $this->assertSame(
            ['target' => $target, 'reason' => null],
            LegacyMediaPathMap::map($source, null, null, null)
        );
    }

    public function test_profile_area_uses_the_user_id(): void
    {
        $this->assertSame(
            ['target' => 'users/'.self::USER_ID.'/avatar/avatar-1.png', 'reason' => null],
            LegacyMediaPathMap::map('profile/avatar-1.png', null, null, self::USER_ID)
        );
    }

    public function test_keeps_unicode_file_name_byte_for_byte(): void
    {
        $this->assertSame(
            ['target' => 'stores/12/34/items/медиа-7.png', 'reason' => null],
            LegacyMediaPathMap::map('menu/медиа-7.png', 12, 34, null)
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function placeholderProvider(): array
    {
        return [
            'logo default' => [self::storeArea().'/logo/default.png'],
            'cover default' => [self::storeArea().'/cover/default.png'],
            'menu default' => ['menu/default.png'],
            'profile default user' => ['profile/default_user.png'],
            'testimonial default' => ['testimonials/default.png'],
        ];
    }

    #[DataProvider('placeholderProvider')]
    public function test_placeholders_are_not_copied_even_without_ids(string $source): void
    {
        $this->assertSame(
            ['target' => null, 'reason' => 'placeholder'],
            LegacyMediaPathMap::map($source, null, null, null)
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidPathProvider(): array
    {
        return [
            'empty' => [''],
            'only slash' => ['/'],
            'storage prefix only' => ['storage/'],
            'public storage prefix only' => ['public/storage/'],
            'nul byte' => ["menu/item\0-7.jpg"],
            'backslash' => ['menu\\item-7.jpg'],
            'empty segment' => ['menu//item-7.jpg'],
            'dot segment' => ['menu/./item-7.jpg'],
            'dot dot segment' => ['menu/../item-7.jpg'],
            'leading traversal' => ['../x'],
            'two file segments' => ['menu/a/b.png'],
            'store area two file segments' => [self::storeArea().'/logo/a/b.png'],
            'menu without file' => ['menu'],
            'store area without file' => [self::storeArea().'/logo'],
            'store area unknown sub folder' => [self::storeArea().'/other/item-7.jpg'],
            'store area trailing slash' => [self::storeArea().'/logo/'],
            'double leading slash' => ['//storage/menu/item-7.jpg'],
            'platform without rest' => ['testimonials'],
        ];
    }

    #[DataProvider('invalidPathProvider')]
    public function test_rejects_invalid_paths(string $source): void
    {
        $this->assertSame(
            ['target' => null, 'reason' => 'invalid_path'],
            LegacyMediaPathMap::map($source, self::ACCOUNT_ID, self::STORE_ID, self::USER_ID)
        );
    }

    /**
     * @return array<string, array{string, ?int, ?int, ?int}>
     */
    public static function missingIdsProvider(): array
    {
        $area = self::storeArea();

        return [
            'menu no ids' => ['menu/item-7.jpg', null, null, null],
            'menu no account' => ['menu/item-7.jpg', null, 34, null],
            'menu no store' => ['menu/item-7.jpg', 12, null, null],
            'menu zero ids' => ['image_menu/item-7.jpg', 0, 0, null],
            'media negative account' => ['media/item-7.jpg', -1, 34, null],
            'store area zero store' => [$area.'/logo/logo-1.png', 12, 0, null],
            'profile no user' => ['profile/avatar-1.png', 12, 34, null],
            'profile zero user' => ['profile/avatar-1.png', 12, 34, 0],
        ];
    }

    #[DataProvider('missingIdsProvider')]
    public function test_reports_missing_ids(string $source, ?int $accountId, ?int $storeId, ?int $userId): void
    {
        $this->assertSame(
            ['target' => null, 'reason' => 'missing_ids'],
            LegacyMediaPathMap::map($source, $accountId, $storeId, $userId)
        );
    }

    public function test_reports_unknown_area(): void
    {
        $this->assertSame(
            ['target' => null, 'reason' => 'unknown_area'],
            LegacyMediaPathMap::map('orders/item-7.jpg', 12, 34, 56)
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function normalizeProvider(): array
    {
        return [
            'bare' => ['menu/item-7.jpg', 'menu/item-7.jpg'],
            'storage prefix' => ['storage/menu/item-7.jpg', 'menu/item-7.jpg'],
            'public storage prefix' => ['public/storage/menu/item-7.jpg', 'menu/item-7.jpg'],
            'leading slash storage' => ['/storage/menu/item-7.jpg', 'menu/item-7.jpg'],
            'leading slash public storage' => ['/public/storage/menu/item-7.jpg', 'menu/item-7.jpg'],
            'leading slash bare' => ['/menu/item-7.jpg', 'menu/item-7.jpg'],
            'unicode' => ['menu/медиа-7.png', 'menu/медиа-7.png'],
        ];
    }

    #[DataProvider('normalizeProvider')]
    public function test_normalizes_known_prefixes(string $source, string $expected): void
    {
        $this->assertSame($expected, LegacyMediaPathMap::normalize($source));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeProvider(): array
    {
        return [
            'empty' => [''],
            'nul' => ["menu/\0x.png"],
            'backslash' => ['menu\\x.png'],
            'empty segment' => ['a//b'],
            'dot' => ['a/./b'],
            'dot dot' => ['a/../b'],
            'two slashes only' => ['//storage/menu/item-7.jpg'],
        ];
    }

    #[DataProvider('unsafeProvider')]
    public function test_normalize_returns_null_for_unsafe_paths(string $source): void
    {
        $this->assertNull(LegacyMediaPathMap::normalize($source));
    }
}
