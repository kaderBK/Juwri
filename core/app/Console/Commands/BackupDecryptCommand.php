<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Juwri\Core\Services\Ops\BackupCrypto;

/**
 * P6-96: manual restore path for an encrypted platform dump. Decrypts (and authenticates the whole file) into a
 * plain .sql.gz; on a wrong key or tampering the output is removed and the command fails. A plain gzip dump is
 * reported as such (nothing to do). Uses BACKUP_ENCRYPTION_KEY + BACKUP_ENCRYPTION_PREVIOUS_KEYS, never prints them.
 */
class BackupDecryptCommand extends Command
{
    protected $signature = 'backup:decrypt {file : encrypted dump (db-*.sql.gz), archive, or a synced media mirror directory} {out : where to write the plain file (or directory)}';

    protected $description = 'Decrypt an encrypted platform DB dump to a plain .sql.gz (for a manual restore)';

    public function handle(): int
    {
        $in = (string) $this->argument('file');
        $out = (string) $this->argument('out');
        if (is_dir($in)) {
            return $this->decryptTree(rtrim($in, '/'), rtrim($out, '/'));
        }
        if (! is_file($in)) {
            $this->error('no such file: '.$in);

            return self::FAILURE;
        }
        if (! BackupCrypto::isEncrypted($in)) {
            $this->warn('not encrypted (plain gzip): restore it directly with gzip -dc');

            return self::SUCCESS;
        }
        if (file_exists($out)) {
            $this->error('refusing to overwrite '.$out);

            return self::FAILURE;
        }
        try {
            BackupCrypto::decryptFile($in, $out);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info('decrypted and authenticated: '.$out);

        return self::SUCCESS;
    }

    /**
     * P6-96b: a media mirror synced back from S3 (`aws s3 sync s3://bucket/backups/platform/media DIR`). Every JWBK
     * file is decrypted + authenticated into the same relative path under $out; plain files (mirrored before the key
     * was set) are copied. Any failure stops with a non-zero exit and names the file.
     */
    private function decryptTree(string $in, string $out): int
    {
        if (file_exists($out) && (glob($out.'/*') ?: []) !== []) {
            $this->error('refusing to write into a non-empty directory '.$out);

            return self::FAILURE;
        }
        $done = 0;
        $copied = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($in, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile()) {
                continue;
            }
            $rel = ltrim(substr($file->getPathname(), strlen($in)), '/');
            $dest = $out.'/'.$rel;
            @mkdir(dirname($dest), 0700, true);
            try {
                if (BackupCrypto::isEncrypted($file->getPathname())) {
                    BackupCrypto::decryptFile($file->getPathname(), $dest);
                    $done++;
                } elseif (! @copy($file->getPathname(), $dest)) {
                    throw new \RuntimeException('cannot copy');
                } else {
                    $copied++;
                }
            } catch (\Throwable $e) {
                $this->error($rel.': '.$e->getMessage());

                return self::FAILURE;
            }
        }
        $this->info("decrypted and authenticated {$done} file(s), copied {$copied} plain file(s) into {$out}");

        return self::SUCCESS;
    }
}
