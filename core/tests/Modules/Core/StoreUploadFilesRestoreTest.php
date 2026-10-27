<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\Tenancy\StoreDataEraser;
use Juwri\Core\Services\Tenancy\StoreDataExporter;
use Juwri\Core\Services\Tenancy\StoreDataImporter;
use Juwri\Core\Services\Tenancy\StoreDataScope;
use Juwri\Core\Services\Tenancy\StoreUploadFiles;
use Tests\Support\TestPlans;
use Tests\TestCase;
use ZipArchive;

class StoreUploadFilesRestoreTest extends TestCase
{
    use RefreshDatabase;

    private string $tmp;

    private string $publicDir;

    /** @var list<string> */
    private array $createdFiles = [];

    /** @var list<string> */
    private array $createdDirs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $token = ParallelTesting::token() ?: 'serial';
        $this->publicDir = sys_get_temp_dir().'/juwri-store-upload-'.$token.'-'.Str::lower(Str::random(12));
        $this->ensureDirectory($this->publicDir.'/storage');
        $this->app->usePublicPath($this->publicDir);

        Storage::fake('local');
        Storage::fake('public');
        $this->tmp = storage_path('framework/testing/store-upload-'.$token.'-'.Str::lower(Str::random(12)));
        $this->ensureDirectory($this->tmp);
    }

    protected function tearDown(): void
    {
        foreach (array_unique($this->createdFiles) as $file) {
            if (is_link($file) || file_exists($file)) {
                @unlink($file);
            }
            $this->removeEmptyParents($file);
        }
        foreach (array_reverse($this->createdDirs) as $dir) {
            @rmdir($dir);
        }
        if (is_dir($this->tmp)) {
            File::deleteDirectory($this->tmp);
        }
        if (isset($this->publicDir)) {
            File::deleteDirectory($this->publicDir);
        }
        parent::tearDown();
    }

    private function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        $parent = dirname($dir);
        if ($parent !== $dir && ! is_dir($parent)) {
            $this->ensureDirectory($parent);
        }
        if (! is_dir($dir) && @mkdir($dir, 0755, true)) {
            $this->createdDirs[] = $dir;
        }
    }

    private function removeEmptyParents(string $file): void
    {
        $root = public_path('storage');
        $dir = dirname($file);
        while ($dir !== $root && str_starts_with($dir, $root.DIRECTORY_SEPARATOR)) {
            if (! @rmdir($dir)) {
                break;
            }
            $dir = dirname($dir);
        }
    }

    private function upload(string $relative, string $content): string
    {
        $absolute = public_path('storage/'.$relative);
        $this->ensureDirectory(dirname($absolute));
        file_put_contents($absolute, $content);
        $this->createdFiles[] = $absolute;

        return $absolute;
    }

    private function owner(string $name): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999], 'status' => 1,
        ]);

        return User::factory()->create(['name' => $name, 'group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(), 'password' => Hash::make('pw-'.$name)]);
    }

    private function store(User $owner, string $slug): Store
    {
        $missing = 'missing-'.Str::lower(Str::random(10)).'.png';

        return Store::create([
            'user_id' => $owner->id, 'title' => 'Store '.$slug, 'slug' => $slug, 'color' => '#fff', 'sub_title' => 's',
            'description' => 'd', 'main_image' => $missing, 'cover_image' => $missing, 'address' => 'a', 'phone' => '1',
        ]);
    }

    /** @return list<string> */
    private function snapshot(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() || $file->isLink()) {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }
        sort($files);

        return $files;
    }

    public function test_restore_target_accepts_every_valid_upload_shape_for_a_restored_store(): void
    {
        $storeId = 7;
        $valid = [
            'uploads/notif-sounds/7/bell.mp3',
            'uploads/fonts/font_7_menu.woff2',
            'uploads/folio-pdf/7-abc.pdf',
            'uploads/folio-pdf-pages/7/p1.png',
            'uploads/pageview/7-menu.pdf',
        ];

        foreach ($valid as $entry) {
            $target = StoreUploadFiles::restoreTarget($entry, [$storeId]);

            $this->assertNotNull($target, $entry);
            $this->assertSame(public_path('storage/'.substr($entry, strlen('uploads/'))), $target, $entry);
            $this->assertStringStartsWith(public_path('storage').DIRECTORY_SEPARATOR, (string) $target, $entry);
        }
    }

    public function test_restore_target_refuses_unknown_foreign_and_unsafe_entries(): void
    {
        $invalid = [
            'another store id' => ['uploads/notif-sounds/8/bell.mp3', [7]],
            'parent traversal' => ['uploads/notif-sounds/7/../8/bell.mp3', [7]],
            'root traversal' => ['uploads/../../.env', [7]],
            'a slash in the name' => ['uploads/notif-sounds/7/a/b.mp3', [7]],
            'a leading dot name' => ['uploads/notif-sounds/7/.htaccess', [7]],
            'a backslash' => ['uploads/notif-sounds/7/a\\b.mp3', [7]],
            'a folder outside the list' => ['uploads/avatars/7/x.png', [7]],
            'a missing uploads prefix' => ['notif-sounds/7/bell.mp3', [7]],
            'store id zero' => ['uploads/notif-sounds/0/bell.mp3', [0]],
            'store id zero against a valid id' => ['uploads/notif-sounds/0/bell.mp3', [7]],
            'an empty id list' => ['uploads/notif-sounds/7/bell.mp3', []],
        ];

        foreach ($invalid as $label => [$entry, $ids]) {
            $this->assertNull(StoreUploadFiles::restoreTarget($entry, $ids), $label);
        }
    }

    public function test_restore_file_writes_a_new_file_and_never_overwrites_an_existing_one(): void
    {
        $storeId = 987201;
        $new = public_path('storage/notif-sounds/'.$storeId.'/new.mp3');
        $this->ensureDirectory(dirname($new));
        $this->createdFiles[] = $new;

        $this->assertTrue(StoreUploadFiles::restoreFile($new, 'new-bytes'));
        $this->assertFileExists($new);
        $this->assertSame('new-bytes', file_get_contents($new));

        $existing = public_path('storage/notif-sounds/'.$storeId.'/existing.mp3');
        $this->ensureDirectory(dirname($existing));
        file_put_contents($existing, 'original-bytes');
        $this->createdFiles[] = $existing;

        $this->assertFalse(StoreUploadFiles::restoreFile($existing, 'replacement-bytes'));
        $this->assertSame('original-bytes', file_get_contents($existing));
    }

    public function test_restore_file_refuses_an_existing_symlink(): void
    {
        if (! function_exists('symlink')) {
            $this->markTestSkipped('symlink() is unavailable');
        }

        $storeId = 987202;
        $targetFile = $this->tmp.'/symlink-target.txt';
        file_put_contents($targetFile, 'target-bytes');

        $link = public_path('storage/notif-sounds/'.$storeId.'/link.mp3');
        $this->ensureDirectory(dirname($link));
        $this->createdFiles[] = $link;

        if (! @symlink($targetFile, $link) || ! is_link($link)) {
            $this->markTestSkipped('symlink() is unavailable');
        }

        $this->assertFalse(StoreUploadFiles::restoreFile($link, 'replacement-bytes'));
        $this->assertSame('target-bytes', file_get_contents($targetFile));
        $this->assertSame('target-bytes', file_get_contents($link));
    }

    public function test_store_bundle_restores_uploaded_sound_and_font(): void
    {
        $owner = $this->owner('uploads');
        $store = $this->store($owner, 'uploads-'.Str::lower(Str::random(5)));
        $storeId = (int) $store->id;
        $accountId = (int) $store->account_id;

        $soundRelative = 'notif-sounds/'.$storeId.'/bell.mp3';
        $fontRelative = 'fonts/font_'.$storeId.'_menu.woff2';
        $sound = $this->upload($soundRelative, 'sound-bytes');
        $font = $this->upload($fontRelative, 'font-bytes');

        $scope = StoreDataScope::store($storeId);
        $zipPath = $this->tmp.'/store.zip';
        $manifest = app(StoreDataExporter::class)->export(DB::connection(), $scope, $zipPath);

        $this->assertArrayHasKey('uploads/'.$soundRelative, $manifest['uploads']);
        $this->assertArrayHasKey('uploads/'.$fontRelative, $manifest['uploads']);

        app(StoreDataEraser::class)->delete(DB::connection(), $scope);
        $this->assertFileDoesNotExist($sound);
        $this->assertFileDoesNotExist($font);
        $this->assertFalse(DB::table('stores')->where('id', $storeId)->exists());

        $report = app(StoreDataImporter::class)->import(DB::connection(), $zipPath, null, 'console', $accountId);

        $this->assertFileExists($sound);
        $this->assertFileExists($font);
        $this->assertSame('sound-bytes', file_get_contents($sound));
        $this->assertSame('font-bytes', file_get_contents($font));
        $this->assertTrue(DB::table('stores')->where('id', $storeId)->exists());
        $this->assertGreaterThanOrEqual(count($manifest['uploads']), $report['files']);
        $this->assertFalse($report['dry_run']);
    }

    public function test_account_bundle_cannot_write_uploads_outside_the_restored_store_folders(): void
    {
        $owner = $this->owner('hostile');
        $store = $this->store($owner, 'hostile-'.Str::lower(Str::random(5)));
        $storeId = (int) $store->id;
        $accountId = (int) $store->account_id;

        $scope = StoreDataScope::account($accountId);
        $zipPath = $this->tmp.'/account.zip';
        app(StoreDataExporter::class)->export(DB::connection(), $scope, $zipPath);
        app(StoreDataEraser::class)->delete(DB::connection(), $scope);
        $this->assertFalse(DB::table('accounts')->where('id', $accountId)->exists());

        $otherStoreId = $storeId + 800000;
        $foreignEntry = 'uploads/notif-sounds/'.$otherStoreId.'/evil.mp3';
        $traversalEntry = 'uploads/notif-sounds/'.$storeId.'/../../evil-outside.mp3';
        $foreignPath = public_path('storage/notif-sounds/'.$otherStoreId.'/evil.mp3');
        $outsidePath = public_path('storage/evil-outside.mp3');
        $this->createdFiles[] = $foreignPath;
        $this->createdFiles[] = $outsidePath;

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath) === true);
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $manifest['uploads'][$foreignEntry] = $otherStoreId;
        $manifest['uploads'][$traversalEntry] = $storeId;
        $zip->addFromString('manifest.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $zip->addFromString($foreignEntry, 'evil-bytes');
        $zip->addFromString($traversalEntry, 'evil-traversal-bytes');
        $zip->close();

        $uploadRoot = public_path('storage/notif-sounds');
        $before = $this->snapshot($uploadRoot);
        $report = app(StoreDataImporter::class)->import(DB::connection(), $zipPath);
        $after = $this->snapshot($uploadRoot);

        $this->assertTrue(DB::table('accounts')->where('id', $accountId)->exists());
        $this->assertTrue(DB::table('stores')->where('id', $storeId)->exists());
        $this->assertSame(0, $report['files']);
        $this->assertSame($before, $after, 'no file was created outside the restored store folders');
        $this->assertFileDoesNotExist($foreignPath);
        $this->assertFileDoesNotExist($outsidePath);
        $this->assertDirectoryDoesNotExist(dirname($foreignPath));
    }
}
