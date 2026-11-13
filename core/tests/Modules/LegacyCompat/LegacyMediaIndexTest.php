<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\LegacyCompat;

use Illuminate\Support\Facades\Artisan;
use Juwri\Modules\LegacyCompat\Console\LegacyMediaIndexCommand;
use Juwri\Modules\LegacyCompat\Support\LegacyMediaIndex;
use Juwri\Modules\LegacyCompat\Support\LegacyMediaManifest;
use Tests\TestCase;

/** M6: the compiled, sharded media index (synthetic manifest rows only). */
class LegacyMediaIndexTest extends TestCase
{
    private string $directory;

    private string $manifestPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/juwri-legacy-media-index-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0777, true);
        $this->manifestPath = $this->directory.'/manifest.json';
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);

        parent::tearDown();
    }

    /** @param  list<array{string, string, string}>  $rows source, target, status */
    private function manifest(array $rows): LegacyMediaManifest
    {
        $manifest = new LegacyMediaManifest;
        foreach ($rows as [$source, $target, $status]) {
            $manifest->put(['source' => $source, 'target' => $target, 'size' => 1, 'sha256' => str_repeat('b', 64),
                'account_id' => 1, 'store_id' => 2, 'user_id' => null, 'status' => $status, 'note' => null]);
        }
        $manifest->write($this->manifestPath);

        return $manifest;
    }

    public function test_hit_miss_and_non_redirectable_entries(): void
    {
        LegacyMediaIndex::build($this->manifest([
            ['menu/item-7.jpg', 'stores/1/2/items/item-7.jpg', 'copied'],
            ['menu/item-8.jpg', 'stores/1/2/items/item-8.jpg', 'conflict'],
        ]), $this->manifestPath);

        $this->assertSame('stores/1/2/items/item-7.jpg', LegacyMediaIndex::lookup($this->manifestPath, 'menu/item-7.jpg'));
        $this->assertNull(LegacyMediaIndex::lookup($this->manifestPath, 'menu/item-8.jpg'));
        $this->assertNull(LegacyMediaIndex::lookup($this->manifestPath, 'menu/other.jpg'));
    }

    public function test_no_index_is_a_miss(): void
    {
        $this->manifest([['menu/a.jpg', 'stores/1/2/items/a.jpg', 'copied']]);

        $this->assertNull(LegacyMediaIndex::lookup($this->manifestPath, 'menu/a.jpg'));
    }

    public function test_changed_manifest_is_stale_until_rebuilt(): void
    {
        LegacyMediaIndex::build($this->manifest([['menu/a.jpg', 'stores/1/2/items/a.jpg', 'copied']]), $this->manifestPath);

        $changed = $this->manifest([
            ['menu/a.jpg', 'stores/1/2/items/a.jpg', 'copied'],
            ['menu/b.jpg', 'stores/1/2/items/b.jpg', 'copied'],
        ]);
        $this->assertNull(LegacyMediaIndex::lookup($this->manifestPath, 'menu/a.jpg'));

        LegacyMediaIndex::build($changed, $this->manifestPath);
        $this->assertSame('stores/1/2/items/b.jpg', LegacyMediaIndex::lookup($this->manifestPath, 'menu/b.jpg'));
    }

    public function test_rebuild_removes_stale_shards(): void
    {
        LegacyMediaIndex::build($this->manifest([['menu/a.jpg', 'stores/1/2/items/a.jpg', 'copied']]), $this->manifestPath);
        $dir = LegacyMediaIndex::directory($this->manifestPath);
        $this->assertCount(2, glob($dir.'/*.json'));

        LegacyMediaIndex::build($this->manifest([['menu/b.jpg', 'stores/1/2/items/b.jpg', 'copied']]), $this->manifestPath);
        $this->assertNull(LegacyMediaIndex::lookup($this->manifestPath, 'menu/a.jpg'));
        $this->assertCount(2, glob($dir.'/*.json'));
    }

    public function test_lookup_does_not_parse_the_manifest(): void
    {
        LegacyMediaIndex::build($this->manifest([['menu/a.jpg', 'stores/1/2/items/a.jpg', 'copied']]), $this->manifestPath);

        $mtime = (int) filemtime($this->manifestPath);
        file_put_contents($this->manifestPath, str_repeat('x', (int) filesize($this->manifestPath)));
        touch($this->manifestPath, $mtime);

        $this->assertSame('stores/1/2/items/a.jpg', LegacyMediaIndex::lookup($this->manifestPath, 'menu/a.jpg'));
        $this->assertNull(LegacyMediaIndex::lookup($this->manifestPath, 'menu/zzz.jpg'));
    }

    public function test_index_command_builds_and_fails_when_off_or_without_manifest(): void
    {
        Artisan::registerCommand($this->app->make(LegacyMediaIndexCommand::class));
        $this->manifest([['menu/a.jpg', 'stores/1/2/items/a.jpg', 'copied']]);
        config(['legacy_compat.media.manifest' => null]);

        config(['legacy_compat.enabled' => false]);
        $this->assertSame(1, Artisan::call('legacy:media-index', ['--manifest' => $this->manifestPath]));

        config(['legacy_compat.enabled' => true, 'legacy_compat.cutover.owner_signoff' => true, 'legacy_compat.import.target_host' => 'localhost']);
        $this->assertSame(1, Artisan::call('legacy:media-index'));
        $this->assertSame(0, Artisan::call('legacy:media-index', ['--manifest' => $this->manifestPath]));
        $this->assertStringContainsString('indexed=1', Artisan::output());
        $this->assertSame('stores/1/2/items/a.jpg', LegacyMediaIndex::lookup($this->manifestPath, 'menu/a.jpg'));
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            is_dir($path.'/'.$name) ? $this->removeDirectory($path.'/'.$name) : @unlink($path.'/'.$name);
        }
        @rmdir($path);
    }
}
