<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\StoreBackup;
use Juwri\Core\Models\User;
use Juwri\Core\Services\Account\AccountExports;
use Juwri\Core\Services\Ops\BackupCrypto;
use Juwri\Modules\Menu\Models\Menu;
use Tests\TestCase;
use ZipArchive;

/**
 * P6-96b: the media mirror, per-store backup archives and account exports are encrypted at rest with the platform
 * backup key (JWBK, {@see BackupCrypto}); downloads/restores decrypt with the current or a previous key; wrong key or
 * tampering fails loudly; no key keeps the old plain behaviour; big files stream in 64 KiB frames.
 */
class MediaAndExportEncryptionTest extends TestCase
{
    use RefreshDatabase;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => (object) [], 'languages' => collect()]);
        $this->tmp = sys_get_temp_dir().'/p696b-'.getmypid().'-'.bin2hex(random_bytes(4));
        mkdir($this->tmp.'/media', 0700, true);
        config([
            'backup.encryption_key' => '', 'backup.encryption_previous_keys' => '',
            'backup.media_root' => $this->tmp.'/media',
            'filesystems.disks.s3.key' => 'k', 'filesystems.disks.s3.bucket' => 'b',
            'filesystems.account_exports_path' => $this->tmp.'/exports',
        ]);
        Storage::fake('s3');
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $f) {
            if ($f !== '.' && $f !== '..') {
                is_dir($dir.'/'.$f) ? $this->rrmdir($dir.'/'.$f) : @unlink($dir.'/'.$f);
            }
        }
        @rmdir($dir);
    }

    private function useKey(?string $key = null): string
    {
        $key ??= BackupCrypto::generateKey();
        config(['backup.encryption_key' => $key]);

        return $key;
    }

    private function media(string $rel, string $bytes): void
    {
        @mkdir(dirname($this->tmp.'/media/'.$rel), 0700, true);
        file_put_contents($this->tmp.'/media/'.$rel, $bytes);
    }

    private function decryptString(string $cipher): string
    {
        file_put_contents($this->tmp.'/c', $cipher);
        @unlink($this->tmp.'/p');
        BackupCrypto::decryptFile($this->tmp.'/c', $this->tmp.'/p');

        return (string) file_get_contents($this->tmp.'/p');
    }

    // ---- sizes -----------------------------------------------------------------------------------------------

    public function test_encrypted_size_is_exact(): void
    {
        $key = (string) BackupCrypto::decodeKey(BackupCrypto::generateKey());
        foreach ([0, 1, 65535, 65536, 65537, 200000] as $n) {
            file_put_contents($this->tmp.'/s', $n > 0 ? random_bytes($n) : '');
            BackupCrypto::encryptFile($this->tmp.'/s', $this->tmp.'/s.enc', $key);
            $this->assertSame(filesize($this->tmp.'/s.enc'), BackupCrypto::encryptedSize($n), "size {$n}");
            @unlink($this->tmp.'/s.enc');
        }
    }

    // ---- media mirror --------------------------------------------------------------------------------------

    public function test_media_mirror_encrypts_round_trips_and_stays_incremental(): void
    {
        $this->useKey();
        $png = random_bytes(70000);
        $this->media('menu/a.png', $png);
        $this->media('logo/b.png', 'bbb');

        $this->artisan('platform:media-backup', ['--force' => true])->assertExitCode(0);
        $obj = (string) Storage::disk('s3')->get('backups/platform/media/menu/a.png');
        $this->assertStringStartsWith('JWBK', $obj);
        $this->assertStringNotContainsString(substr($png, 100, 64), $obj);
        $this->assertSame($png, $this->decryptString($obj));

        $this->artisan('platform:media-backup', ['--force' => true])
            ->expectsOutputToContain('uploaded=0 skipped=2')->assertExitCode(0);
    }

    public function test_plain_mirror_objects_are_re_uploaded_encrypted_once_a_key_is_set(): void
    {
        $this->media('menu/a.png', 'plain-bytes');
        $this->artisan('platform:media-backup', ['--force' => true])->assertExitCode(0);
        $this->assertSame('plain-bytes', Storage::disk('s3')->get('backups/platform/media/menu/a.png'), 'no key: plain as before');

        $this->useKey();
        $this->artisan('platform:media-backup', ['--force' => true])->expectsOutputToContain('uploaded=1 skipped=0')->assertExitCode(0);
        $this->assertSame('plain-bytes', $this->decryptString((string) Storage::disk('s3')->get('backups/platform/media/menu/a.png')));
    }

    public function test_media_mirror_with_a_malformed_key_fails_loudly(): void
    {
        config(['backup.encryption_key' => 'base64:c2hvcnQ=']);
        $this->media('menu/a.png', 'x');
        $this->artisan('platform:media-backup', ['--force' => true])->assertExitCode(1);
        Storage::disk('s3')->assertMissing('backups/platform/media/menu/a.png');
    }

    public function test_synced_mirror_directory_decrypts_with_previous_key_and_fails_with_wrong_key(): void
    {
        $old = $this->useKey();
        $this->media('menu/a.png', 'AAA');
        $this->artisan('platform:media-backup', ['--force' => true])->assertExitCode(0);
        // "aws s3 sync" back: the mirror tree plus one plain object from before the key
        $sync = $this->tmp.'/sync';
        @mkdir($sync.'/menu', 0700, true);
        file_put_contents($sync.'/menu/a.png', (string) Storage::disk('s3')->get('backups/platform/media/menu/a.png'));
        file_put_contents($sync.'/old.png', 'legacy');

        $this->useKey(); // rotated: old key gone → loud failure
        $this->artisan('backup:decrypt', ['file' => $sync, 'out' => $this->tmp.'/w'])->assertExitCode(1);

        config(['backup.encryption_previous_keys' => $old]);
        $this->artisan('backup:decrypt', ['file' => $sync, 'out' => $this->tmp.'/r'])->assertExitCode(0);
        $this->assertSame('AAA', file_get_contents($this->tmp.'/r/menu/a.png'));
        $this->assertSame('legacy', file_get_contents($this->tmp.'/r/old.png'));
    }

    public function test_large_media_file_streams_without_reading_it_whole(): void
    {
        $this->useKey();
        $f = $this->tmp.'/media/big.bin';
        $h = fopen($f, 'wb');
        for ($i = 0; $i < 24; $i++) {
            fwrite($h, random_bytes(1 << 20));
        }
        fclose($h);
        $sha = hash_file('sha256', $f);

        gc_collect_cycles();
        $before = memory_get_usage();
        memory_reset_peak_usage();
        $this->artisan('platform:media-backup', ['--force' => true])->assertExitCode(0);
        $this->assertLessThan(8 << 20, memory_get_peak_usage() - $before, '24 MB file must not be read whole');

        $enc = Storage::disk('s3')->path('backups/platform/media/big.bin');
        $this->assertSame(BackupCrypto::encryptedSize(24 << 20), filesize($enc));
        BackupCrypto::decryptFile($enc, $this->tmp.'/big.out');
        $this->assertSame($sha, hash_file('sha256', $this->tmp.'/big.out'));
    }

    public function test_encrypt_reads_the_source_in_64k_chunks(): void
    {
        ChunkCountingStream::$maxRead = 0;
        ChunkCountingStream::$size = 5 * 1048576 + 3;
        stream_wrapper_register('p696bfake', ChunkCountingStream::class);
        try {
            $key = (string) BackupCrypto::decodeKey(BackupCrypto::generateKey());
            BackupCrypto::encryptFile('p696bfake://src', $this->tmp.'/fake.enc', $key);
        } finally {
            stream_wrapper_unregister('p696bfake');
        }
        $this->assertGreaterThan(0, ChunkCountingStream::$maxRead);
        $this->assertLessThanOrEqual(65536, ChunkCountingStream::$maxRead);
        $this->assertSame(BackupCrypto::encryptedSize(ChunkCountingStream::$size), filesize($this->tmp.'/fake.enc'));
    }

    // ---- per-store backups ----------------------------------------------------------------------------------

    /** @return array{0: User, 1: StoreBackup} */
    private function storeBackup(): array
    {
        $owner = User::factory()->create(['status' => 1]);
        $r = Store::create([
            'user_id' => $owner->id, 'title' => 'Enc Barn', 'slug' => 'enc-'.Str::lower(Str::random(6)), 'color' => '#fff',
            'sub_title' => 's', 'description' => 'd', 'main_image' => 'x.png', 'cover_image' => 'x.png', 'address' => 'a', 'phone' => '1',
        ]);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => '1']);
        Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Cheeseburger', 'price' => 20, 'active' => '1']);
        $this->actingAs($owner)->post(route('backup.store'), ['include_pii' => 0])->assertRedirect();

        return [$owner, StoreBackup::where('store_id', $r->id)->firstOrFail()];
    }

    private function zipEntry(string $path, string $entry): ?string
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'download must be a valid ZIP');
        $c = $zip->getFromName($entry);
        $zip->close();

        return $c === false ? null : $c;
    }

    public function test_store_backup_is_encrypted_at_rest_and_download_decrypts(): void
    {
        $this->useKey();
        [$owner, $b] = $this->storeBackup();
        $this->assertSame('ready', $b->status);
        $abs = storage_path('app/'.$b->path);
        $this->assertTrue(BackupCrypto::isEncrypted($abs));
        $this->assertFileDoesNotExist($abs.'.jwbk-tmp');

        $res = $this->actingAs($owner)->get(route('backup.download', $b->token))->assertOk();
        $this->assertSame('application/zip', $res->headers->get('content-type'));
        $plain = $res->getFile()->getPathname();
        $this->assertNotSame($abs, $plain);
        $this->assertStringContainsString('Cheeseburger', (string) $this->zipEntry($plain, 'menu_items.json'));
        @unlink($plain);
        @unlink($abs);
    }

    public function test_store_backup_downloads_with_a_previous_key_and_fails_loudly_with_wrong_key_or_tamper(): void
    {
        $old = $this->useKey();
        [$owner, $b] = $this->storeBackup();
        $abs = storage_path('app/'.$b->path);

        $this->useKey();
        $this->actingAs($owner)->get(route('backup.download', $b->token))->assertStatus(500);
        $this->assertSame([], glob(storage_path('app/tmp/backup-downloads/*')) ?: [], 'no partial plaintext left');

        config(['backup.encryption_previous_keys' => $old]);
        $res = $this->actingAs($owner)->get(route('backup.download', $b->token))->assertOk();
        $this->assertNotNull($this->zipEntry($res->getFile()->getPathname(), 'manifest.json'));
        @unlink($res->getFile()->getPathname());

        $bytes = (string) file_get_contents($abs);
        $bytes[200] = chr(ord($bytes[200]) ^ 1);
        file_put_contents($abs, $bytes);
        $this->actingAs($owner)->get(route('backup.download', $b->token))->assertStatus(500);
        @unlink($abs);
    }

    public function test_store_backup_without_key_stays_plain_and_still_downloads_after_a_key_is_set(): void
    {
        [$owner, $b] = $this->storeBackup();
        $abs = storage_path('app/'.$b->path);
        $this->assertFalse(BackupCrypto::isEncrypted($abs));

        $this->useKey();
        $res = $this->actingAs($owner)->get(route('backup.download', $b->token))->assertOk();
        $this->assertSame($abs, $res->getFile()->getPathname(), 'plain archive is sent as it is');
        @unlink($abs);
    }

    // ---- account exports ------------------------------------------------------------------------------------

    public function test_account_export_is_encrypted_at_rest_and_download_decrypts(): void
    {
        $this->useKey();
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now(), 'email' => Str::lower(Str::random(8)).'@enc.test', 'password' => Hash::make('pw')]);
        Store::create(['user_id' => $user->id, 'slug' => 'ae-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'Store',
            'sub_title' => 't', 'description' => 'd', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1', 'phone' => '1']);
        $user = $user->fresh();

        $this->actingAs($user)->post(route('backup.account.export'))->assertRedirect();
        $exports = app(AccountExports::class);
        $latest = $exports->latest((int) $user->account_id);
        $this->assertSame('ready', $latest['status'] ?? null);
        $stored = (string) $exports->file((int) $user->account_id, $latest['token']);
        $this->assertTrue(BackupCrypto::isEncrypted($stored));

        $res = $this->actingAs($user)->get(route('backup.account.download', $latest['token']))->assertOk();
        $manifest = json_decode((string) $this->zipEntry($res->getFile()->getPathname(), 'manifest.json'), true);
        $this->assertSame('account', $manifest['scope']['type']);
        @unlink($res->getFile()->getPathname());

        $this->useKey(); // key lost → loud failure, never the ciphertext as a "zip"
        $this->actingAs($user)->get(route('backup.account.download', $latest['token']))->assertStatus(500);
    }
}

/** A read-only stream of $size zero bytes that records the largest single read request. */
class ChunkCountingStream
{
    public static int $size = 0;

    public static int $maxRead = 0;

    /** @var resource|null */
    public $context;

    private int $pos = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        $this->pos = 0;

        return true;
    }

    public function stream_read(int $count): string
    {
        self::$maxRead = max(self::$maxRead, $count);
        $n = max(0, min($count, self::$size - $this->pos));
        $this->pos += $n;

        return str_repeat("\0", $n);
    }

    public function stream_eof(): bool
    {
        return $this->pos >= self::$size;
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return ['size' => self::$size];
    }
}
