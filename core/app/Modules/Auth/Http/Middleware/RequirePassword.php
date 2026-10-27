<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\RequirePassword as FrameworkRequirePassword;

/**
 * `password.confirm`: the framework middleware, except that when it stops a
 * form POST (2FA enable / disable / regenerate codes) the confirmation screen
 * returns the user to the page the form was on. The framework remembers the
 * POST URL itself, and redirecting back to it after confirming is a GET that
 * the route does not accept (405).
 */
class RequirePassword extends FrameworkRequirePassword
{
    public function handle($request, Closure $next, $redirectToRoute = null, $passwordTimeoutSeconds = null)
    {
        $formPost = ! in_array($request->getMethod(), ['GET', 'HEAD'], true) && ! $request->expectsJson();

        if (! $formPost || ! $request->hasSession() || ! $this->shouldConfirmPassword($request, $passwordTimeoutSeconds)) {
            return parent::handle($request, $next, $redirectToRoute, $passwordTimeoutSeconds);
        }

        $request->session()->put('url.intended', url()->previous());

        return $this->responseFactory->redirectTo(
            $this->urlGenerator->route($redirectToRoute ?: 'password.confirm')
        );
    }
}
