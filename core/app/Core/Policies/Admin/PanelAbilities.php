<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Policies\Admin;

use Juwri\Core\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Superadmin panel areas that manage settings or files rather than records,
 * so they have no model policy: `Gate::authorize('admin.plugins')` etc.
 */
final class PanelAbilities
{
    public const PLUGINS = 'admin.plugins';

    public const MAIL_TEMPLATES = 'admin.mail-templates';

    public const SITE_TEMPLATE = 'admin.site-template';

    /** The restaurants (stores) list; Post itself keeps the owner-facing PostPolicy. */
    public const STORES = 'admin.stores';

    /** Platform settings (options) and the platform-default integration keys. */
    public const SETTINGS = 'admin.settings';

    /** The admin dashboard. */
    public const DASHBOARD = 'admin.dashboard';

    /** Merchant accounts (list, edit, plan, password, sign-in-as, backups). */
    public const ACCOUNTS = 'admin.accounts';

    /** The Laravel Pulse operations dashboard (P1-17; Pulse's own gate name). */
    public const PULSE = 'viewPulse';

    public static function register(): void
    {
        foreach ([self::PLUGINS, self::MAIL_TEMPLATES, self::SITE_TEMPLATE, self::STORES, self::SETTINGS, self::DASHBOARD, self::ACCOUNTS, self::PULSE] as $ability) {
            Gate::define($ability, fn (User $user): bool => $user->isAdmin());
        }
    }
}
