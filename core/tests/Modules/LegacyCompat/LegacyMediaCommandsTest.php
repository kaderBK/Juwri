<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\LegacyCompat;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Juwri\Modules\LegacyCompat\Console\LegacyMediaCopy;
use Juwri\Modules\LegacyCompat\Console\LegacyMediaVerify;
use Juwri\Modules\LegacyCompat\Support\LegacyMediaIndex;
use Juwri\Modules\LegacyCompat\Support\LegacyMediaManifest;
use Tests\TestCase;

class LegacyMediaCommandsTest extends TestCase
{
    private FilesystemAdapter $src;

    private FilesystemAdapter $dst;

    private string $directory;

    private string $output = '';

    protected function setUp(): void
    {
        parent::setUp();

        config(['legacy_compat.enabled' => true, 'legacy_compat.cutover.owner_signoff' => true, 'legacy_compat.import.target_host' => 'localhost']);
        config(['legacy_compat.media.from_disk' => null]);
        config(['legacy_compat.media.to_disk' => null]);
        config(['legacy_compat.media.manifest' => null]);

        $this->src = Storage::fake('legacy_src');
        $this->dst = Storage::fake('legacy_dst');
        // The command validates disk names against config; Storage::fake only swaps the resolved disk instance.
        foreach (['legacy_src', 'legacy_dst'] as $disk) {
            config(['filesystems.disks.'.$disk => ['driver' => 'local', 'root' => storage_path('framework/testing/disks/'.$disk)]]);
        }

        Artisan::registerCommand($this->app->make(LegacyMediaCopy::class));
        Artisan::registerCommand($this->app->make(LegacyMediaVerify::class));

        $this->directory = sys_get_temp_dir().'/juwri-legacy-media-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);

        parent::tearDown();
    }

    public function test_copy_happy_path_records_targets_hashes_and_sizes(): void
    {
        $content = 'logo-bytes-'.random_int(1, PHP_INT_MAX);
        $this->src->put('storage/restaurant/logo/logo-1.png', $content);
        $this->src->put('menu/item-7.jpg', 'menu-bytes');
        $this->src->put('profile/pu-u.png', 'avatar-bytes');
        $this->src->put('blog/b.png', 'blog-bytes');

        $code = $this->runCopy([
            ['source' => 'storage/restaurant/logo/logo-1.png', 'account_id' => 11, 'store_id' => 21, 'user_id' => null],
            ['source' => 'menu/item-7.jpg', 'account_id' => 11, 'store_id' => 21, 'user_id' => null],
            ['source' => 'profile/pu-u.png', 'account_id' => null, 'store_id' => null, 'user_id' => 5],
            ['source' => 'blog/b.png', 'account_id' => null, 'store_id' => null, 'user_id' => null],
        ]);

        $this->assertSame(0, $code);
        $this->assertSame('stores/11/21/logo/logo-1.png', LegacyMediaIndex::lookup($this->manifestPath(), 'restaurant/logo/logo-1.png'));

        $this->dst->assertExists('stores/11/21/logo/logo-1.png', $content);
        $this->dst->assertExists('stores/11/21/items/item-7.jpg', 'menu-bytes');
        $this->dst->assertExists('users/5/avatar/pu-u.png', 'avatar-bytes');
        $this->dst->assertExists('platform/blog/b.png', 'blog-bytes');
        $this->src->assertExists('storage/restaurant/logo/logo-1.png', $content);

        $entries = $this->entries();
        $this->assertCount(4, $entries);

        $logo = $this->entryFor('restaurant/logo/logo-1.png', 'stores/11/21/logo/logo-1.png');
        $this->assertSame('copied', $logo['status']);
        $this->assertSame(hash('sha256', $content), $logo['sha256']);
        $this->assertSame(strlen($content), $logo['size']);
        $this->assertSame(11, $logo['account_id']);
        $this->assertSame(21, $logo['store_id']);

        $this->assertSame('copied', $this->entryFor('menu/item-7.jpg', 'stores/11/21/items/item-7.jpg')['status']);
        $this->assertSame(hash('sha256', 'menu-bytes'), $this->entryFor('menu/item-7.jpg', 'stores/11/21/items/item-7.jpg')['sha256']);
        $this->assertSame('copied', $this->entryFor('profile/pu-u.png', 'users/5/avatar/pu-u.png')['status']);
        $this->assertSame('copied', $this->entryFor('blog/b.png', 'platform/blog/b.png')['status']);
    }

    public function test_file_shared_by_two_stores_is_copied_to_both(): void
    {
        $this->src->put('storage/restaurant/logo/share.png', 'shared-bytes');

        $code = $this->runCopy([
            ['source' => 'storage/restaurant/logo/share.png', 'account_id' => 1, 'store_id' => 101, 'user_id' => null],
            ['source' => 'storage/restaurant/logo/share.png', 'account_id' => 1, 'store_id' => 102, 'user_id' => null],
        ]);

        $this->assertSame(0, $code);

        $this->dst->assertExists('stores/1/101/logo/share.png', 'shared-bytes');
        $this->dst->assertExists('stores/1/102/logo/share.png', 'shared-bytes');

        $entries = $this->entries();
        $this->assertCount(2, $entries);
        $this->assertSame('copied', $entries[0]['status']);
        $this->assertSame('copied', $entries[1]['status']);
        $this->assertSame(101, $entries[0]['store_id']);
        $this->assertSame(102, $entries[1]['store_id']);
    }

    public function test_dry_run_writes_no_files_and_no_manifest(): void
    {
        $this->src->put('storage/restaurant/logo/logo-1.png', 'logo-bytes');

        $code = $this->runCopy([
            ['source' => 'storage/restaurant/logo/logo-1.png', 'account_id' => 11, 'store_id' => 21, 'user_id' => null],
        ], ['--dry-run' => true]);

        $this->assertSame(0, $code);
        $this->assertSame([], Storage::disk('legacy_dst')->allFiles());
        $this->assertFileDoesNotExist($this->manifestPath());
        $this->assertStringContainsString('planned=1', $this->output);
        $this->assertStringContainsString('copied=0', $this->output);
    }

    public function test_second_run_is_fully_already_present_and_missing_target_is_refilled(): void
    {
        $this->src->put('storage/restaurant/logo/a.png', 'a-bytes');
        $this->src->put('storage/restaurant/logo/b.png', 'b-bytes');

        $assignments = [
            ['source' => 'storage/restaurant/logo/a.png', 'account_id' => 1, 'store_id' => 10, 'user_id' => null],
            ['source' => 'storage/restaurant/logo/b.png', 'account_id' => 1, 'store_id' => 10, 'user_id' => null],
        ];

        $this->assertSame(0, $this->runCopy($assignments));

        // First resume pass: everything already present, nothing written again.
        $this->assertSame(0, $this->runCopy($assignments));
        $this->assertSame('already_present', $this->entryFor('restaurant/logo/a.png', 'stores/1/10/logo/a.png')['status']);
        $this->assertSame('already_present', $this->entryFor('restaurant/logo/b.png', 'stores/1/10/logo/b.png')['status']);
        // The target file still has the original content.
        $this->dst->assertExists('stores/1/10/logo/a.png', 'a-bytes');

        // A deleted target is copied again by a later run.
        $this->dst->delete('stores/1/10/logo/b.png');
        $this->assertSame(0, $this->runCopy($assignments));
        $this->dst->assertExists('stores/1/10/logo/b.png', 'b-bytes');
        $this->assertSame('copied', $this->entryFor('restaurant/logo/b.png', 'stores/1/10/logo/b.png')['status']);
        $this->assertSame('already_present', $this->entryFor('restaurant/logo/a.png', 'stores/1/10/logo/a.png')['status']);
    }

    public function test_pre_existing_different_target_is_a_conflict_and_is_untouched(): void
    {
        $this->src->put('storage/restaurant/logo/logo-1.png', 'new-bytes');
        $this->dst->put('stores/11/21/logo/logo-1.png', 'different-bytes');

        $code = $this->runCopy([
            ['source' => 'storage/restaurant/logo/logo-1.png', 'account_id' => 11, 'store_id' => 21, 'user_id' => null],
        ]);

        $this->assertSame(1, $code);
        $this->dst->assertExists('stores/11/21/logo/logo-1.png', 'different-bytes');

        $entry = $this->entryFor('restaurant/logo/logo-1.png', 'stores/11/21/logo/logo-1.png');
        $this->assertSame('conflict', $entry['status']);
        $this->assertNull($entry['note']);
        $this->assertSame(hash('sha256', 'new-bytes'), $entry['sha256']);
        $this->assertStringContainsString('conflict=1', $this->output);
    }

    public function test_missing_source_is_reported_and_fails(): void
    {
        $this->src->put('storage/restaurant/logo/present.png', 'present-bytes');

        $code = $this->runCopy([
            ['source' => 'storage/restaurant/logo/present.png', 'account_id' => 11, 'store_id' => 21, 'user_id' => null],
            ['source' => 'storage/restaurant/logo/gone.png', 'account_id' => 11, 'store_id' => 21, 'user_id' => null],
        ]);

        $this->assertSame(1, $code);
        $this->dst->assertExists('stores/11/21/logo/present.png');
        $this->assertSame('missing', $this->entryFor('restaurant/logo/gone.png', 'stores/11/21/logo/gone.png')['status']);
        $this->assertStringContainsString('missing=1', $this->output);
    }

    public function test_placeholder_source_is_unmapped_and_succeeds(): void
    {
        $this->src->put('storage/restaurant/logo/default.png', 'placeholder-bytes');

        $code = $this->runCopy([
            ['source' => 'storage/restaurant/logo/default.png', 'account_id' => 11, 'store_id' => 21, 'user_id' => null],
        ]);

        $this->assertSame(0, $code);
        $this->assertSame([], Storage::disk('legacy_dst')->allFiles());

        $entry = $this->entryFor('storage/restaurant/logo/default.png', null);
        $this->assertSame('unmapped', $entry['status']);
        $this->assertSame('placeholder', $entry['note']);
        $this->assertStringContainsString('unmapped=1', $this->output);
    }

    public function test_traversal_source_is_invalid_and_fails(): void
    {
        $code = $this->runCopy([
            ['source' => '../x', 'account_id' => 11, 'store_id' => 21, 'user_id' => null],
        ]);

        $this->assertSame(1, $code);
        $this->assertSame([], Storage::disk('legacy_dst')->allFiles());

        $entry = $this->entryFor('../x', null);
        $this->assertSame('invalid', $entry['status']);
        $this->assertSame('invalid_path', $entry['note']);
        $this->assertStringContainsString('invalid=1', $this->output);
    }

    public function test_invalid_assignments_json_is_rejected(): void
    {
        $code = Artisan::call('legacy:media-copy', [
            '--from-disk' => 'legacy_src',
            '--to-disk' => 'legacy_dst',
            '--assignments' => $this->writeFile('assignments.json', 'not json at all'),
            '--manifest' => $this->manifestPath(),
        ]);

        $this->assertSame(1, $code);
        $this->assertFileDoesNotExist($this->manifestPath());
        $this->assertSame([], Storage::disk('legacy_dst')->allFiles());
    }

    public function test_same_disk_names_are_rejected(): void
    {
        $this->src->put('storage/restaurant/logo/logo-1.png', 'logo-bytes');

        $code = $this->runCopy([
            ['source' => 'storage/restaurant/logo/logo-1.png', 'account_id' => 11, 'store_id' => 21, 'user_id' => null],
        ], ['--to-disk' => 'legacy_src']);

        $this->assertSame(1, $code);
        $this->assertFileDoesNotExist($this->manifestPath());
        $this->assertSame([], Storage::disk('legacy_dst')->allFiles());
    }

    public function test_unknown_disk_name_is_rejected(): void
    {
        $code = $this->runCopy([
            ['source' => 'storage/restaurant/logo/logo-1.png', 'account_id' => 11, 'store_id' => 21, 'user_id' => null],
        ], ['--from-disk' => 'legacy_src', '--to-disk' => 'not_a_disk']);

        $this->assertSame(1, $code);
        $this->assertFileDoesNotExist($this->manifestPath());
    }

    public function test_module_off_blocks_copy_and_verify(): void
    {
        config(['legacy_compat.enabled' => false]);
        $this->src->put('storage/restaurant/logo/logo-1.png', 'logo-bytes');

        $code = $this->runCopy([
            ['source' => 'storage/restaurant/logo/logo-1.png', 'account_id' => 11, 'store_id' => 21, 'user_id' => null],
        ]);
        $this->assertSame(1, $code);
        $this->assertFileDoesNotExist($this->manifestPath());
        $this->assertSame([], Storage::disk('legacy_dst')->allFiles());

        $code = Artisan::call('legacy:media-verify', ['--to-disk' => 'legacy_dst', '--manifest' => $this->manifestPath()]);
        $this->assertSame(1, $code);
    }

    public function test_verify_passes_on_a_good_manifest(): void
    {
        $this->src->put('storage/restaurant/logo/logo-1.png', 'logo-bytes');
        $this->assertSame(0, $this->runCopy([
            ['source' => 'storage/restaurant/logo/logo-1.png', 'account_id' => 11, 'store_id' => 21, 'user_id' => null],
        ]));

        $code = Artisan::call('legacy:media-verify', [
            '--to-disk' => 'legacy_dst',
            '--manifest' => $this->manifestPath(),
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('verified=1 mismatch=0 missing=0', $output);
    }

    public function test_verify_flags_a_tampered_target(): void
    {
        $this->src->put('storage/restaurant/logo/logo-1.png', 'logo-bytes');
        $this->assertSame(0, $this->runCopy([
            ['source' => 'storage/restaurant/logo/logo-1.png', 'account_id' => 11, 'store_id' => 21, 'user_id' => null],
        ]));

        $this->dst->put('stores/11/21/logo/logo-1.png', 'tampered-bytes');

        $code = Artisan::call('legacy:media-verify', [
            '--to-disk' => 'legacy_dst',
            '--manifest' => $this->manifestPath(),
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('MISMATCH stores/11/21/logo/logo-1.png', $output);
        $this->assertStringContainsString('mismatch=1 missing=0', $output);
    }

    public function test_verify_flags_a_missing_target(): void
    {
        $this->src->put('storage/restaurant/logo/logo-1.png', 'logo-bytes');
        $this->assertSame(0, $this->runCopy([
            ['source' => 'storage/restaurant/logo/logo-1.png', 'account_id' => 11, 'store_id' => 21, 'user_id' => null],
        ]));

        $this->dst->delete('stores/11/21/logo/logo-1.png');

        $code = Artisan::call('legacy:media-verify', [
            '--to-disk' => 'legacy_dst',
            '--manifest' => $this->manifestPath(),
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('MISSING stores/11/21/logo/logo-1.png', $output);
        $this->assertStringContainsString('mismatch=0 missing=1', $output);
    }

    public function test_verify_of_an_empty_manifest_succeeds(): void
    {
        $code = Artisan::call('legacy:media-verify', [
            '--to-disk' => 'legacy_dst',
            '--manifest' => $this->manifestPath(),
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('verified=0 mismatch=0 missing=0', $output);
    }

    public function test_csv_is_written_alongside_the_manifest(): void
    {
        $this->src->put('storage/restaurant/logo/logo-1.png', 'logo-bytes');

        $csvPath = $this->directory.'/manifest.csv';
        $code = $this->runCopy([
            ['source' => 'storage/restaurant/logo/logo-1.png', 'account_id' => 11, 'store_id' => 21, 'user_id' => null],
        ], ['--csv' => $csvPath]);

        $this->assertSame(0, $code);
        $this->assertFileExists($csvPath);
        $csv = (string) file_get_contents($csvPath);
        $this->assertStringContainsString('source,target,size,sha256,account_id,store_id,user_id,status,note', $csv);
        $this->assertStringContainsString('copied', $csv);
    }

    /** Run the copy command with option overrides applied over the test defaults. */
    private function runCopy(array $assignments, array $options = []): int
    {
        $json = json_encode($assignments, JSON_THROW_ON_ERROR);
        $file = $this->writeFile('assignments.json', $json);

        $parameters = array_merge([
            '--from-disk' => 'legacy_src',
            '--to-disk' => 'legacy_dst',
            '--assignments' => $file,
            '--manifest' => $this->manifestPath(),
        ], $options);

        $code = Artisan::call('legacy:media-copy', $parameters);
        $this->output = Artisan::output();

        return $code;
    }

    /** @param  list<array<string, mixed>>  $assignments */
    private function writeFile(string $name, string $content): string
    {
        if (! is_dir($this->directory)) {
            @mkdir($this->directory, 0777, true);
        }

        $path = $this->directory.'/'.$name;
        file_put_contents($path, $content);

        return $path;
    }

    private function manifestPath(): string
    {
        return $this->directory.'/manifest.json';
    }

    /** The manifest entries from this test's run. */
    private function entries(): array
    {
        $this->assertFileExists($this->manifestPath());

        return LegacyMediaManifest::read($this->manifestPath())->entries();
    }

    /** @return array<string, mixed> */
    private function entryFor(string $source, ?string $target): array
    {
        $entry = LegacyMediaManifest::read($this->manifestPath())->get($source, $target);
        $this->assertNotNull($entry, 'No manifest entry for '.$source.' / '.($target ?? 'null'));

        return $entry;
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
            $item = $path.'/'.$name;
            is_dir($item) ? $this->removeDirectory($item) : @unlink($item);
        }

        @rmdir($path);
    }
}
