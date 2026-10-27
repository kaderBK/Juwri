<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Http\Controllers;

use App\Models\StaffMember;
use App\Services\StaffTwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Juwri\Core\Contracts\Tenancy\ResellerContext;
use Juwri\Core\Http\Controllers\Controller;

class StaffLoginController extends Controller
{
    public function showLoginForm(): View|RedirectResponse
    {
        if (Auth::guard('staff')->check() || is_staff_dashboard()) {
            return redirect()->route('dashboard');
        }

        return view('auth.staff-login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        /** @var \Illuminate\Auth\SessionGuard $guard */
        $guard = Auth::guard('staff');
        if (! $guard->validate($credentials + ['active' => 1])) {
            return back()
                ->withInput($request->only('email', 'remember'))
                ->withErrors(['email' => ___('Invalid staff credentials or inactive account.')]);
        }

        /** @var StaffMember|null $staff */
        $staff = $guard->getProvider()->retrieveByCredentials($credentials + ['active' => 1]);
        if (! $staff instanceof StaffMember) {
            return back()
                ->withInput($request->only('email', 'remember'))
                ->withErrors(['email' => ___('Invalid staff credentials or inactive account.')]);
        }

        // Same TOTP step as the API staff login: enrolled staff must give a code
        // (or a recovery code) before either guard is logged in.
        if ($staff->twoFactorEnabled()) {
            $code = trim((string) $request->input('code', ''));
            if ($code === '' || ! StaffTwoFactorService::verify($staff, $code)) {
                return back()
                    ->withInput($request->only('email', 'remember'))
                    ->withErrors(['code' => $code === ''
                        ? ___('Enter the code from your authenticator app.')
                        : ___('Invalid two-factor code.')]);
            }
        }

        self::startSession($request, $staff, $request->boolean('remember'));
        $request->session()->put(self::CONFIRMED_AT, now()->getTimestamp());

        return redirect()->route('dashboard');
    }

    /** P1-19b: when the staff member last proved who they are (password or passkey) in this session. */
    public const CONFIRMED_AT = 'staff.confirmed_at';

    /**
     * Open the staff session — shared by the password and the passkey sign-in (P1-19b). The staff guard holds
     * the staff member; the web guard holds the OWNER, fenced by EnsureStaffDashboardPermission
     * (staff_session_id). Owner-only elevation a previous owner session may have left in this browser session
     * (password confirmation, passkey MFA marker, reseller 2FA pass, impersonation) is dropped first, so the
     * staff session never inherits it.
     */
    public static function startSession(Request $request, StaffMember $staff, bool $remember): void
    {
        $request->session()->forget([
            'auth.password_confirmed_at', 'auth.passkey_user_id', ResellerContext::TWO_FACTOR_PASSED,
            'login.2fa.id', 'login.2fa.key', 'login.2fa.remember', 'quick_admin_user_id', 'quick_admin_impersonation',
            'impersonating', self::CONFIRMED_AT,
        ]);

        Auth::guard('staff')->login($staff, $remember);
        $request->session()->regenerate();
        $staff->forceFill(['last_login_at' => now()])->save();

        if ($staff->owner) {
            Auth::guard('web')->login($staff->owner);
        }

        $request->session()->put('staff_session_id', $staff->id);
    }

    public function portal(): RedirectResponse
    {
        return redirect()->route('dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('staff')->logout();
        Auth::guard('web')->logout();

        $request->session()->forget('staff_session_id');

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('staff.login');
    }
}
