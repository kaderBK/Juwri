<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Modules\Storefront\Support\DemoOwner\DemoCredentials;
use Juwri\Modules\Storefront\Support\DemoOwner\DemoOwnerPlan;
use Tests\TestCase;

/**
 * J62-1: the demo credentials — one secret per key per run, written to a file only its owner reads.
 */
class DemoCredentialsTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = storage_path('app/demo-credentials-unit-'.Str::random(12).'.md');
        config(['demo.credentials_path' => $this->path]);
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }

        parent::tearDown();
    }

    public function test_a_password_is_generated_once_per_key_and_never_repeated(): void
    {
        $credentials = new DemoCredentials;

        $password = $credentials->password('owner');

        $this->assertSame($password, $credentials->password('owner'), 'the same key gives the same password');
        $this->assertGreaterThanOrEqual(20, strlen($password), 'a password is at least 20 characters');
        $this->assertNotSame($password, $credentials->password('admin'), 'two keys give two passwords');
        $this->assertNotSame($password, $credentials->password('staff'));
    }

    public function test_a_pin_is_six_digits_and_generated_once(): void
    {
        $credentials = new DemoCredentials;

        $pin = $credentials->pin('cashier');

        $this->assertMatchesRegularExpression('/^\d{6}$/', $pin);
        $this->assertSame($pin, $credentials->pin('cashier'));
    }

    public function test_a_label_is_written_once_and_the_last_row_wins(): void
    {
        $credentials = new DemoCredentials;

        $credentials->record('Owner', 'https://example.test/dashboard', 'owner@example.test', 'first-secret');
        $credentials->record('Owner', 'https://example.test/dashboard', 'owner@example.test', 'second-secret');
        $credentials->record('Staff', 'https://example.test/dashboard', 'staff@example.test', 'staff-secret');

        $rows = $credentials->rows();

        $this->assertCount(2, $rows);
        $this->assertSame('Owner', $rows[0]['label']);
        $this->assertSame('second-secret', $rows[0]['secret']);
        $this->assertSame('staff-secret', $rows[1]['secret']);
    }

    public function test_write_leaves_the_file_readable_by_its_owner_only(): void
    {
        $credentials = new DemoCredentials;
        $credentials->record('Owner', 'https://example.test/dashboard', 'owner@example.test', $credentials->password('owner'));
        $credentials->record('Cashier PIN', 'https://example.test/storefront', '—', $credentials->pin('cashier'), 'pin');

        $path = $credentials->write();

        $this->assertSame($this->path, $path);
        $this->assertFileExists($path);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));

        $contents = (string) file_get_contents($path);
        $this->assertStringContainsString('| Label | URL | Login | Secret |', $contents, 'the file is a markdown table');
        $this->assertStringContainsString('owner@example.test', $contents);
        $this->assertStringContainsString($credentials->password('owner'), $contents);
        $this->assertStringContainsString($credentials->pin('cashier'), $contents);
    }

    public function test_the_file_falls_back_to_the_storage_path(): void
    {
        config(['demo.credentials_path' => null]);

        $credentials = new DemoCredentials;

        $this->assertSame(storage_path(DemoOwnerPlan::CREDENTIALS_FILE), $credentials->path());
    }
}
