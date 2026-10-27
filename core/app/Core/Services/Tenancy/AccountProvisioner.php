<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\Tenancy;

use Juwri\Core\Models\User;
use InvalidArgumentException;
use Juwri\Core\Models\Account;

/**
 * P1-04: one place that creates the tenant account for a store owner. Called when a store is created for (or
 * transferred to) a user that has no account yet (Post saving hook) — slice 5 dropped the eager sign-up hook, so
 * users who never own a store (reseller team members, partners, platform admins without a store) get none; staff members,
 * customers and drivers are separate models that take their store's account (BelongsToAccount). A new
 * account adopts the user's earlier store-less rows (subscriptions, transactions, …).
 */
final class AccountProvisioner
{
    /**
     * The owner's account, created on first use. Only called when the user owns a store (create, transfer,
     * restore) — a platform admin who owns a store gets one too (5b: every store row has an account).
     */
    public static function forUser(User $user): Account
    {
        if ($user->account_id && ($existing = Account::find($user->account_id))) {
            return $existing;
        }

        $account = Account::create([
            'owner_user_id' => $user->id,
            'reseller_id' => $user->reseller_id ?: null,
            'name' => self::nameFor($user),
            'region' => self::region(null),
        ]);
        $user->forceFill(['account_id' => $account->id])->saveQuietly();
        // The request's own subject now has this account (slice 6: the store insert that follows must pass the
        // RLS WITH CHECK); the adoption re-keys store-less rows of this user only — platform work, explicit bypass.
        TenantContext::current()->accountProvisioned((int) $user->id, (int) $account->id);
        TenantContext::bypass(fn () => TenantFill::adoptUserRows($user->getConnection(), (int) $user->id, (int) $account->id));

        return $account;
    }

    /** A supported region, else the configured default. */
    public static function region(?string $region): string
    {
        $regions = (array) config('tenancy.regions', ['global']);
        $default = (string) config('tenancy.default_region', 'global');
        if ($region === null) {
            return in_array($default, $regions, true) ? $default : 'global';
        }
        if (! in_array($region, $regions, true)) {
            throw new InvalidArgumentException("Unsupported tenancy region [{$region}].");
        }

        return $region;
    }

    private static function nameFor(User $user): string
    {
        $name = trim((string) ($user->name ?: $user->username ?: $user->email ?: 'Account '.$user->id));

        return mb_substr($name, 0, 255);
    }
}
