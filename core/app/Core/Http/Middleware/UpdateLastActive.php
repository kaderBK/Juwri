<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Middleware;

use Juwri\Core\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records when an owner/admin account was last seen (users.lastactive),
 * shown in the admin user list.
 */
class UpdateLastActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user instanceof User) {
            $user->update(['lastactive' => now()]);
        }

        return $next($request);
    }
}
