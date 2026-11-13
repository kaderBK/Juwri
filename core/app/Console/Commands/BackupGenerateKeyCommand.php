<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Juwri\Core\Services\Ops\BackupCrypto;

/**
 * P6-96: print a fresh BACKUP_ENCRYPTION_KEY (32 random bytes, base64). Never writes .env and never reads or prints
 * the configured key. Equivalent: php -r 'echo "base64:".base64_encode(random_bytes(32)), PHP_EOL;'
 */
class BackupGenerateKeyCommand extends Command
{
    protected $signature = 'backup:generate-key';

    protected $description = 'Print a new BACKUP_ENCRYPTION_KEY for encrypting platform DB dumps';

    public function handle(): int
    {
        $key = BackupCrypto::generateKey();
        $this->line('BACKUP_ENCRYPTION_KEY='.$key);
        $this->line('key id: '.BackupCrypto::keyId((string) BackupCrypto::decodeKey($key)));
        $this->warn('Store this key OFF the server (password manager). Without it, encrypted dumps cannot be restored.');
        $this->warn('Rotating: move the old key to BACKUP_ENCRYPTION_PREVIOUS_KEYS until every dump made with it has aged out.');

        return self::SUCCESS;
    }
}
