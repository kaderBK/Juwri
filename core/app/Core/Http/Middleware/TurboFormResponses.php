<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Owner app, server item S7 (rev/mobile-app/FORMS-AUDIT.md): answer a TURBO form
 * submission the way Turbo can follow it.
 *
 * Turbo (and so Hotwire Native, whose navigation is driven by Turbo) only
 * accepts two answers to a form POST: a redirect (followed, becomes a visit — a
 * native screen) or a 4xx/5xx HTML page (rendered in place). A 200 page or a
 * JSON body is dropped ("Form responses must redirect to another location"), so
 * the owner taps Save and nothing happens. Forms are Turbo-driven only inside
 * the native app (turbo-compat.js); the browser keeps native form submissions,
 * never sends Turbo's headers on them, and is never touched by this middleware.
 *
 *   302 / 301 redirect      → 303 See Other (always a GET, as Turbo recommends)
 *   JSON (2xx / 4xx)        → 303 back, its `message` flashed as a success / error
 *                             toast (+ field `errors` and old input on a failure)
 *   200 HTML page           → the same page as 422, so Turbo renders it in place
 *   anything else           → unchanged (downloads, streams, 204, 5xx, 4xx HTML)
 */
class TurboFormResponses
{
    /** Old input never flashed back (mirrors the exception handler's $dontFlash). */
    private const DONT_FLASH = ['password', 'password_confirmation', 'current_password', '_token', '_method'];

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (! self::isTurboFormSubmission($request)) {
            return $response;
        }

        return self::adapt($request, $response);
    }

    /**
     * A non-GET request made by Turbo's form submission. Turbo stamps every fetch
     * with X-Turbo-Request-Id (8.x) and asks for Turbo Streams first on form
     * submissions (7.x and 8.x). JS fetch()/AJAX callers send neither.
     */
    public static function isTurboFormSubmission(Request $request): bool
    {
        if ($request->isMethodSafe() || $request->ajax()) {
            return false;
        }

        return $request->headers->has('X-Turbo-Request-Id')
            || str_contains((string) $request->headers->get('Accept', ''), 'text/vnd.turbo-stream.html');
    }

    public static function adapt(Request $request, Response $response): Response
    {
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return $response;
        }

        $status = $response->getStatusCode();

        if ($response instanceof RedirectResponse || $response->isRedirect()) {
            if (in_array($status, [301, 302], true)) {
                $response->setStatusCode(303);
            }

            return $response;
        }

        if (self::isJson($response) && $status >= 200 && $status < 500 && $status !== 204) {
            return self::jsonToRedirect($request, $response);
        }

        if ($status === 200 && self::isHtml($response)) {
            $response->setStatusCode(422);
        }

        return $response;
    }

    private static function jsonToRedirect(Request $request, Response $response): RedirectResponse
    {
        $data = $response instanceof JsonResponse ? $response->getData(true) : json_decode((string) $response->getContent(), true);
        $data = is_array($data) ? $data : [];

        $failed = $response->getStatusCode() >= 400
            || (array_key_exists('success', $data) && $data['success'] === false)
            || (array_key_exists('ok', $data) && $data['ok'] === false);

        $message = is_string($data['message'] ?? null) ? trim($data['message']) : '';
        $errors = is_array($data['errors'] ?? null) ? $data['errors'] : [];
        if ($failed && $message === '' && $errors !== []) {
            $first = reset($errors);
            $message = is_array($first) ? (string) (reset($first) ?: '') : (string) $first;
        }

        $redirect = redirect()->back(303);
        foreach ($response->headers->getCookies() as $cookie) {
            $redirect->headers->setCookie($cookie);
        }

        if ($message !== '') {
            $redirect->with($failed ? 'error' : 'success', $message);
        }
        if ($failed && $errors !== []) {
            $redirect->withErrors($errors)->withInput($request->except(self::DONT_FLASH));
        }

        return $redirect;
    }

    private static function isJson(Response $response): bool
    {
        if ($response instanceof JsonResponse) {
            return true;
        }
        $type = strtolower((string) $response->headers->get('Content-Type', ''));

        return str_starts_with($type, 'application/json') || str_contains($type, '+json');
    }

    private static function isHtml(Response $response): bool
    {
        $type = strtolower((string) $response->headers->get('Content-Type', ''));

        // An unprepared Illuminate response has no Content-Type yet; Symfony's
        // prepare() later defaults it to text/html.
        return str_starts_with($type, 'text/html') || ($type === '' && $response instanceof IlluminateResponse);
    }
}
