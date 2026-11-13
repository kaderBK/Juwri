<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Support\UploadLimit;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * A5-4: the one JSON error envelope for the v1 API surface.
 *
 *   { "message": <human string>, "code": <machine string>, "errors"?: {field:[…]} }
 *
 * Every hand-thrown API auth/authorization response and every framework exception
 * on an `api/v1/*` route resolves through here, so integrators parse ONE shape
 * (previously `{error}`, `{message,errors}` and bare HTTP errors all coexisted).
 *
 * P3-06b (kit/api README §6): an optional `details` object carries machine data for recovery — the new `quote`
 * on 409 `quote_changed`, the missing plan `feature` on the `*_api_not_enabled` 403s, the `line_ids` a business
 * rule refused. Additive: callers that pass none get the old shape.
 */
class ApiError
{
    /** Machine `code` for a bare HTTP status when a more specific one isn't given. */
    private const STATUS_CODES = [
        400 => 'bad_request',
        401 => 'unauthenticated',
        403 => 'forbidden',
        404 => 'not_found',
        405 => 'method_not_allowed',
        409 => 'conflict',
        413 => 'payload_too_large',
        419 => 'token_mismatch',
        422 => 'unprocessable',
        429 => 'rate_limited',
        500 => 'server_error',
        503 => 'service_unavailable',
    ];

    /**
     * Envelope with the machine `code` inferred from the HTTP status.
     *
     * @param  array<string, mixed>|null  $errors
     */
    public static function status(int $status, string $message, ?array $errors = null): JsonResponse
    {
        return self::response($status, self::STATUS_CODES[$status] ?? 'http_error', $message, $errors);
    }

    /**
     * Build the envelope response directly (used by the auth middlewares).
     *
     * @param  array<string, mixed>|null  $errors
     * @param  array<string, mixed>|null  $details  machine data for recovery (README §6): quote, feature, line_ids, next_free_slot
     * @param  array<string, string>  $headers
     */
    public static function response(int $status, string $code, string $message, ?array $errors = null, ?array $details = null, array $headers = []): JsonResponse
    {
        $body = ['message' => $message, 'code' => $code];
        if ($errors !== null) {
            $body['errors'] = $errors;
        }
        if ($details !== null && $details !== []) {
            $body['details'] = $details;
        }

        return response()->json($body, $status, $headers);
    }

    /** Map any Throwable to the envelope (used by the exception handler for api/v1). */
    public static function fromException(Throwable $e): JsonResponse
    {
        if ($e instanceof ValidationException) {
            return self::response(422, 'validation_failed', $e->getMessage(), $e->errors());
        }
        if ($e instanceof AuthenticationException) {
            return self::response(401, 'unauthenticated', $e->getMessage() ?: 'Unauthenticated.');
        }
        if ($e instanceof AuthorizationException) {
            return self::response(403, 'forbidden', $e->getMessage() ?: 'This action is unauthorized.');
        }
        if ($e instanceof ModelNotFoundException) {
            return self::response(404, 'not_found', 'Resource not found.');
        }
        // P6-212: the host's post_max_size cap. Integrators get the same envelope shape with
        // `details.max_mb` so the recovery hint ("raise upload_max_filesize / post_max_size in
        // php.ini") is machine-readable, not just prose on a translated HTML page.
        if ($e instanceof PostTooLargeException) {
            $mb = UploadLimit::effectiveMaxMb();

            return self::response(
                413,
                'payload_too_large',
                $mb > 0
                    ? "Your host stopped this upload — the file exceeds {$mb} MB. To send a larger file, ask your host to raise upload_max_filesize and post_max_size in php.ini."
                    : 'Your host stopped this upload (file too large). To send a larger file, ask your host to raise upload_max_filesize and post_max_size in php.ini.',
                null,
                $mb > 0 ? ['max_mb' => $mb] : null,
                $e->getHeaders() + ['Cache-Control' => 'no-store'],
            );
        }
        if ($e instanceof ThrottleRequestsException) {
            // P3-06b bug fix: the limiter's Retry-After / X-RateLimit-* headers were dropped with the exception, so a
            // throttled client was told to slow down without being told for how long (README §6: 429 always carries
            // Retry-After).
            $headers = array_map(fn ($v) => is_array($v) ? implode(', ', $v) : (string) $v, $e->getHeaders());
            $retry = $headers['Retry-After'] ?? null;
            $message = $retry !== null && ctype_digit($retry)
                ? 'Too many requests. Retry after '.$retry.' seconds.'
                : ($e->getMessage() ?: 'Too many requests.');

            // P3-09 bug fix: a 429 is never cached (kit example errors/08: `Cache-Control: no-store`)
            return self::response(429, 'rate_limited', $message, null, null, $headers + ['Cache-Control' => 'no-store']);
        }
        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $code = self::STATUS_CODES[$status] ?? 'http_error';
            $headers = array_map(fn ($v) => is_array($v) ? implode(', ', $v) : (string) $v, $e->getHeaders());

            return self::response($status, $code, $e->getMessage() ?: (self::STATUS_CODES[$status] ?? 'Error'), null, null, $headers);
        }

        // Unhandled: never leak internals unless APP_DEBUG is on.
        $message = config('app.debug') ? ($e->getMessage() ?: 'Server error.') : 'Server error.';

        return self::response(500, 'server_error', $message);
    }
}
