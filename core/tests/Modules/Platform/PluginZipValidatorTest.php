<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Juwri\Core\Support\PluginZipValidator;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class PluginZipValidatorTest extends TestCase
{
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function makeZip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'plz').'.zip';
        $this->tmp[] = $path;
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $name => $body) {
            $zip->addFromString($name, $body);
        }
        $zip->close();

        return $path;
    }

    public function test_safe_plugin_id_accepts_slugs_and_rejects_paths(): void
    {
        $this->assertTrue(PluginZipValidator::isSafePluginId('quickorder'));
        $this->assertTrue(PluginZipValidator::isSafePluginId('my-plugin_2'));
        $this->assertFalse(PluginZipValidator::isSafePluginId('../evil'));
        $this->assertFalse(PluginZipValidator::isSafePluginId('a/b'));
        $this->assertFalse(PluginZipValidator::isSafePluginId('..'));
        $this->assertFalse(PluginZipValidator::isSafePluginId(''));
        $this->assertFalse(PluginZipValidator::isSafePluginId(123));
    }

    public function test_clean_plugin_zip_passes(): void
    {
        $path = $this->makeZip([
            'plugin.php' => "<?php return ['id' => 'demo', 'min_app_version' => '1.0'];",
            'assets/style.css' => 'body{}',
        ]);

        PluginZipValidator::assertSafe($path);
        $this->assertTrue(true); // reached = no exception
    }

    public function test_traversal_entry_is_rejected(): void
    {
        $path = $this->makeZip([
            'plugin.php' => "<?php return ['id' => 'demo'];",
            '../../evil.php' => '<?php system($_GET[0]);',
        ]);

        $this->expectException(RuntimeException::class);
        PluginZipValidator::assertSafe($path);
    }

    public function test_missing_manifest_is_rejected(): void
    {
        $path = $this->makeZip(['readme.txt' => 'hi']);

        $this->expectException(RuntimeException::class);
        PluginZipValidator::assertSafe($path);
    }
}
