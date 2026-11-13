<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\Api;

use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * P3-06b: a refusal of the headless storefront API, rendered as the ApiError envelope `{message, code, errors?,
 * details?}` (kit/api README §6) with `Cache-Control: no-store`. Thrown from the services so a controller never
 * hand-builds an error body.
 */
final class StorefrontApiException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>|null  $errors
     * @param  array<string, mixed>|null  $details
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly ?array $errors = null,
        public readonly ?array $details = null,
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self(404, 'not_found', ___('Not found.'));
    }

    /** @param  array<string, list<string>>  $errors */
    public static function validation(array $errors, ?string $message = null): self
    {
        $first = null;
        foreach ($errors as $messages) {
            $first = $messages[0] ?? null;
            break;
        }

        return new self(422, 'validation_failed', $message ?? (string) ($first ?? ___('The request is not valid.')), $errors);
    }

    /** An expected refusal, not an error: never logged (a returned null stops Laravel's default reporting). */
    public function report(): void {}

    public function render(): JsonResponse
    {
        return ApiError::response($this->status, $this->errorCode, $this->getMessage(), $this->errors, $this->details, $this->headers + ['Cache-Control' => 'no-store']);
    }
}
