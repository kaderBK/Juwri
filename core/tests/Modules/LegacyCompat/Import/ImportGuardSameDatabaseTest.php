<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\LegacyCompat\Import;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Juwri\Modules\LegacyCompat\Import\ImportGuard;
use Tests\TestCase;

/**
 * J52b M4: the importer refuses a source that is the target database, however the two configs spell it.
 *
 * J60: the importer refuses a target whose own host (the host of APP_URL) is a fresh-install host: migrated data
 * never lands on such an install.
 */
class ImportGuardSameDatabaseTest extends TestCase
{
    public function test_host_aliases_and_default_ports_are_the_same_database(): void
    {
        $target = ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3306', 'database' => 'juwri'];

        $this->assertTrue(ImportGuard::sameDatabase(['driver' => 'mysql', 'host' => 'localhost', 'database' => 'juwri'], $target));
        $this->assertTrue(ImportGuard::sameDatabase(['driver' => 'mariadb', 'host' => '::1', 'port' => 3306, 'database' => 'JUWRI'], $target));
        $this->assertFalse(ImportGuard::sameDatabase(['driver' => 'mysql', 'host' => 'localhost', 'database' => 'old_platform'], $target));
        $this->assertFalse(ImportGuard::sameDatabase(['driver' => 'mysql', 'host' => 'db.example.test', 'database' => 'juwri'], $target));
        $this->assertFalse(ImportGuard::sameDatabase(['driver' => 'pgsql', 'host' => '127.0.0.1', 'database' => 'juwri'], $target));
        $this->assertTrue(ImportGuard::sameDatabase(
            ['driver' => 'pgsql', 'host' => 'localhost', 'database' => 'juwri'],
            ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => '5432', 'database' => 'juwri'],
        ));
    }

    public function test_another_table_prefix_in_the_same_database_is_still_the_same_database(): void
    {
        $target = ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3306', 'database' => 'juwri', 'prefix' => ''];

        $this->assertTrue(ImportGuard::sameDatabase(['driver' => 'mysql', 'host' => 'localhost', 'database' => 'juwri', 'prefix' => 'qrtest_'], $target));
        $this->assertFalse(ImportGuard::sameDatabase(['driver' => 'mysql', 'host' => 'localhost', 'database' => 'old_platform', 'prefix' => 'qrtest_'], $target));
    }

    public function test_a_url_config_is_compared_after_it_is_resolved(): void
    {
        config(['database.connections.guard_url_probe' => ['driver' => 'mysql', 'url' => 'mysql://u:p@localhost:3306/juwri', 'database' => 'other']]);

        $resolved = DB::connection('guard_url_probe')->getConfig();

        $this->assertTrue(ImportGuard::sameDatabase($resolved, ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3306', 'database' => 'juwri']));
        DB::purge('guard_url_probe');
    }

    public function test_sqlite_memory_databases_are_separate_and_a_shared_file_is_not(): void
    {
        $this->assertFalse(ImportGuard::sameDatabase(['driver' => 'sqlite', 'database' => ':memory:'], ['driver' => 'sqlite', 'database' => ':memory:']));
        $file = sys_get_temp_dir().'/guard-'.bin2hex(random_bytes(6)).'.sqlite';
        touch($file);
        $this->assertTrue(ImportGuard::sameDatabase(['driver' => 'sqlite', 'database' => $file], ['driver' => 'sqlite', 'database' => dirname($file).'/./'.basename($file)]));
        @unlink($file);
    }

    public function test_a_fresh_install_host_is_refused(): void
    {
        config(['app.url' => 'https://juwri.com']);

        $this->assertNotNull(ImportGuard::forbiddenTargetHostReason());
        $this->assertSame(
            'the target APP_URL host is a fresh-install host that never receives migrated data (J60)',
            ImportGuard::forbiddenTargetHostReason(),
        );
    }

    public function test_a_sub_domain_of_a_fresh_install_host_is_refused(): void
    {
        config(['app.url' => 'https://shop.juwri.com']);

        $this->assertNotNull(ImportGuard::forbiddenTargetHostReason());
    }

    public function test_a_host_that_only_looks_like_a_fresh_install_host_is_allowed(): void
    {
        config(['app.url' => 'https://notjuwri.com']);

        $this->assertNull(ImportGuard::forbiddenTargetHostReason());
    }

    public function test_a_configured_fresh_install_host_and_its_sub_domains_are_refused(): void
    {
        config([
            'app.url' => 'https://install.example.test',
            'legacy_compat.import.forbidden_target_hosts' => ['fresh.example.test'],
        ]);
        $this->assertNull(ImportGuard::forbiddenTargetHostReason());

        config(['app.url' => 'https://fresh.example.test']);
        $this->assertNotNull(ImportGuard::forbiddenTargetHostReason());

        config(['app.url' => 'https://shop.fresh.example.test']);
        $this->assertNotNull(ImportGuard::forbiddenTargetHostReason());
    }

    public function test_the_built_in_host_stays_forbidden_when_the_configured_list_is_emptied(): void
    {
        config([
            'app.url' => 'https://juwri.com',
            'legacy_compat.import.forbidden_target_hosts' => [],
        ]);

        $this->assertNotNull(ImportGuard::forbiddenTargetHostReason());
    }

    public function test_an_allowed_host_passes_the_guard(): void
    {
        config([
            'app.url' => 'https://install.example.test',
            'legacy_compat.enabled' => true,
            'legacy_compat.import.enabled' => true,
            'legacy_compat.cutover.owner_signoff' => true,
            'legacy_compat.import.target_host' => 'install.example.test',
            'legacy_compat.import.source_timezone' => 'UTC',
            'database.connections.guard_host_probe' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
            ],
        ]);

        $this->assertNull(ImportGuard::forbiddenTargetHostReason());
        $this->assertNotContains(
            'the target APP_URL host is a fresh-install host that never receives migrated data (J60)',
            ImportGuard::check(DB::connection(), 'guard_host_probe'),
        );
    }

    public function test_the_guard_refuses_a_fresh_install_host(): void
    {
        config([
            'app.url' => 'https://juwri.com',
            'legacy_compat.enabled' => true,
            'legacy_compat.import.enabled' => true,
            'legacy_compat.cutover.owner_signoff' => true,
            'legacy_compat.import.target_host' => 'localhost',
            'legacy_compat.import.source_timezone' => 'UTC',
            'database.connections.guard_host_probe' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
            ],
        ]);

        $reasons = ImportGuard::check(DB::connection(), 'guard_host_probe');

        $this->assertContains(
            'the target APP_URL host is a fresh-install host that never receives migrated data (J60)',
            $reasons,
        );
    }

    public function test_the_import_command_refuses_a_fresh_install_host(): void
    {
        config([
            'app.url' => 'https://juwri.com',
            'legacy_compat.enabled' => true,
            'legacy_compat.import.enabled' => true,
            'legacy_compat.cutover.owner_signoff' => true,
            'legacy_compat.import.target_host' => 'localhost',
            'legacy_compat.import.source_timezone' => 'UTC',
            'database.connections.guard_host_probe' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
            ],
        ]);

        $code = Artisan::call('legacy:import', ['--source' => 'guard_host_probe']);
        $output = Artisan::output();

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('legacy:import refused:', $output);
        $this->assertStringContainsString(
            'the target APP_URL host is a fresh-install host that never receives migrated data (J60)',
            $output,
        );
    }
}
