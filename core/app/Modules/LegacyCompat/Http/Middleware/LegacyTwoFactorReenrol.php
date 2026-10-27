<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Http\Middleware;

use App\Models\StaffMember;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Juwri\Core\Models\User;
use Juwri\Modules\LegacyCompat\Import\HistoryImporter;
use Juwri\Modules\LegacyCompat\Import\LegacyTableMaps;
use Juwri\Modules\LegacyCompat\Support\LegacyCompatRoutes;
use Symfony\Component\HttpFoundation\Response;

/**
 * J58b: two-factor secrets are not carried over from the old platform; the importer marks every owner and staff member
 * who had two-factor on ({@see LegacyTableMaps::TWO_FACTOR_REENROL}). This middleware (on the `web` and `api` groups
 * while the module is on) asks them to set it up again:
 *
 *  - an owner, in their OWN session (never while an admin is logged in as them, never inside a staff session, which
 *    also signs the owner in on the web guard), is sent once to the two-factor setup page on their next page view;
 *    the mark is removed when that page is actually shown (or once two-factor is on again);
 *  - a staff member (staff app, token sign-in) gets `two_factor_setup_required: true` in the app's profile response
 *    until two-factor is on again (the staff app is where staff enrol); then the mark is removed.
 */
final class LegacyTwoFactorReenrol
{
    public const SETUP_ROUTE = '2fa.show';

    /** The staff app's profile route, read right after sign-in. */
    public const STAFF_PROFILE_ROUTE = 'api.v1.staff.me';

    public const STAFF_FLAG = 'two_factor_setup_required';

    /** Session key: the owner id already checked and found without a pending mark (one lookup per session). */
    private const CHECKED = 'legacy_2fa_reenrol_checked';

    /** Session keys of a session that is not the owner's own (admin "log in as", staff session). */
    private const NOT_OWN = ['quick_admin_user_id', 'quick_admin_impersonation', 'impersonating', 'staff_session_id'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! LegacyCompatRoutes::enabled()) {
            return $next($request);
        }
        $redirect = $this->owner($request);
        if ($redirect !== null) {
            return $redirect;
        }
        $response = $next($request);

        return $this->staff($request, $response);
    }

    private function owner(Request $request): ?Response
    {
        if (! $request->hasSession() || Auth::guard('staff')->check()) {
            return null;
        }
        $user = Auth::guard('web')->user();
        if (! $user instanceof User) {
            return null;
        }
        $session = $request->session();
        foreach (self::NOT_OWN as $key) {
            if ($session->has($key)) {
                return null;
            }
        }
        if ($session->get(self::CHECKED) === $user->getKey()) {
            return null;
        }
        $marks = self::marks('users', (int) $user->getKey());
        if (! (clone $marks)->exists() || $user->hasTwoFactorEnabled() || ! Route::has(self::SETUP_ROUTE)) {
            $marks->delete();
            $session->put(self::CHECKED, $user->getKey());

            return null;
        }
        if ($request->routeIs(self::SETUP_ROUTE)) {
            $marks->delete(); // shown: asked once
            $session->put(self::CHECKED, $user->getKey());

            return null;
        }
        if (! $request->isMethod('GET') || $request->expectsJson() || $request->routeIs('2fa.*', 'logout', 'password.*')) {
            return null;
        }

        return redirect()->route(self::SETUP_ROUTE);
    }

    private function staff(Request $request, Response $response): Response
    {
        if (! $response instanceof JsonResponse || ! $request->routeIs(self::STAFF_PROFILE_ROUTE) || ! app()->bound('api.staff')) {
            return $response;
        }
        $staff = app('api.staff');
        if (! $staff instanceof StaffMember) {
            return $response;
        }
        $marks = self::marks('staff_members', (int) $staff->getKey());
        if (! (clone $marks)->exists()) {
            return $response;
        }
        if ($staff->twoFactorEnabled()) {
            $marks->delete();

            return $response;
        }
        $data = $response->getData(true);
        if (is_array($data)) {
            $response->setData([self::STAFF_FLAG => true] + $data);
        }

        return $response;
    }

    private static function marks(string $table, int $id): Builder
    {
        return DB::table(HistoryImporter::MARKS_TABLE)->where('target_table', $table)
            ->where('target_id', (string) $id)->where('mark', LegacyTableMaps::TWO_FACTOR_REENROL);
    }
}
