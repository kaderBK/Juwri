<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\GoogleOrdering\Sftp;

/** The real drop (phpseclib 4). Only reached when the integration is `real` and the SFTP target is configured. */
final class PhpseclibGoogleOrderingTransport implements GoogleOrderingSftpTransport
{
    private ?\phpseclib4\Net\SFTP $sftp = null;

    public function connect(array $cfg): void
    {
        if (! class_exists(\phpseclib4\Net\SFTP::class)) {
            throw new \RuntimeException('phpseclib is not installed');
        }
        $sftp = new \phpseclib4\Net\SFTP($cfg['host'], $cfg['port']);
        $key = \phpseclib4\Crypt\PublicKeyLoader::load(is_file($cfg['key']) ? (string) file_get_contents($cfg['key']) : $cfg['key']);
        if (! $sftp->login($cfg['user'], $key)) {
            throw new \RuntimeException('SFTP login failed');
        }
        $this->sftp = $sftp;
    }

    public function put(string $remotePath, string $localFile): void
    {
        if ($this->sftp === null) {
            throw new \RuntimeException('SFTP is not connected');
        }
        // phpseclib 4 throws on a failed put (the caller retries on any Throwable).
        $this->sftp->put($remotePath, $localFile, \phpseclib4\Net\SFTP::SOURCE_LOCAL_FILE);
    }

    public function close(): void
    {
        $this->sftp = null;
    }
}
