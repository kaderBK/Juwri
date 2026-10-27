<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\User;
use Juwri\Modules\LegacyCompat\Import\HistoryImporter;
use Juwri\Modules\LegacyCompat\Import\StoreSettingsImporter;
use Juwri\Modules\LegacyCompat\Support\LegacyCompatRoutes;
use Symfony\Component\HttpFoundation\Response;

/**
 * J59e: payment gateways a migrated store had switched on at the old platform but that this install does not offer at
 * launch (J51a) are imported with their keys, switched off, and marked per store
 * ({@see StoreSettingsImporter::GATEWAYS_OFF}). This middleware (on the `web` group while the module is on) tells the
 * owner ONCE, on their own dashboard: the notice names the gateways and says to switch to Stripe or PayPal, or wait.
 * The marks are removed when the notice is shown. Never shown in an admin's "log in as" or a staff session.
 */
final class LegacyGatewayNotice
{
    public const ROUTE = 'dashboard';

    public const MESSAGE = 'Payment methods from your old platform are switched off here: :gateways. Switch to Stripe or PayPal, or wait until they are available.';

    /** Session keys of a session that is not the owner's own (admin "log in as", staff session). */
    private const NOT_OWN = ['quick_admin_user_id', 'quick_admin_impersonation', 'impersonating', 'staff_session_id'];

    public function handle(Request $request, Closure $next): Response
    {
        if (LegacyCompatRoutes::enabled() && $request->isMethod('GET') && $request->routeIs(self::ROUTE) && ! $request->expectsJson()) {
            $this->notice($request);
        }

        return $next($request);
    }

    private function notice(Request $request): void
    {
        if (! $request->hasSession() || Auth::guard('staff')->check()) {
            return;
        }
        $user = Auth::guard('web')->user();
        if (! $user instanceof User) {
            return;
        }
        $session = $request->session();
        foreach (self::NOT_OWN as $key) {
            if ($session->has($key)) {
                return;
            }
        }
        $stores = array_map('strval', DB::table('stores')->where('user_id', (int) $user->getKey())->pluck('id')->all());
        if ($stores === []) {
            return;
        }
        $marks = DB::table(HistoryImporter::MARKS_TABLE)->where('target_table', 'stores')
            ->where('mark', StoreSettingsImporter::GATEWAYS_OFF)->whereIn('target_id', $stores);
        $gateways = [];
        foreach ((clone $marks)->pluck('detail') as $detail) {
            $decoded = json_decode((string) $detail, true);
            foreach ((array) ($decoded['gateways'] ?? []) as $gateway) {
                $gateways[(string) $gateway] = ucwords(str_replace(['_', '-'], ' ', (string) $gateway));
            }
        }
        if ($gateways === []) {
            return;
        }
        ksort($gateways);
        // Flashed: shown on the page the owner lands on (the dashboard, or where it sends them).
        $session->flash('warning', ___(self::MESSAGE, ['gateways' => implode(', ', $gateways)]));
        $marks->delete(); // shown: told once
    }
}
