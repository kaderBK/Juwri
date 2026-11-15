<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\Tenancy;

use App\Models\StoreOption;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Juwri\Core\Contracts\Tenancy\StoreData;
use Juwri\Core\Contracts\Tenancy\StoreTable;
use Juwri\Core\Contracts\Tenancy\StoreTransferContext;
use Juwri\Core\Settings\StoreSettings;

/**
 * P1-21 (J29-8): Core's store- and account-owned tables for the store-data registry.
 *
 *  - stores: the store row. On transfer its owner-connection settings (every key a module calls an owner setting:
 *    gateway keys and ids, tracking ids, bridge tokens) are removed — audit key `options`. A duplicate copies the row
 *    and its other settings (StoreCloneService) without the platform's grants (cleared / set below).
 *  - branches, store_transfers: follow the store (the transfer row too, so the new owner's cooldown sees it).
 *  - users: people, not store data — never touched by a store operation (an account export/delete/restore takes the
 *    owner without password, 2FA or remember token: the owner resets the password after a restore).
 *  - The owner's user-level rows (deletion request, OAuth grants/clients, screen views, personal tokens) go with an
 *    account export/delete; sign-in grants and tokens are never exported.
 */
final class CoreStoreData extends StoreData
{
    public function tables(): array
    {
        return [
            // P1-13 13e: abuse log, audit trail and self-service backups (were registered by Platform).
            StoreTable::platform('abuse_events', 'platform security log (legitimate interest); no business data'),
            StoreTable::owned('account_deletions'),
            // P1-13 13g: account-level entitlement, usage and legacy plan rows (Community tables; were registered by Billing).
            StoreTable::account('account_usage'),
            StoreTable::data('activity_logs'),
            StoreTable::data('branches'),
            StoreTable::data('branch_hour_exceptions'), // P6-288: a branch's holidays / special hours follow the store
            StoreTable::data('cuisine_store'), // P1-13 13d follow-up: the store's cuisines (Community; was Marketplace's)
            StoreTable::account('entitlement_audit_logs'),
            StoreTable::account('entitlement_overrides'),
            StoreTable::account('feature_grants'),
            // P1-23: legal document acceptances (contract evidence): exported with the account, kept when the owner
            // is anonymised, removed only by the hard erasure of the whole account.
            StoreTable::account('legal_acceptances'),
            StoreTable::platform('merchant_campaign_sends', 'platform merchant email campaign send log; never exported or copied'),
            StoreTable::owned('oauth_access_tokens')->notExported(),
            StoreTable::owned('oauth_auth_codes')->notExported(),
            StoreTable::owned('oauth_clients')->notExported(),
            StoreTable::owned('owner_screen_views'),
            StoreTable::owned('personal_access_tokens', 'tokenable_id', fn (Builder $q) => $q->where('tokenable_type', 'like', '%User'))->notExported(),
            StoreTable::owned('sessions')->notExported(), // P6-145: SESSION_DRIVER=database login sessions
            StoreTable::data('store_backup_schedules')->repoint(['user_id']),
            StoreTable::data('store_backups')->repoint(['user_id'])->secret(['password_enc']),
            StoreTable::data('store_transfers'),
            StoreTable::account('tenant_usage_daily'), // P1-13 13g (was Billing's)
            StoreTable::account('upgrades'), // P1-13 13g (was Billing's)
            // P1-17: Laravel Pulse's operations metrics (route templates, SQL without bindings, class names, user ids).
            StoreTable::platform('pulse_aggregates', 'Laravel Pulse operations metrics; instance-level'),
            StoreTable::platform('pulse_entries', 'Laravel Pulse operations metrics; instance-level'),
            StoreTable::platform('pulse_values', 'Laravel Pulse operations metrics; instance-level'),
            StoreTable::catalog('stores')->resetWith(self::resetOwnerSettings(...), 'options')
                // A duplicate does not inherit platform grants: verified badge, custom-code permission, marketplace
                // approval / feature / review (the copy asks for its own review).
                ->clear(['mk_status', 'mk_reviewed_at', 'mk_review_note'])
                ->set(['is_verified' => false, 'custom_code_enabled' => false, 'mk_featured' => false])
                ->media([
                    'main_image' => ['public:storage/store/logo/', 'public:storage/restaurant/'],
                    'cover_image' => ['public:storage/store/cover/', 'public:storage/restaurant/'],
                ]),
            StoreTable::account('users')->secret(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token']),
        ];
    }

    /** @return list<string> the settings removed */
    public static function resetOwnerSettings(ConnectionInterface $db, StoreTransferContext $ctx): array
    {
        $names = array_map('strval', array_keys(StoreSettings::legacyMap($ctx->storeId)));
        $options = array_values(array_filter($names, fn (string $n) => StoreDataRegistry::isOwnerSetting($n)));
        sort($options);
        if ($options !== []) {
            StoreSettings::forgetMany($ctx->storeId, $options);
            StoreOption::flushOptionMemo();
        }

        return $options;
    }
}
