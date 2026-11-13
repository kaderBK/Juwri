<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Import;

use Illuminate\Support\Facades\Crypt;
use Juwri\Core\Settings\StoreSettingKeys;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Core\Support\DeferredGateways;
use Juwri\Modules\LegacyCompat\Http\Middleware\LegacyGatewayNotice;
use Juwri\Modules\Payments\Contracts\StoreGatewayKeys;

/**
 * Plan §1.2 `post_options` row: a store's key/value options become
 *  - typed `stores.settings` (StoreSettings, keys per StoreSettingKeys; J52 renames applied, see RENAMED);
 *  - `store_payment_gateways` rows for `restaurant_<gateway>_<field>` keys: non-secret fields in `config`, secret
 *    fields (StoreGatewayKeys::isSecret, Payments' one list) re-encrypted with THIS install's key in `credentials`;
 *  - the encrypted `gateway_secrets` credential row for the non-gateway secrets (Meta CAPI, print bridge).
 * Source secrets kept in the source's own encrypted credential row are read with the source key (SourceSecrets).
 *
 * Never imported: NEVER (J57 / plan: the room-token switch keeps the Juwri default, token required) and the old
 * licence keys (DROPPED_PREFIXES). Any other unknown name goes to the report (name only, never the value), not into
 * settings. J51a: a gateway stays enabled only when it was installed at the source, is in the configured allow list,
 * is not a deferred (hidden) gateway and ALL its secrets came across; otherwise its `install` flag is imported as off.
 * A value that is encrypted at the source but whose name is not a known secret is never decrypted (reported by name).
 */
final class StoreSettingsImporter
{
    /** Source name => current StoreSettingKeys name (J52 renames). */
    public const RENAMED = [
        'restaurant_template' => 'store_template',
    ];

    /** J65: the dropped legacy templates; each (like an empty template) becomes DEFAULT_TEMPLATE. */
    public const DROPPED_TEMPLATES = [
        'Ice-cream-theme', 'arrow-theme', 'classic-theme', 'dark-theme', 'lala-theme', 'modern-theme', 'night-theme',
        'old-theme', 'old-2-theme', 'orange-theme', 'red-blue-theme', 'show-theme', 'star-theme', 'white-theme',
        'yellow-theme',
    ];

    public const DEFAULT_TEMPLATE = 'crave';

    /** Source names never imported, whatever the value. */
    public const NEVER = [
        'hotel_charge_room_token_required',
    ];

    /** Old licence / vendor keys: dropped (NAMING-AUDIT a6). */
    public const DROPPED_PREFIXES = ['quickorder', 'quickad_', 'quickchat_', 'quickmenu', 'quickvcard', 'purchase_code'];

    /** Feature name of the source's per-store encrypted secrets row. */
    public const SECRET_FEATURE = 'gateway_secrets';

    /**
     * J59e mark on stores: gateways the store had switched on at the source that are off here (not offered at launch,
     * J51a). Detail: the gateway names. The owner is told once ({@see LegacyGatewayNotice}).
     */
    public const GATEWAYS_OFF = 'legacy_gateways_off';

    /**
     * @param  list<string>  $enabledGateways
     */
    public function __construct(
        private readonly ImportContext $ctx,
        private readonly SourceSecrets $secrets,
        private readonly array $enabledGateways,
        private readonly StoreGatewayKeys $keys,
    ) {}

    /**
     * Import one store's options. $options: name => raw value (source rows); $vault: name => value from the source's
     * encrypted secrets row (already decrypted, or null when it could not be read).
     *
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>|null  $vault
     */
    public function import(int $storeId, int $accountId, array $options, ?array $vault, bool $vaultUnreadable): void
    {
        $report = $this->ctx->report;
        if ($vaultUnreadable) {
            $report->conflict('store secrets: source secret row could not be decrypted (no or wrong source key), not imported', 'store#'.$storeId);
        }
        $settings = [];
        $gatewayConfig = [];
        $gatewaySecrets = [];
        $otherSecrets = [];
        /** @var array<string, true> $failed gateways with a secret that could not be imported */
        $failed = [];

        foreach (array_merge($options, $vault ?? []) as $name => $value) {
            $name = (string) $name;
            if ($name === '') {
                continue;
            }
            if (in_array($name, self::NEVER, true) || $this->dropped($name)) {
                $report->droppedSetting($name);

                continue;
            }
            $gateway = $this->keys->parse($name);
            $secret = $this->keys->isSecret($name);
            if (! $secret && SourceSecrets::isEncrypted($value)) {
                // Encrypted at the source under a name Payments does not list as a secret: never decrypted into
                // plain settings or gateway config. Name only in the report; its gateway (if any) stays off.
                $report->conflict('store secrets: encrypted value under a name that is not a known secret, not imported', 'store#'.$storeId.' '.$name);
                if ($gateway !== null) {
                    $failed[$gateway['gateway']] = true;
                }

                continue;
            }
            if ($secret && ($value === null || $value === '')) {
                // An unset optional secret: nothing to import, and not a failure (the gateway keeps its switch).
                continue;
            }
            if ($secret) {
                $plain = $this->secrets->reveal($value);
                if ($plain === null) {
                    $report->conflict('store secrets: encrypted value could not be decrypted, not imported', 'store#'.$storeId.' '.$name);
                    if ($gateway !== null) {
                        $failed[$gateway['gateway']] = true;
                    }

                    continue;
                }
                $value = $plain;
            }
            if ($gateway !== null) {
                if ($secret) {
                    if ($value !== '') {
                        $gatewaySecrets[$gateway['gateway']][$gateway['field']] = (string) $value;
                    }
                } elseif ($value !== null) {
                    $gatewayConfig[$gateway['gateway']][$gateway['field']] = is_scalar($value) ? (string) $value : $value;
                }

                continue;
            }
            if ($secret) {
                if ($value !== '') {
                    $otherSecrets[$name] = (string) $value;
                }

                continue;
            }
            $current = self::RENAMED[$name] ?? $name;
            if (StoreSettingKeys::lookup($current) === null) {
                $report->unmappedSetting($name);

                continue;
            }
            $settings[$current] = $value;
        }

        $this->resolveTemplate($storeId, $settings);

        if ($settings !== []) {
            StoreSettings::setMany($storeId, $settings);
            $report->count('store_settings_written', count($settings));
        }
        // A gateway whose secrets did not all come across must not be live with partial credentials: all of them when
        // the source's secret row could not be read (which gateways it held is unknown).
        $this->writeGateways($storeId, $accountId, $gatewayConfig, $gatewaySecrets, $vaultUnreadable ? null : $failed);
        $this->writeOtherSecrets($storeId, $accountId, $otherSecrets);
    }

    /**
     * J64b/J65: an empty/missing source template or one of the dropped legacy templates is written explicitly as
     * `crave` (not left to the runtime fallback); a remapped slug gets a report row per store.
     *
     * @param  array<string, mixed>  $settings
     */
    private function resolveTemplate(int $storeId, array &$settings): void
    {
        $template = is_scalar($settings['store_template'] ?? null) ? trim((string) $settings['store_template']) : '';
        if ($template === '') {
            $settings['store_template'] = self::DEFAULT_TEMPLATE;
            $this->ctx->report->count('stores_template_set_to_default');

            return;
        }
        foreach (self::DROPPED_TEMPLATES as $dropped) {
            if (strcasecmp($template, $dropped) === 0) {
                $settings['store_template'] = self::DEFAULT_TEMPLATE;
                $this->ctx->report->count('stores_template_remapped_to_default');
                $this->ctx->report->conflict('store template: dropped legacy template remapped to '.self::DEFAULT_TEMPLATE.' (J65)', 'store#'.$storeId.' '.$template);

                return;
            }
        }
    }

    private function dropped(string $name): bool
    {
        foreach (self::DROPPED_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, array<string, mixed>>  $config
     * @param  array<string, array<string, string>>  $secrets
     * @param  array<string, true>|null  $failed  gateways to force off; null = every gateway
     */
    private function writeGateways(int $storeId, int $accountId, array $config, array $secrets, ?array $failed): void
    {
        $now = now()->utc()->format('Y-m-d H:i:s');
        $gateways = array_unique(array_merge(array_keys($config), array_keys($secrets)));
        sort($gateways);
        $off = [];
        foreach ($gateways as $gateway) {
            $fields = $config[$gateway] ?? [];
            $installed = filter_var($fields['install'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $allowed = in_array($gateway, $this->enabledGateways, true) && ! DeferredGateways::isHidden($gateway);
            $complete = $failed !== null && ! isset($failed[$gateway]);
            $enabled = $installed && $allowed && $complete;
            if ($installed && ! $allowed) {
                $fields['install'] = '0';
                $off[] = $gateway;
                $this->ctx->report->conflict('store gateways: installed at the source but not allowed here (J51a), imported switched off', 'store#'.$storeId.' '.$gateway);
            } elseif ($installed && ! $complete) {
                $fields['install'] = '0';
                $this->ctx->report->conflict('store gateways: secrets not imported, imported switched off', 'store#'.$storeId.' '.$gateway);
            }
            $credentials = $secrets[$gateway] ?? [];
            $this->ctx->target->table('store_payment_gateways')->insert([
                'account_id' => $accountId,
                'store_id' => $storeId,
                'gateway' => $gateway,
                'enabled' => $enabled,
                'config' => $fields === [] ? null : json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                // Same format as the model's `encrypted:array` cast, under THIS install's key.
                'credentials' => $credentials === [] ? null : Crypt::encryptString((string) json_encode($credentials)),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->ctx->report->written('store_payment_gateways');
            $this->ctx->report->count('gateway_secrets_reencrypted', count($credentials));
        }
        if ($off !== []) {
            // J59e: keys imported (re-encrypted) but switched off; recorded per store for the owner's notice.
            $this->ctx->mark('stores', $storeId, self::GATEWAYS_OFF, ['gateways' => $off]);
            $this->ctx->flushMarks();
            $this->ctx->report->count('stores_with_gateways_switched_off');
            $this->ctx->report->gatewaysSwitchedOff($storeId, $off);
        }
    }

    /** @param array<string, string> $secrets */
    private function writeOtherSecrets(int $storeId, int $accountId, array $secrets): void
    {
        if ($secrets === []) {
            return;
        }
        $now = now()->utc()->format('Y-m-d H:i:s');
        $this->ctx->target->table('credentials')->insert([
            'account_id' => $accountId,
            'store_id' => $storeId,
            'feature' => self::SECRET_FEATURE,
            'provider' => 'gateway',
            'secrets' => Crypt::encryptString((string) json_encode($secrets)),
            'active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->ctx->report->written('credentials');
        $this->ctx->report->count('store_secrets_reencrypted', count($secrets));
    }
}
