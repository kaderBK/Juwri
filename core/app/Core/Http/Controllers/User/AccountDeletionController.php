<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Controllers\User;

use App\Services\Account\AccountDeletionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\AccountDeletion;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\User;
use Juwri\Core\Support\OwnerApp;

/**
 * Owner self-service account deletion (Apple 5.1.1(v); owner-app server item S3).
 * Owner-only: a staff session, an admin, or a reseller support (impersonation)
 * session can never close the owner's account.
 */
class AccountDeletionController extends Controller
{
    public function __construct(private AccountDeletionService $deletions) {}

    private function ensureOwnerSession(Request $request): void
    {
        $user = $request->user();
        abort_if(! $user, 403);
        abort_if($user->isAdmin(), 403);
        abort_if($request->session()->has('staff_session_id'), 403);
        abort_if(is_array($request->session()->get('impersonating')), 403);
    }

    private function confirmsWithEmail(User $user): bool
    {
        return (string) ($user->oauth_provider ?? '') !== '' || (string) $user->password === '';
    }

    public function show(Request $request): View
    {
        $this->ensureOwnerSession($request);

        return view('owner.settings.account-delete', [
            'graceDays' => AccountDeletionService::GRACE_DAYS,
            // Social-login owners may never have set a password: they confirm with their email.
            'confirmWithEmail' => $this->confirmsWithEmail($request->user()),
        ]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->ensureOwnerSession($request);
        $user = $request->user();

        $byEmail = $this->confirmsWithEmail($user);
        $request->validate([
            'password' => [$byEmail ? 'nullable' : 'required', 'string'],
            'confirm_email' => [$byEmail ? 'required' : 'nullable', 'string'],
            'understand' => ['accepted'],
            'reason' => ['nullable', 'string', 'max:500'],
        ], [
            'understand.accepted' => ___('Please confirm that you understand what will be deleted.'),
        ]);

        if ($byEmail) {
            if (strcasecmp(trim((string) $request->input('confirm_email')), (string) $user->email) !== 0) {
                return back()->withErrors(['confirm_email' => ___('Type your account email exactly to confirm.')]);
            }
        } elseif (! Hash::check((string) $request->input('password'), (string) $user->password)) {
            return back()->withErrors(['password' => ___('The password is incorrect.')]);
        }

        $deletion = $this->deletions->request(
            $user,
            $request->input('reason'),
            $request->ip(),
            OwnerApp::inApp($request) ? 'app' : 'web'
        );

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        app_alert_success(___('Your account is closed. It will be permanently deleted on :date. We emailed you a link to restore it before then.', [
            'date' => $deletion->purge_after->translatedFormat('j F Y'),
        ]));

        // In the native app, the logout marker sends the shell to its native login screen.
        return redirect()->route('login', OwnerApp::inApp($request) ? ['app_logout' => 1] : []);
    }

    /**
     * Signed link from the confirmation email (valid until the purge date). BL-036 A1b: GET only
     * renders a confirm page — no state change, so a mail scanner pre-fetching the link restores
     * nothing. Links already sent keep working and land here.
     */
    public function restoreConfirm(Request $request, AccountDeletion $deletion): Response
    {
        $usable = $deletion->status === AccountDeletion::PENDING && ! $deletion->purge_after->isPast()
            && User::whereKey($deletion->user_id)->exists();
        $code = User::find($deletion->user_id)?->preferred_locale;
        if ($code && Language::where('code', $code)->where('active', 1)->exists()) {
            app()->setLocale((string) $code);
        }

        return response()->view('account.restore-confirm', [
            'state' => $usable ? 'confirm' : 'invalid',
            'deletion' => $deletion,
            'brand' => (string) config('settings.site_title', config('app.name')),
            // POST back to the exact signed path + query that was opened (same host).
            'action' => $usable ? $request->getRequestUri() : null,
        ], $usable ? 200 : 410)
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('Cache-Control', 'no-store');
    }

    /** POST (CSRF + the same signature): perform the restore. */
    public function restore(Request $request, AccountDeletion $deletion): RedirectResponse
    {
        if ($this->deletions->restore($deletion)) {
            app_alert_success(___('Your account has been restored. You can log in again. Your previous subscription was cancelled, so choose a plan to continue on a paid plan.'));
        } else {
            app_alert_error(___('This restore link is no longer valid.'));
        }

        return redirect()->route('login');
    }
}
