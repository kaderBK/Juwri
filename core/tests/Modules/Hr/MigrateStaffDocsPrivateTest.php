<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hr;

use Juwri\Modules\Hr\Models\StaffDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * storage:migrate-staff-docs — relocates legacy employee documents from the
 * public disk (webroot-served, no auth) into private storage.
 */
class MigrateStaffDocsPrivateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStores(999001); // P1-04 5b: fixture store ids must be real stores
    }

    use RefreshDatabase;

    private string $relPath = 'staff-docs/999001/legacy-contract-test.pdf';

    protected function tearDown(): void
    {
        foreach ([storage_path('app/' . $this->relPath), storage_path('app/public/' . $this->relPath)] as $abs) {
            if (is_file($abs)) {
                @unlink($abs);
            }
        }
        foreach ([storage_path('app/staff-docs/999001'), storage_path('app/public/staff-docs/999001')] as $dir) {
            if (is_dir($dir)) {
                @rmdir($dir);
            }
        }
        parent::tearDown();
    }

    private function makeDoc(string $disk): StaffDocument
    {
        $abs = $disk === 'public'
            ? storage_path('app/public/' . $this->relPath)
            : storage_path('app/' . $this->relPath);
        File::ensureDirectoryExists(dirname($abs));
        file_put_contents($abs, '%PDF-1.4 test');

        return StaffDocument::create([
            'store_id' => 999001,
            'staff_id'      => 999001,
            'kind'          => 'contract',
            'path'          => $this->relPath,
        ]);
    }

    public function test_dry_run_reports_but_does_not_move(): void
    {
        $this->makeDoc('public');

        $this->artisan('storage:migrate-staff-docs')
            ->expectsOutputToContain('Would move: 1')
            ->assertExitCode(0);

        $this->assertFileExists(storage_path('app/public/' . $this->relPath));
        $this->assertFileDoesNotExist(storage_path('app/' . $this->relPath));
    }

    public function test_move_relocates_legacy_file_to_private_disk(): void
    {
        $this->makeDoc('public');

        $this->artisan('storage:migrate-staff-docs', ['--move' => true])
            ->expectsOutputToContain('Moved: 1')
            ->assertExitCode(0);

        $this->assertFileDoesNotExist(storage_path('app/public/' . $this->relPath));
        $this->assertFileExists(storage_path('app/' . $this->relPath));
    }

    public function test_already_private_file_is_left_alone(): void
    {
        $this->makeDoc('local');

        $this->artisan('storage:migrate-staff-docs', ['--move' => true])
            ->expectsOutputToContain('already private: 1')
            ->assertExitCode(0);

        $this->assertFileExists(storage_path('app/' . $this->relPath));
    }
}
