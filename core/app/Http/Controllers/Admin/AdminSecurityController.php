<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http\Controllers\Admin;

use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Auth\Models\LoginAudit;
use Juwri\Core\Contracts\Tenancy\ResellerContext;
use Juwri\Core\Models\User;
use Illuminate\Contracts\View\View;
use Juwri\Core\Http\Hosts\HostMap;

/**
 * A1 (upstream superadmin hardening, 185d3adb0): who holds privileged accounts, and
 * whether they use two-factor authentication. The FORCE_2FA_ADMIN switch is
 * shown here but only the owner flips it (in .env).
 */
class AdminSecurityController extends Controller
{
    public function accounts(): View
    {
        $admins = User::where('user_type', 'admin')->orderBy('id')->get();
        // P1-13 13f: reseller owners through Core's extension point (Community: none).
        $resellerOwners = User::whereIn('id', app(ResellerContext::class)->ownerUserIds())->orderBy('id')->get();
        $ids = $admins->pluck('id')->merge($resellerOwners->pluck('id'))->unique();
        $lastLogins = LoginAudit::whereIn('user_id', $ids)
            ->selectRaw('user_id, MAX(created_at) as last_at, SUM(CASE WHEN new_device THEN 1 ELSE 0 END) as new_devices')
            ->groupBy('user_id')->get()->keyBy('user_id');

        return view('admin.security.accounts', [
            'admins' => $admins,
            'resellerOwners' => $resellerOwners,
            'lastLogins' => $lastLogins,
            'recent' => LoginAudit::whereIn('user_id', $ids)->orderByDesc('id')->limit(30)->get(),
            'force2fa' => (bool) config('auth.force_2fa_admin'),
            'hostWarnings' => HostMap::warnings(), // P1-20: single-host installs / split misconfiguration
        ]);
    }
}
