<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http\Middleware;

use Juwri\Core\Services\AbuseHold;
use Closure;
use Illuminate\Http\Request;

/**
 * Abuse Guard P6 (6.3): freeze writes for a held merchant. Applied to the authed
 * dashboard group only — public menu routes are outside it and stay live. Reads
 * (GET/HEAD/OPTIONS) always pass so the owner can still see their account; only
 * mutating verbs are blocked with 423 Locked.
 */
class AbuseHoldWall
{
    public function handle(Request $request, Closure $next)
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        // Resolve the account OWNER (staff roll up to their owner) so a held
        // merchant's staff are frozen too — not just the owner's own login.
        $ownerId = function_exists('dashboard_owner_id') ? dashboard_owner_id() : optional($request->user())->id;
        if ($ownerId && AbuseHold::active($ownerId)) {
            $msg = 'Your account is temporarily on hold pending review. Please contact support.';
            if ($request->expectsJson()) {
                return response()->json(['message' => $msg], 423);
            }

            return back()->with('error', $msg);
        }

        return $next($request);
    }
}
