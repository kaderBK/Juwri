<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support\StorefrontApi;

use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Studio\Gesso\OpenApiRequestValidator;
use Studio\Gesso\OpenApiResponseValidator;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;

/**
 * P3-06b step 0 (owner Q6: both) — the headless storefront API's contract check in tests:
 *
 *  1. the outside OpenAPI 3.1 validator `studio-design/gesso` (MIT, require-dev only, never shipped) validates
 *     requests and responses against the kit contract tools/themes/kit/api/openapi.json (it follows the View Model
 *     `$ref`s into ../viewmodel/viewmodel.schema.json; JSON Schema 2020-12 via opis/json-schema);
 *  2. our own juwri rules the schema cannot say ({@see juwriRules()}): money is integer minor units, ids are public
 *     ids (never an internal integer id outside the frozen legacy shapes), errors are the ApiError envelope with the
 *     README §6 code ↔ status table and its `details`, the caching family per operation, the deprecation headers on
 *     the legacy aliases, Retry-After on 429.
 */
final class ApiContract
{
    public const SPEC = 'api/openapi';

    /** README §6: error code → HTTP status. */
    public const ERROR_STATUS = [
        'unauthenticated' => 401, 'forbidden' => 403,
        'storefront_api_not_enabled' => 403, 'booking_api_not_enabled' => 403, 'kiosk_api_not_enabled' => 403,
        'feature_disabled' => 403, 'kiosk_not_enabled' => 403, 'phone_verification_required' => 403,
        'not_found' => 404, 'quote_changed' => 409, 'idempotency_conflict' => 409,
        'slot_taken' => 409, 'slot_full' => 409, 'hold_expired' => 409, 'hold_in_use' => 409, 'price_changed' => 409,
        'past_deadline' => 409, 'booking_not_active' => 409, 'store_closed' => 409, 'bookings_not_open' => 409,
        'validation_failed' => 422, 'out_of_zone' => 422, 'below_minimum' => 422, 'out_of_stock' => 422,
        'invalid_order_type' => 422, 'payment_method_unavailable' => 422, 'payment_mode_unavailable' => 422,
        'return_url_not_allowed' => 422, 'otp_invalid' => 422, 'rate_limited' => 429,
        // legacy body errors keep the generic envelope codes (LegacyOrderRequest: `unprocessable`)
        'unprocessable' => 422,
    ];

    public const CACHE_PUBLIC120 = ['getStore', 'getCatalog', 'getItem', 'legacyMenu', 'legacyPublicMenu'];

    public const CACHE_NOSTORE = ['quoteCart', 'createOrder', 'getOrder', 'requestCustomerOtp', 'verifyCustomerOtp'];

    public const LEGACY = ['legacyMenu', 'legacyRestaurant', 'legacyTrack', 'legacyPublicMenu'];

    private static bool $configured = false;

    public static function configure(): void
    {
        if (! self::$configured) {
            OpenApiSpecLoader::configure(realpath(base_path('../tools/themes/kit')) ?: base_path('../tools/themes/kit'));
            self::$configured = true;
        }
    }

    /** @return array<string, mixed> the kit spec (resolved) */
    public static function spec(): array
    {
        self::configure();

        return OpenApiSpecLoader::load(self::SPEC);
    }

    /** @return array<string, mixed> operation id => [method, path template, operation] */
    public static function operations(): array
    {
        $out = [];
        foreach (self::spec()['paths'] as $path => $ops) {
            foreach ($ops as $method => $op) {
                if (is_array($op) && isset($op['operationId'])) {
                    $out[$op['operationId']] = [strtoupper($method), $path, $op];
                }
            }
        }

        return $out;
    }

    /**
     * Validate a request the client sends (method, concrete path, query, headers, body) against the contract.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $headers
     */
    public static function assertRequest(string $method, string $path, array $query, array $headers, mixed $body): void
    {
        self::configure();
        $result = (new OpenApiRequestValidator)->validateWithoutRecording(
            self::SPEC, $method, $path, $query, array_change_key_case($headers, CASE_LOWER), $body, $body === null ? null : 'application/json'
        );
        Assert::assertTrue($result->isValid(), "request {$method} {$path} is not valid for the contract:\n".$result->errorMessage());
    }

    /**
     * Validate a response of the app against the contract (status documented, declared headers, body schema), then
     * the juwri rules.
     */
    public static function assertResponse(string $operationId, string $method, string $path, TestResponse $response): void
    {
        self::configure();
        $status = $response->getStatusCode();
        $headers = [];
        foreach ($response->headers->all() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }
        // objects stay objects (stdClass): an empty `{}` the app sends must not turn into `[]` before the schema sees it
        $body = $status === 304 || $response->getContent() === '' ? null : json_decode((string) $response->getContent());
        $result = (new OpenApiResponseValidator(new StrictRequiredTracker))->validateWithoutRecording(
            self::SPEC, $method, $path, $status, $body, $body === null ? null : 'application/json', $headers
        );
        Assert::assertTrue($result->isValid(), "{$operationId} {$status} does not match the contract:\n".$result->errorMessage()."\n".substr((string) $response->getContent(), 0, 2000));
        self::juwriRules($operationId, $response);
    }

    /** The juwri-specific checks (own checker, owner Q6). */
    public static function juwriRules(string $operationId, TestResponse $response): void
    {
        $status = $response->getStatusCode();
        $body = json_decode((string) $response->getContent(), true);
        $legacy = in_array($operationId, self::LEGACY, true)
            || ($operationId === 'createOrder' && is_array($body) && array_key_exists('ok', $body));

        if (in_array($operationId, self::LEGACY, true) || $response->headers->has('Deprecation')) {
            foreach (['Deprecation', 'Sunset', 'Link'] as $h) {
                Assert::assertTrue($response->headers->has($h), "{$operationId}: a deprecated alias answers {$h}");
            }
            Assert::assertSame('true', $response->headers->get('Deprecation'));
            Assert::assertNotFalse(\DateTimeImmutable::createFromFormat(DATE_RFC7231, (string) $response->headers->get('Sunset')), 'Sunset is an HTTP-date');
            Assert::assertStringContainsString('rel="deprecation"', (string) $response->headers->get('Link'));
        }

        if ($status >= 400) {
            Assert::assertIsArray($body, "{$operationId} {$status}: an error has a JSON body");
            Assert::assertIsString($body['message'] ?? null, 'ApiError.message');
            Assert::assertIsString($body['code'] ?? null, 'ApiError.code');
            Assert::assertSame([], array_diff(array_keys($body), ['message', 'code', 'errors', 'details']), 'ApiError has only message, code, errors, details');
            if (isset(self::ERROR_STATUS[$body['code']])) {
                Assert::assertSame(self::ERROR_STATUS[$body['code']], $status, "code {$body['code']} answers HTTP ".self::ERROR_STATUS[$body['code']]);
            }
            if (isset($body['details'])) {
                Assert::assertSame([], array_diff(array_keys($body['details']), ['quote', 'next_free_slot', 'line_ids', 'feature']), 'ApiError.details keys');
            }
            if (str_ends_with((string) $body['code'], '_api_not_enabled')) {
                Assert::assertSame(substr((string) $body['code'], 0, -strlen('_not_enabled')), $body['details']['feature'] ?? null, 'details.feature names the missing plan feature');
            }
            if ($status === 429) {
                Assert::assertTrue($response->headers->has('Retry-After'), '429 carries Retry-After');
            }
            if ($status === 422 && ($body['code'] ?? null) === 'validation_failed') {
                Assert::assertIsArray($body['errors'] ?? null, 'validation_failed carries errors');
            }

            return;
        }

        if ($status >= 200 && $status < 300) {
            $cache = (string) $response->headers->get('Cache-Control');
            if (in_array($operationId, self::CACHE_PUBLIC120, true)) {
                Assert::assertStringContainsString('max-age=120', $cache, "{$operationId}: public, max-age=120");
                Assert::assertStringContainsString('public', $cache);
                Assert::assertNotEmpty($response->headers->get('ETag'), "{$operationId}: ETag");
            }
            if (in_array($operationId, self::CACHE_NOSTORE, true)) {
                Assert::assertStringContainsString('no-store', $cache, "{$operationId}: no-store");
            }
        }

        if (is_array($body) && ! $legacy) {
            self::walk($body, '$', $operationId);
        }
    }

    /** Money objects are integer minor units; nothing under an id-like key is an internal integer id. */
    private static function walk(mixed $node, string $path, string $op): void
    {
        if (! is_array($node)) {
            return;
        }
        if (array_key_exists('minor', $node) && array_key_exists('currency', $node) && array_key_exists('formatted', $node)) {
            Assert::assertIsInt($node['minor'], "{$op} {$path}.minor is an integer of minor units");
            Assert::assertMatchesRegularExpression('/^[A-Z]{3}$/', (string) $node['currency'], "{$op} {$path}.currency is ISO 4217");
        }
        foreach ($node as $key => $value) {
            $here = $path.'.'.$key;
            if (is_string($key) && ($key === 'id' || str_ends_with($key, '_id') || str_ends_with($key, '_ids'))) {
                foreach ((array) $value as $v) {
                    Assert::assertFalse(is_int($v), "{$op} {$here}: an internal integer id must never leave the API (public ids only)");
                }
            }
            if (is_string($key) && in_array($key, ['price', 'total', 'amount', 'subtotal'], true)) {
                Assert::assertFalse(is_float($value), "{$op} {$here}: money is never a float");
            }
            self::walk($value, $here, $op);
        }
    }
}
