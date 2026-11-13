<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\PlatformSettings;
use Tests\TestCase;
use ZipArchive;

/**
 * WI-R1: the superadmin backup import now bounds the archive (entry count,
 * uncompressed size, ratio) before extraction, so a zip bomb cannot fill the disk
 * that holds the DB dumps/sessions/logs, and the operator gets a truthful error.
 * (Zip-slip was refuted — this is availability hardening, not a traversal fix.)
 */
class AdminBackupImportGuardTest extends TestCase
{
    use RefreshDatabase;

    private array $tmp = [];

    protected function setUp(): void
    {
        parent::setUp();
        // The guard's user-facing messages are ___()-translated; this suite asserts
        // the English copy, so pin the locale (the test env defaults to ar).
        app()->setLocale('en');
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) {
            @unlink($f);
        }
        foreach (glob(storage_path('app/user-backups').'/extract-*') ?: [] as $d) {
            @exec('rm -rf '.escapeshellarg($d));
        }
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['user_type' => 'admin', 'status' => 1, 'email_verified_at' => now()]);
    }

    private function zipUpload(array $entries): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'abk').'.zip';
        $this->tmp[] = $path;
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $name => $body) {
            $zip->addFromString($name, $body);
        }
        $zip->close();

        return new UploadedFile($path, 'backup.zip', 'application/zip', null, true);
    }

    private function setCap(string $key, $value): void
    {
        PlatformSettings::set($key, (string) $value);
        config(['settings.'.$key => $value]);
    }

    private function extractDirs(): array
    {
        return glob(storage_path('app/user-backups').'/extract-*') ?: [];
    }

    public function test_an_archive_over_the_entry_cap_is_rejected_before_extraction(): void
    {
        $this->setCap('admin_import_max_entries', 5);
        $user = $this->admin();
        $zip = $this->zipUpload(['a' => '1', 'b' => '2', 'c' => '3', 'd' => '4', 'e' => '5', 'f' => '6']);

        $this->actingAs($user)->post(route('admin.users.fullImport', $user), ['backup_file' => $zip]);

        $this->assertSame('error', session('app_alert_type'));
        $this->assertStringContainsString('too many files', (string) session('app_alert_message'));
        $this->assertEmpty($this->extractDirs(), 'A rejected upload must leave no extract-* directory behind.');
    }

    public function test_an_archive_over_the_uncompressed_cap_is_rejected(): void
    {
        $this->setCap('admin_import_max_uncompressed_mb', 1);
        $user = $this->admin();
        $zip = $this->zipUpload(['big' => str_repeat('AB', 1024 * 1024)]); // ~2 MB uncompressed

        $this->actingAs($user)->post(route('admin.users.fullImport', $user), ['backup_file' => $zip]);

        $this->assertSame('error', session('app_alert_type'));
        $this->assertStringContainsString('too large when uncompressed', (string) session('app_alert_message'));
        $this->assertEmpty($this->extractDirs());
    }

    public function test_the_bulk_import_path_is_guarded_too(): void
    {
        $this->setCap('admin_import_max_entries', 5);
        $user = $this->admin();
        $zip = $this->zipUpload(['a' => '1', 'b' => '2', 'c' => '3', 'd' => '4', 'e' => '5', 'f' => '6']);

        $this->actingAs($user)->post(route('admin.users.fullImportPackage'), ['backup_file' => $zip]);

        $this->assertSame('error', session('app_alert_type'));
        $this->assertStringContainsString('too many files', (string) session('app_alert_message'));
        $this->assertEmpty($this->extractDirs());
    }

    public function test_the_superadmin_setting_actually_changes_the_cap(): void
    {
        $user = $this->admin();

        // Unset: a 6-entry archive passes the entry gate and reaches the
        // missing-manifest path (default cap is 20000).
        $this->actingAs($user)->post(route('admin.users.fullImport', $user),
            ['backup_file' => $this->zipUpload(['a' => '1', 'b' => '2', 'c' => '3', 'd' => '4', 'e' => '5', 'f' => '6'])]);
        $this->assertStringNotContainsString('too many files', (string) session('app_alert_message'));

        // Set to 5: the same archive is now rejected at the gate — proving the
        // Option leg and the config('settings.*') read leg agree (C1 dead-toggle guard).
        $this->setCap('admin_import_max_entries', 5);
        $this->actingAs($user)->post(route('admin.users.fullImport', $user),
            ['backup_file' => $this->zipUpload(['a' => '1', 'b' => '2', 'c' => '3', 'd' => '4', 'e' => '5', 'f' => '6'])]);
        $this->assertStringContainsString('too many files', (string) session('app_alert_message'));
    }
}
