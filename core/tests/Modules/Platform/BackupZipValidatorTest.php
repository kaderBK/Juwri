<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Juwri\Core\Support\BackupZipValidator;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * WI-R1: direct coverage of BackupZipValidator::assertSafe(ZipArchive). Note the
 * traversal/null-byte cases are belt-and-braces — ZipArchive::extractTo() already
 * strips "../" and absolute prefixes; the class exists to stop entry-count /
 * uncompressed-size / ratio bombs, which the admin importer never limited.
 */
class BackupZipValidatorTest extends TestCase
{
    private array $tmp = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Validator messages are ___()-translated; assert the English copy against a
        // pinned locale (the test env defaults to ar).
        app()->setLocale('en');
    }

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function openZip(array $entries): ZipArchive
    {
        $path = tempnam(sys_get_temp_dir(), 'bkz').'.zip';
        $this->tmp[] = $path;
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $name => $body) {
            $zip->addFromString($name, $body);
        }
        $zip->close();

        $open = new ZipArchive;
        $open->open($path);

        return $open;
    }

    public function test_a_normal_archive_passes(): void
    {
        $zip = $this->openZip(['manifest.json' => '{}', 'users/1/data.json' => 'x']);

        BackupZipValidator::assertSafe($zip);
        $zip->close();

        $this->assertTrue(true);
    }

    public function test_too_many_entries_is_rejected(): void
    {
        config(['settings.admin_import_max_entries' => 3]);
        $zip = $this->openZip(['a' => '1', 'b' => '2', 'c' => '3', 'd' => '4']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/too many files/');
        try {
            BackupZipValidator::assertSafe($zip);
        } finally {
            $zip->close();
        }
    }

    public function test_over_the_uncompressed_cap_is_rejected(): void
    {
        config(['settings.admin_import_max_uncompressed_mb' => 1]);
        $zip = $this->openZip(['big' => str_repeat('AB', 1024 * 1024)]); // ~2 MB uncompressed

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/too large when uncompressed/');
        try {
            BackupZipValidator::assertSafe($zip);
        } finally {
            $zip->close();
        }
    }

    public function test_a_zip_bomb_ratio_is_rejected(): void
    {
        // 2 MB of zeros compresses ~1000:1, far over MAX_RATIO (200); under the
        // default 1024 MB uncompressed cap, so it is the RATIO check that fires.
        $zip = $this->openZip(['zeros' => str_repeat("\0", 2 * 1024 * 1024)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/zip bomb/');
        try {
            BackupZipValidator::assertSafe($zip);
        } finally {
            $zip->close();
        }
    }

    public function test_a_traversal_entry_is_rejected_belt_and_braces(): void
    {
        $zip = $this->openZip(['../evil' => 'x']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/illegal path/');
        try {
            BackupZipValidator::assertSafe($zip);
        } finally {
            $zip->close();
        }
    }
}
