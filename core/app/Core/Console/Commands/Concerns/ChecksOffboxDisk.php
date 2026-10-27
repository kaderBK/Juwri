<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Console\Commands\Concerns;

use Aws\Exception\AwsException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;

/**
 * WI-M7: the single definition of "an off-box (S3) target is configured", shared by
 * platform:db-backup and platform:media-backup so there is never a second copy of
 * the predicate to drift.
 */
trait ChecksOffboxDisk
{
    protected function s3Configured(): bool
    {
        return (bool) config('filesystems.disks.s3.key')
            && (bool) config('filesystems.disks.s3.bucket');
    }

    /**
     * P1-23: write options for every off-box backup object. The security page promises encrypted backups, so each
     * object asks the store for server-side encryption (config backup.offbox_encryption, default AES256). An
     * S3-compatible store that rejects the header can turn it off with BACKUP_S3_SSE= (empty).
     *
     * @return array<string, string>
     */
    protected function offboxWriteOptions(): array
    {
        $sse = trim((string) config('backup.offbox_encryption', 'AES256'));

        return $sse === '' ? [] : ['ServerSideEncryption' => $sse];
    }

    /**
     * P6-166: upload $localPath to $key on the off-box disk and PROVE it landed whole. The `s3` disk is configured
     * with 'throw' => false, so Flysystem swallows a refused PUT (wrong keys, missing bucket, endpoint down, SSE
     * not supported) and put() just returns false; the old callers ignored that and recorded "mirrored". Now a
     * false put() or a stored size that differs from the local file throws, with the store's own reason.
     */
    protected function putOffbox(Filesystem $disk, string $key, string $localPath): void
    {
        clearstatcache(true, $localPath);
        $expected = (int) @filesize($localPath);
        $stream = @fopen($localPath, 'r');
        if ($stream === false) {
            throw new \RuntimeException('cannot read '.basename($localPath).' for the off-box copy');
        }
        try {
            $ok = $disk->put($key, $stream, $this->offboxWriteOptions());
        } catch (\Throwable $e) {
            throw new \RuntimeException('the off-box store refused the upload: '.$this->describeOffboxError($e), 0, $e);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        if ($ok === false) {
            throw new \RuntimeException('the off-box store refused the upload: '.$this->offboxDiagnose($disk));
        }

        try {
            $stored = (int) $disk->size($key);
        } catch (\Throwable $e) {
            throw new \RuntimeException('the uploaded object cannot be read back: '.$this->redactOffbox($e->getMessage()));
        }
        if ($stored !== $expected) {
            throw new \RuntimeException("partial off-box object: {$stored} of {$expected} bytes stored");
        }
    }

    /**
     * Why did the off-box store refuse? A bucket probe surfaces the S3 error code (InvalidAccessKeyId,
     * SignatureDoesNotMatch, NoSuchBucket, a connect error) that 'throw' => false swallowed. Never throws.
     */
    protected function offboxDiagnose(Filesystem $disk): string
    {
        try {
            if ($disk instanceof AwsS3V3Adapter) {
                $disk->getClient()->headBucket(['Bucket' => (string) config('filesystems.disks.s3.bucket')]);

                return 'the bucket answers now, but the upload was refused or cut off (network drop, write permission, or server-side encryption not supported: BACKUP_S3_SSE)';
            }
        } catch (\Throwable $e) {
            return $this->describeOffboxError($e);
        }

        return 'check AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY / AWS_BUCKET / AWS_ENDPOINT';
    }

    /**
     * P6-166: a short, key-free reason from a Flysystem / AWS SDK exception chain. The SDK's own message starts with
     * the full request URL, which used to fill the 190-character mirror_error before the actual reason.
     */
    protected function describeOffboxError(\Throwable $e): string
    {
        for ($x = $e; $x !== null; $x = $x->getPrevious()) {
            if (! $x instanceof AwsException) {
                continue;
            }
            $code = (string) $x->getAwsErrorCode();
            $status = (string) $x->getStatusCode();
            if ($code === '' && $status === '') { // no HTTP answer at all: DNS, refused, reset, cut mid-body
                $why = $x->getPrevious()?->getMessage() ?? $x->getMessage();

                return $this->redactOffbox('store unreachable or connection dropped: '.substr((string) preg_replace('/\s*\(see https?:\S+\)/', '', $why), 0, 120));
            }

            return $this->redactOffbox(match (true) {
                $code === 'NoSuchBucket' || ($status === '404' && $code === '') => 'bucket missing (NoSuchBucket)',
                $status === '403' || in_array($code, ['InvalidAccessKeyId', 'SignatureDoesNotMatch', 'AccessDenied'], true) => 'wrong keys or no access (HTTP '.$status.($code !== '' ? ' '.$code : '').')',
                default => trim($code.' HTTP '.$status.': '.$x->getAwsErrorMessage()),
            });
        }

        return $this->redactOffbox($e->getMessage());
    }

    /** P6-166: the off-box keys never reach a log line, an alert, a platform_backup_runs row or the admin page. */
    protected function redactOffbox(string $message): string
    {
        foreach (['secret', 'key'] as $field) {
            $value = (string) config('filesystems.disks.s3.'.$field);
            if (strlen($value) >= 4) {
                $message = str_replace($value, '[redacted]', $message);
            }
        }

        return $message;
    }

    /**
     * The off-box disk. P6-166: for the real S3 driver, a copy of the `s3` disk with 'throw' => true, so a failed
     * call surfaces the store's reason instead of a bare false (the shared `s3` disk config is left as it is).
     * A faked or non-S3 disk (Storage::fake('s3') in tests) is returned unchanged.
     */
    protected function offboxDisk(): Filesystem
    {
        $disk = Storage::disk('s3');
        if (! $disk instanceof AwsS3V3Adapter) {
            return $disk;
        }

        return Storage::build(array_merge((array) config('filesystems.disks.s3'), ['throw' => true]));
    }
}
