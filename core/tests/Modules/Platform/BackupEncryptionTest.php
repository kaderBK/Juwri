<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Juwri\Core\Console\Commands\PlatformDbBackup;
use Juwri\Core\Models\PlatformBackupRun;
use Juwri\Core\Services\Ops\BackupCrypto;
use RuntimeException;
use Tests\TestCase;

/**
 * P6-96 (dump encryption at rest) + P6-97 (restore drill on by default in production).
 */
class BackupEncryptionTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/juwri-bk-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
        config(['backup.encryption_key' => '', 'backup.encryption_previous_keys' => '']);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function useKey(?string $key = null): string
    {
        $key ??= BackupCrypto::generateKey();
        config(['backup.encryption_key' => $key]);

        return $key;
    }

    /** ~5 MB, crossing many 64 KiB frames and ending mid-frame. */
    private function bigGzip(): string
    {
        $f = $this->dir.'/plain.sql.gz';
        $gz = gzopen($f, 'wb');
        for ($i = 0; $i < 80; $i++) {
            gzwrite($gz, random_bytes(65536 + 7));
        }
        gzwrite($gz, "\n-- Dump completed\n");
        gzclose($gz);

        return $f;
    }

    public function test_round_trip_of_a_large_stream(): void
    {
        $key = BackupCrypto::decodeKey($this->useKey());
        $plain = $this->bigGzip();
        $enc = $this->dir.'/enc';
        $out = $this->dir.'/out.sql.gz';

        BackupCrypto::encryptFile($plain, $enc, (string) $key);
        $this->assertTrue(BackupCrypto::isEncrypted($enc));
        $this->assertSame(BackupCrypto::keyId((string) $key), BackupCrypto::fileKeyId($enc));
        $this->assertSame(0600, fileperms($enc) & 0777);

        BackupCrypto::decryptFile($enc, $out);
        $this->assertSame(hash_file('sha256', $plain), hash_file('sha256', $out));
    }

    public function test_empty_and_exact_frame_sizes_round_trip(): void
    {
        $key = (string) BackupCrypto::decodeKey($this->useKey());
        foreach ([0, 1, 65536, 131072] as $n) {
            $plain = $this->dir.'/p'.$n;
            file_put_contents($plain, $n > 0 ? random_bytes($n) : '');
            BackupCrypto::encryptFile($plain, $plain.'.enc', $key);
            BackupCrypto::decryptFile($plain.'.enc', $plain.'.out');
            $this->assertSame(hash_file('sha256', $plain), hash_file('sha256', $plain.'.out'), "size {$n}");
        }
    }

    public function test_a_tampered_byte_fails_and_leaves_no_plaintext(): void
    {
        $key = (string) BackupCrypto::decodeKey($this->useKey());
        $plain = $this->bigGzip();
        $enc = $this->dir.'/enc';
        BackupCrypto::encryptFile($plain, $enc, $key);

        $bytes = file_get_contents($enc);
        $pos = intdiv(strlen($bytes), 2);
        $bytes[$pos] = chr(ord($bytes[$pos]) ^ 1);
        file_put_contents($enc, $bytes);

        $out = $this->dir.'/out';
        try {
            BackupCrypto::decryptFile($enc, $out);
            $this->fail('tampered dump decrypted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('authentication failed', $e->getMessage());
        }
        $this->assertFileDoesNotExist($out);
    }

    public function test_truncation_and_trailing_data_fail(): void
    {
        $key = (string) BackupCrypto::decodeKey($this->useKey());
        $plain = $this->bigGzip();
        $enc = $this->dir.'/enc';
        BackupCrypto::encryptFile($plain, $enc, $key);
        $bytes = (string) file_get_contents($enc);

        // Cut exactly at a frame boundary: every remaining frame authenticates, only the FINAL tag is missing.
        $off = 13 + 24;
        $boundary = $off;
        while ($boundary < strlen($bytes)) {
            $n = unpack('N', substr($bytes, $boundary, 4))[1];
            if ($boundary + 4 + $n >= strlen($bytes)) {
                break;
            }
            $boundary += 4 + $n;
        }
        file_put_contents($enc, substr($bytes, 0, $boundary));
        $this->expectFailure(fn () => BackupCrypto::verifyFile($enc), 'truncated');

        file_put_contents($enc, $bytes.'x');
        $this->expectFailure(fn () => BackupCrypto::verifyFile($enc), 'trailing data');
    }

    public function test_wrong_key_fails_loudly_without_printing_a_key(): void
    {
        $k1 = $this->useKey();
        $plain = $this->bigGzip();
        $enc = $this->dir.'/enc';
        BackupCrypto::encryptFile($plain, $enc, (string) BackupCrypto::decodeKey($k1));

        $k2 = $this->useKey();
        try {
            BackupCrypto::decryptFile($enc, $this->dir.'/out');
            $this->fail('wrong key decrypted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('wrong key', $e->getMessage());
            $this->assertStringNotContainsString(substr($k1, 7), $e->getMessage());
            $this->assertStringNotContainsString(substr($k2, 7), $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->dir.'/out');

        // Rotation: the old key in BACKUP_ENCRYPTION_PREVIOUS_KEYS restores it.
        config(['backup.encryption_previous_keys' => $k1]);
        BackupCrypto::decryptFile($enc, $this->dir.'/out');
        $this->assertSame(hash_file('sha256', $plain), hash_file('sha256', $this->dir.'/out'));

        // No key at all.
        config(['backup.encryption_key' => '', 'backup.encryption_previous_keys' => '']);
        $this->expectFailure(fn () => BackupCrypto::verifyFile($enc), 'BACKUP_ENCRYPTION_KEY is not set');
    }

    public function test_a_malformed_key_is_rejected(): void
    {
        config(['backup.encryption_key' => 'base64:c2hvcnQ=']);
        $this->expectFailure(fn () => BackupCrypto::currentKey(), 'malformed');
    }

    public function test_backup_seal_without_key_keeps_plain_gzip(): void
    {
        $plain = $this->bigGzip();
        $before = hash_file('sha256', $plain);

        $this->assertNull($this->seal($plain));
        $this->assertSame($before, hash_file('sha256', $plain));
        $this->assertFalse(BackupCrypto::isEncrypted($plain));
    }

    public function test_backup_seal_with_key_encrypts_in_place(): void
    {
        $key = $this->useKey();
        $plain = $this->bigGzip();
        $before = hash_file('sha256', $plain);

        $this->assertSame(BackupCrypto::keyId((string) BackupCrypto::decodeKey($key)), $this->seal($plain));
        $this->assertTrue(BackupCrypto::isEncrypted($plain));
        $this->assertFileDoesNotExist($plain.'.enc');

        $this->artisan('backup:decrypt', ['file' => $plain, 'out' => $this->dir.'/restored.sql.gz'])->assertExitCode(0);
        $this->assertSame($before, hash_file('sha256', $this->dir.'/restored.sql.gz'));
    }

    public function test_backup_seal_with_malformed_key_throws(): void
    {
        config(['backup.encryption_key' => 'nope']);
        $plain = $this->bigGzip();
        $this->expectFailure(fn () => $this->seal($plain), 'malformed');
    }

    public function test_decrypt_command_with_wrong_key_writes_nothing(): void
    {
        $this->useKey();
        $plain = $this->bigGzip();
        $this->seal($plain);
        $this->useKey();

        $this->artisan('backup:decrypt', ['file' => $plain, 'out' => $this->dir.'/x.sql.gz'])->assertExitCode(1);
        $this->assertFileDoesNotExist($this->dir.'/x.sql.gz');
    }

    public function test_generate_key_prints_a_valid_key(): void
    {
        $this->artisan('backup:generate-key')->expectsOutputToContain('BACKUP_ENCRYPTION_KEY=base64:')->assertExitCode(0);
        $this->assertSame(32, strlen((string) BackupCrypto::decodeKey(BackupCrypto::generateKey())));
    }

    public function test_restore_drill_defaults_on_in_production_only(): void
    {
        config(['settings.platform_backup_restore_check_enabled' => null, 'app.env' => 'production']);
        $this->assertTrue(PlatformBackupRun::restoreCheckEnabled());
        config(['app.env' => 'local']);
        $this->assertFalse(PlatformBackupRun::restoreCheckEnabled());

        config(['settings.platform_backup_restore_check_enabled' => '0', 'app.env' => 'production']);
        $this->assertFalse(PlatformBackupRun::restoreCheckEnabled(), 'the operator can switch it off in production');
        config(['settings.platform_backup_restore_check_enabled' => '1', 'app.env' => 'local']);
        $this->assertTrue(PlatformBackupRun::restoreCheckEnabled());
    }

    /** P6-158 review (merge of P6-96 x P6-119a): the native restore loads the DECRYPTED temp copy, then removes it. */
    public function test_restore_check_native_path_loads_the_decrypted_copy_and_cleans_it_up(): void
    {
        $this->useKey();
        $file = $this->dir.'/db-enc.sql.gz';
        file_put_contents($file, gzencode("-- PostgreSQL database dump complete\n"));
        $this->seal($file);
        $this->assertTrue(BackupCrypto::isEncrypted($file));
        config(['database.connections.pgfake' => ['driver' => 'pgsql', 'host' => 'db.internal', 'port' => '5433',
            'database' => 'juwri', 'username' => 'u', 'password' => 'p', 'search_path' => 'public']]);

        $loaded = null;
        Process::fake(function (PendingProcess $process) use (&$loaded) {
            $cmd = (string) $process->command;
            if (str_contains($cmd, 'gzip -dc')) {
                preg_match("/gzip -dc '([^']+)'/", $cmd, $m);
                $loaded = $m[1] ?? '';
                $this->assertFalse(BackupCrypto::isEncrypted($loaded), 'the loader must read the decrypted gzip');
                $this->assertSame("-- PostgreSQL database dump complete\n", gzdecode((string) file_get_contents($loaded)));

                return Process::result(errorOutput: 'stop here', exitCode: 3);
            }

            return Process::result();
        });

        $this->artisan('platform:db-restore-check', ['--connection' => 'pgfake', '--force' => true, '--file' => $file])->assertExitCode(1);

        $this->assertNotNull($loaded);
        $this->assertNotSame($file, $loaded);
        $this->assertFileDoesNotExist($loaded, 'the plaintext temp copy is removed');
        $this->assertSame($file, PlatformBackupRun::latestOf('restore_check')->path, 'the run records the artifact, not the temp copy');
    }

    /** P6-158 review: the PHP-fallback restore records the artifact (not the decrypted temp) and leaves no plaintext. */
    public function test_restore_check_php_path_records_the_artifact_and_leaves_no_plaintext(): void
    {
        $this->useKey();
        $file = $this->dir.'/db-enc.sql.gz';
        file_put_contents($file, gzencode("-- Dump completed\n"));
        $this->seal($file);
        config([
            'backup.dump_engine' => 'php',
            'database.connections.myfake' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '1', 'database' => 'juwri',
                'username' => 'u', 'password' => 'p', 'options' => [\PDO::ATTR_TIMEOUT => 2]],
        ]);

        $this->artisan('platform:db-restore-check', ['--connection' => 'myfake', '--force' => true, '--file' => $file])->assertExitCode(1);

        $run = PlatformBackupRun::latestOf('restore_check');
        $this->assertFalse((bool) $run->ok);
        $this->assertSame($file, $run->path);
        $this->assertSame([], glob($this->dir.'/.restore-*') ?: []);
    }

    private function seal(string $file): ?string
    {
        $m = new \ReflectionMethod(PlatformDbBackup::class, 'seal');

        return $m->invoke(new PlatformDbBackup, $file);
    }

    private function expectFailure(callable $fn, string $needle): void
    {
        try {
            $fn();
            $this->fail('expected a failure containing: '.$needle);
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }
    }
}
