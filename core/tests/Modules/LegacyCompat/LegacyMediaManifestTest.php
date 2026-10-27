<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\LegacyCompat;

use Juwri\Modules\LegacyCompat\Support\LegacyMediaManifest;
use RuntimeException;
use Tests\TestCase;

class LegacyMediaManifestTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/juwri-legacy-media-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function entry(string $source, ?string $target, array $overrides = []): array
    {
        return array_merge([
            'source' => $source,
            'target' => $target,
            'size' => 123,
            'sha256' => str_repeat('a', 64),
            'account_id' => 1,
            'store_id' => 2,
            'user_id' => null,
            'status' => 'planned',
            'note' => null,
        ], $overrides);
    }

    public function test_put_replaces_the_same_source_and_target(): void
    {
        $manifest = new LegacyMediaManifest;
        $manifest->put(self::entry('menu/item-7.jpg', 'stores/1/2/items/item-7.jpg'));
        $manifest->put(self::entry('menu/item-7.jpg', 'stores/1/2/items/item-7.jpg', [
            'status' => 'copied',
            'note' => 'second pass',
        ]));

        $this->assertCount(1, $manifest->entries());
        $entry = $manifest->get('menu/item-7.jpg', 'stores/1/2/items/item-7.jpg');
        $this->assertNotNull($entry);
        $this->assertSame('copied', $entry['status']);
        $this->assertSame('second pass', $entry['note']);
    }

    public function test_entries_are_sorted_by_source_then_target(): void
    {
        $manifest = new LegacyMediaManifest;
        $manifest->put(self::entry('menu/z.png', 'stores/1/2/items/z.png'));
        $manifest->put(self::entry('blog/a.png', 'platform/blog/a.png'));
        $manifest->put(self::entry('menu/z.png', 'stores/1/1/items/z.png'));
        $manifest->put(self::entry('menu/a.png', 'stores/1/2/items/a.png'));

        $this->assertSame([
            'blog/a.png',
            'menu/a.png',
            'menu/z.png',
            'menu/z.png',
        ], array_column($manifest->entries(), 'source'));
        $this->assertSame([
            'platform/blog/a.png',
            'stores/1/2/items/a.png',
            'stores/1/1/items/z.png',
            'stores/1/2/items/z.png',
        ], array_column($manifest->entries(), 'target'));
    }

    public function test_write_and_read_round_trip_and_creates_directory(): void
    {
        $manifest = new LegacyMediaManifest;
        $manifest->put(self::entry('menu/item-7.jpg', 'stores/1/2/items/item-7.jpg'));
        $manifest->put(self::entry('blog/post-1.png', 'platform/blog/post-1.png', ['store_id' => null, 'account_id' => null]));

        $path = $this->directory.'/nested/manifest.json';
        $manifest->write($path);

        $this->assertFileExists($path);
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertSame(1, $decoded['version']);

        $read = LegacyMediaManifest::read($path);
        $this->assertSame($manifest->entries(), $read->entries());
    }

    public function test_atomic_write_leaves_no_tmp_file(): void
    {
        $manifest = new LegacyMediaManifest;
        $manifest->put(self::entry('menu/item-7.jpg', 'stores/1/2/items/item-7.jpg'));

        $path = $this->directory.'/manifest.json';
        $manifest->write($path);
        $manifest->toCsv($this->directory.'/manifest.csv');

        $this->assertSame([], glob($this->directory.'/*.tmp') ?: []);
        $this->assertCount(4, scandir($this->directory) ?: []);
    }

    public function test_read_missing_file_is_an_empty_manifest(): void
    {
        $manifest = LegacyMediaManifest::read($this->directory.'/missing.json');

        $this->assertSame([], $manifest->entries());
    }

    public function test_read_invalid_json_throws(): void
    {
        $path = $this->directory.'/invalid.json';
        mkdir($this->directory, 0777, true);
        file_put_contents($path, '{not json');

        $this->expectException(RuntimeException::class);
        LegacyMediaManifest::read($path);
    }

    public function test_read_wrong_version_throws(): void
    {
        $path = $this->directory.'/wrong-version.json';
        mkdir($this->directory, 0777, true);
        file_put_contents($path, json_encode(['version' => 2, 'entries' => []]));

        $this->expectException(RuntimeException::class);
        LegacyMediaManifest::read($path);
    }

    public function test_to_csv_writes_the_header_and_a_row(): void
    {
        $manifest = new LegacyMediaManifest;
        $manifest->put(self::entry('menu/item-7.jpg', 'stores/1/2/items/item-7.jpg', [
            'size' => 42,
            'sha256' => 'abc123',
            'account_id' => 1,
            'store_id' => 2,
            'user_id' => null,
            'status' => 'copied',
            'note' => 'ok',
        ]));

        $path = $this->directory.'/manifest.csv';
        $manifest->toCsv($path);

        $handle = fopen($path, 'rb');
        $this->assertIsResource($handle);
        $this->assertSame(
            ['source', 'target', 'size', 'sha256', 'account_id', 'store_id', 'user_id', 'status', 'note'],
            fgetcsv($handle, escape: '\\')
        );
        $this->assertSame(
            ['menu/item-7.jpg', 'stores/1/2/items/item-7.jpg', '42', 'abc123', '1', '2', '', 'copied', 'ok'],
            fgetcsv($handle, escape: '\\')
        );
        fclose($handle);
    }

    public function test_to_csv_neutralizes_formula_cells(): void
    {
        $manifest = new LegacyMediaManifest;
        $manifest->put(self::entry('menu/=HYPERLINK("x").jpg', null, ['status' => 'conflict', 'note' => '@SUM(1)']));

        $path = $this->directory.'/manifest.csv';
        $manifest->toCsv($path);

        $handle = fopen($path, 'rb');
        $this->assertIsResource($handle);
        fgetcsv($handle, escape: '\\');
        $row = fgetcsv($handle, escape: '\\');
        fclose($handle);

        $this->assertIsArray($row);
        $this->assertSame('menu/=HYPERLINK("x").jpg', $row[0]);
        $this->assertSame("'@SUM(1)", $row[8]);
    }

    public function test_find_redirect_ignores_conflict_and_missing_and_picks_lowest_store(): void
    {
        $manifest = new LegacyMediaManifest;
        $manifest->put(self::entry('menu/item-7.jpg', 'stores/9/9/items/item-7.jpg', [
            'status' => 'copied',
            'store_id' => 9,
        ]));
        $manifest->put(self::entry('menu/item-7.jpg', 'stores/1/1/items/item-7.jpg', [
            'status' => 'already_present',
            'store_id' => 1,
        ]));
        $manifest->put(self::entry('menu/item-7.jpg', 'stores/0/0/items/item-7.jpg', [
            'status' => 'conflict',
            'store_id' => 0,
        ]));
        $manifest->put(self::entry('menu/item-7.jpg', 'stores/2/2/items/item-7.jpg', [
            'status' => 'missing',
            'store_id' => 2,
        ]));

        $redirect = $manifest->findRedirect('menu/item-7.jpg');

        $this->assertNotNull($redirect);
        $this->assertSame('stores/1/1/items/item-7.jpg', $redirect['target']);
    }

    public function test_find_redirect_puts_null_store_ids_last_then_uses_lowest_target(): void
    {
        $manifest = new LegacyMediaManifest;
        $manifest->put(self::entry('menu/item-7.jpg', 'stores/0/0/items/item-7.jpg', [
            'status' => 'copied',
            'store_id' => null,
        ]));
        $manifest->put(self::entry('menu/item-7.jpg', 'stores/7/z/items/item-7.jpg', [
            'status' => 'copied',
            'store_id' => 7,
        ]));
        $manifest->put(self::entry('menu/item-7.jpg', 'stores/7/a/items/item-7.jpg', [
            'status' => 'already_present',
            'store_id' => 7,
        ]));

        $redirect = $manifest->findRedirect('menu/item-7.jpg');

        $this->assertNotNull($redirect);
        $this->assertSame('stores/7/a/items/item-7.jpg', $redirect['target']);
    }

    public function test_find_redirect_returns_null_for_unknown_source(): void
    {
        $this->assertNull((new LegacyMediaManifest)->findRedirect('menu/item-7.jpg'));
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $directory.'/'.$name;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
