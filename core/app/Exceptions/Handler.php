<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Exceptions;

use App\Support\ApiError;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Juwri\Core\Support\ErrorPage;
use Juwri\Core\Support\UploadLimit;
use Psr\Log\LogLevel;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<Throwable>, LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        // O2: forward unhandled, reportable exceptions to Sentry when configured.
        // The `sentry` binding only exists when SENTRY_LARAVEL_DSN is set, so this
        // is a no-op (identical to before) until the owner enables it in prod.
        $this->reportable(function (Throwable $e) {
            if (config('sentry.dsn') && app()->bound('sentry') && $this->shouldReport($e)) {
                app('sentry')->captureException($e);
            }
        });

        // A5-4: every framework exception on an api/v1 route resolves to the ONE
        // JSON envelope { message, code, errors? } — validation, auth, not-found,
        // throttling and 500s alike — so integrators parse a single shape.
        // (Controllers that return a bespoke `response()->json([...])` are not
        // thrown, so they are untouched here — the auth middlewares are updated
        // separately to the same envelope.)
        $this->renderable(function (Throwable $e, $request) {
            if ($request->is('api/v1', 'api/v1/*')) {
                return ApiError::fromException($e);
            }

            return null;
        });

        // B1 (theme-audit-followups / API robustness): the Passport-guarded API
        // routes — /api/user and /api/app/* — build the `api` guard EAGERLY, and
        // that construction resolves the ResourceServer singleton, which builds a
        // League CryptKey from the OAuth *public* key. When that key is missing or
        // unreadable (a deploy that never ran `php artisan passport:keys`, or a
        // wiped storage key), CryptKey's constructor throws
        // `LogicException: Invalid key supplied` (vendor .../league/oauth2-server/
        // src/CryptKey.php) DURING guard resolution — before any bearer token is
        // inspected — so nothing on the `auth:api` path catches it and every call
        // 500s (this is what RouteSmokeSweepTest hit in single-process runs, where
        // TestCase's parallel key-seeding never runs).
        //
        // Degrade THAT one misconfiguration to a clean 401 JSON: the same "we
        // cannot authenticate you" answer these routes already give an anonymous
        // request, never a 500. This does NOT weaken security — the request is
        // REFUSED (never granted) and no token validation is skipped: with no key,
        // no token can be verified, so no caller can be authenticated. Restoring
        // the PROD key is an operator action, not a code change. Scoped tightly to
        // the CryptKey origin + the passport routes, so an unrelated LogicException
        // still surfaces normally.
        $this->renderable(function (\LogicException $e, $request) {
            $fromCryptKey = str_contains(
                strtr($e->getFile(), '\\', '/'),
                'league/oauth2-server/src/CryptKey.php'
            );

            if ($fromCryptKey && $request->is('api/user', 'api/app/*')) {
                return response()->json([
                    'message' => 'API authentication is currently unavailable.',
                ], 401);
            }

            return null;
        });

        // P6-212: when the request body crosses the host's post_max_size (Laravel's
        // ValidatePostSize middleware), PHP strips $_POST/$_FILES before the route
        // runs. The owner saw a raw "413 — POST content length" page with no hint of
        // which PHP limit to raise. We now resolve it to the same friendly,
        // translated page that the 5xx stack uses, naming the real per-file ceiling.
        // API/JSON callers already get the matching envelope from `ApiError::fromException`
        // via the api/v1 wrapper above; on the rest of the API surface (api/user,
        // api/app/*, /livewire/update) we still need to short-circuit here so they
        // get the same `payload_too_large` shape (not an empty 413).
        $this->renderable(function (PostTooLargeException $e, $request) {
            $mb = UploadLimit::effectiveMaxMb();
            $context = $mb > 0 ? ['limit' => $mb] : [];
            $isJson = $request->expectsJson() || $request->is('api/*');

            if ($isJson) {
                return ApiError::fromException($e);
            }

            return ErrorPage::response(413, $request, $e->getHeaders(), $context);
        });
    }

    /**
     * P6-112: HTML error pages.
     *
     * 5xx (500, 503 incl. maintenance mode) are answered by ErrorPage as plain PHP: no view composer, no database,
     * no session, and never the exception text or trace (APP_DEBUG=false is the only way a trace is shown, and that
     * path does not come through here). 4xx keep their Blade pages, but pick the language from the cookie /
     * Accept-Language / default when routing did not set one (a 404 has no route, so no session locale yet).
     */
    protected function renderHttpException(HttpExceptionInterface $e)
    {
        $request = request();
        $status = $e->getStatusCode();

        if (ErrorPage::handles($status)) {
            try {
                return ErrorPage::response($status, $request, $e->getHeaders());
            } catch (Throwable) {
                // P6-173: the database is most likely exactly the reason we got
                // here, and ErrorPage::response() reads the lang/ folder and
                // reaches into the translator (lang/<locale>/lang.php). If
                // anything inside that path throws, fall back to the
                // self-contained English page so the visitor never sees an
                // empty 500 body. Headers from the original exception pass
                // through (Retry-After on a 503 is the important one); the
                // nested try is best-effort because emergency() must always
                // return a Response, with or without the extra headers.
                $fallback = ErrorPage::emergency($status);
                try {
                    $fallback->headers->add($e->getHeaders());
                } catch (Throwable) {
                }

                return $fallback;
            }
        }

        $previous = app()->getLocale();
        if ($previous === ErrorPage::defaultLocale()) {
            app()->setLocale(ErrorPage::resolveLocale($request));
        }

        try {
            return parent::renderHttpException($e);
        } finally {
            app()->setLocale($previous);
        }
    }
}
