<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession as BaseStartSession;

/**
 * P6-146: no stale session cookie after a concurrent login/logout.
 *
 * A slow GET that started on the guest id used to finish after the login
 * response and send Set-Cookie with the OLD id, putting the browser back on
 * the guest session (user looks logged out). Stock StartSession queues the
 * cookie BEFORE saving; here the session is saved first, and the cookie is
 * left off when the store reports its id was replaced meanwhile
 * (MergesConcurrentWrites::wasSuperseded — tombstone or vanished copy). The
 * browser then keeps whatever cookie the login/logout response set. Normal
 * requests still refresh the cookie on every response.
 *
 * Cookie driver: the session lives in the browser, there is no server copy to
 * compare with, so the stock behaviour (last response wins) stays.
 */
class StartSession extends BaseStartSession
{
    protected function handleStatefulRequest(Request $request, $session, Closure $next)
    {
        $request->setLaravelSession($this->startSession($request, $session));

        $this->collectGarbage($session);

        $response = $next($request);

        $this->storeCurrentUrl($request, $session);

        $this->saveSession($request);

        if (! (method_exists($session, 'wasSuperseded') && $session->wasSuperseded())) {
            $this->addCookieToResponse($response, $session);
        }

        return $response;
    }
}
