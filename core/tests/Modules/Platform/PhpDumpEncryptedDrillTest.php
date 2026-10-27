<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\Ops\BackupCrypto;
use Juwri\Modules\Menu\Models\Menu;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * P6-158 review of the P6-96 (BackupCrypto) x P6-119a (PHP dumper) merge, end to end on MySQL/MariaDB: the PHP-only
 * dumper's artifact is encrypted when a key is set, and the PHP-only restore drill loads the decrypted copy. Works only
 * in scratch schemas derived from the test database name; the test database itself is never dumped or dropped.
 */
#[Group('mysql')]
class PhpDumpEncryptedDrillTest extends TestCase
{
    private const SRC = '_p6158_src';

    private string $storage = '';

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'mysql') { // the Group('mysql') guard skips elsewhere; nothing was created
            DB::purge('p6158src');
            DB::purge('restore_check');
            DB::statement('DROP DATABASE IF EXISTS `'.$this->scratchDb(self::SRC).'`');
            DB::statement('DROP DATABASE IF EXISTS `'.$this->scratchDb(self::SRC.'_restore_check').'`');
        }
        if ($this->storage !== '') {
            exec('rm -rf '.escapeshellarg($this->storage));
        }
        parent::tearDown();
    }

    public function test_php_dumper_output_is_encrypted_and_the_php_restore_drill_reads_the_decrypted_copy(): void
    {
        $src = $this->scratchDb(self::SRC);
        DB::statement('DROP DATABASE IF EXISTS `'.$src.'`');
        DB::statement('CREATE DATABASE `'.$src.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        config(['database.connections.p6158src' => array_merge(config('database.connections.mysql'), ['database' => $src, 'prefix' => ''])]);
        DB::purge('p6158src');
        $c = DB::connection('p6158src');
        foreach ([User::class, Store::class, Menu::class, Order::class] as $model) {
            $c->statement('CREATE TABLE `'.(new $model)->getTable().'` (id INT PRIMARY KEY)');
        }
        $c->table((new User)->getTable())->insert(['id' => 1]);

        $this->storage = sys_get_temp_dir().'/p6158-'.uniqid();
        @mkdir($this->storage.'/app', 0775, true);
        $this->app->useStoragePath($this->storage);
        config([
            'backup.dump_engine' => 'php',
            'backup.encryption_key' => BackupCrypto::generateKey(),
            'backup.restore_check_suffix' => '_restore_check',
            'filesystems.disks.s3.key' => null,
        ]);

        $this->artisan('platform:db-backup', ['--connection' => 'p6158src'])->assertExitCode(0);
        $artifacts = glob($this->storage.'/app/backups/platform/db-*.sql.gz') ?: [];
        $this->assertCount(1, $artifacts);
        $this->assertTrue(BackupCrypto::isEncrypted($artifacts[0]), 'the PHP dumper output must be sealed');

        $this->artisan('platform:db-restore-check', ['--connection' => 'p6158src', '--force' => true, '--file' => $artifacts[0]])
            ->expectsOutputToContain('restore drill passed')
            ->assertExitCode(0);
        $this->assertSame([], glob(dirname($artifacts[0]).'/.restore-*') ?: [], 'no plaintext temp left behind');
    }

    private function scratchDb(string $suffix): string
    {
        $live = (string) config('database.connections.mysql.database');
        $this->assertNotSame('', $live);

        return $live.$suffix;
    }
}
