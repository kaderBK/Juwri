<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services\Security;

use App\Support\PlatformSecrets;
use Juwri\Core\Models\PlatformSetting;
use Juwri\Core\Settings\PlatformSettings;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Payments\Services\GatewaySecretVault;

/**
 * Brings stored credentials to "encrypted at rest", idempotently.
 *
 *  - Store secrets (GatewaySecretVault::secretKeys()) still sitting plaintext
 *    in stores.settings move into the encrypted Credential vault and are
 *    forgotten from the settings tree. Empty placeholder values are removed too
 *    (updateStoreOption would drop them on the next save anyway).
 *  - Platform secrets (PlatformSecrets::KEYS) in `platform_settings` are encrypted in
 *    place; values that already decrypt with this APP_KEY are skipped.
 *
 * Re-running changes nothing once everything is encrypted. With $dryRun only
 * counts are returned.
 */
class SecretsAtRest
{
    /**
     * @return array{store_moved:int, store_empty_removed:int, store_orphans_left:int, platform_encrypted:int, platform_already_encrypted:int, orphan_store_options:int}
     */
    public function run(bool $dryRun = false): array
    {
        $stats = [
            'store_moved' => 0,
            'store_empty_removed' => 0,
            'store_orphans_left' => 0,
            'platform_encrypted' => 0,
            'platform_already_encrypted' => 0,
            'orphan_store_options' => 0, // P1-07: settings sit inside the store row, so none can be orphaned
        ];

        $this->storeSecrets($dryRun, $stats);
        $this->platformSecrets($dryRun, $stats);

        return $stats;
    }

    /** @param  array<string,int>  $stats */
    private function storeSecrets(bool $dryRun, array &$stats): void
    {
        // P1-07: store settings live in stores.settings; a secret key still sitting there in plaintext
        // moves into the encrypted vault and is forgotten from the settings tree.
        foreach (GatewaySecretVault::secretKeys() as $name) {
            foreach (StoreSettings::valuesFor($name) as $storeId => $value) {
                if ($value === '') {
                    $stats['store_empty_removed']++;
                    if (! $dryRun) {
                        StoreSettings::forget($storeId, $name);
                    }

                    continue;
                }
                $stats['store_moved']++;
                if (! $dryRun) {
                    // Only vault when the store has no newer vaulted value.
                    if (GatewaySecretVault::get($storeId, $name) === null) {
                        GatewaySecretVault::put($storeId, $name, $value);
                    }
                    StoreSettings::forget($storeId, $name);
                }
            }
        }
    }

    /** @param  array<string,int>  $stats */
    private function platformSecrets(bool $dryRun, array &$stats): void
    {
        $changed = false;
        foreach (PlatformSetting::query()->whereIn('name', PlatformSecrets::KEYS)->get() as $setting) {
            $value = PlatformSettings::decode($setting->value);
            if (! is_string($value) || $value === '') {
                continue;
            }
            if (PlatformSecrets::isEncrypted($value)) {
                $stats['platform_already_encrypted']++;

                continue;
            }
            $stats['platform_encrypted']++;
            if (! $dryRun) {
                PlatformSetting::query()->whereKey($setting->getKey())->update(['value' => json_encode(PlatformSecrets::encrypt($value))]);
                $changed = true;
            }
        }
        if ($changed) {
            PlatformSettings::flush();
        }
    }
}
