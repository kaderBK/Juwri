<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support\StorefrontApi;

use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;

/**
 * P3-06b done-definition: replay a kit API example (tools/themes/kit/api/examples/<flow>/<file>.json) through the
 * Laravel app — the recorded request (method, path, query, headers, body) is sent as is, after substituting the
 * values the server makes and the example could only invent (quote_hash, order public id / number / track token,
 * legacy integer ids) — and check:
 *
 *  - the example's request is valid for the contract (gesso request validator);
 *  - the app answers the example's status;
 *  - the app's answer matches the contract — status documented, declared headers, body schema (gesso) — and the
 *    juwri rules (ApiContract::juwriRules);
 *  - the headers the example shows that carry meaning (Cache-Control, Idempotent-Replayed, Deprecation / Sunset /
 *    Link, Retry-After, ETag) are present;
 *  - per example, the fields that carry the flow: error `code` and `details` keys, quote total and requirements,
 *    order and payment status, `replayed`.
 *
 * Values the app returns are recorded (example value → app value) and substituted into the later requests of the
 * flow, as check_examples.py's flow rules require (the submit echoes the last quote_hash, tracking reads the created
 * order's token).
 */
final class ExampleReplayer
{
    /** @var array<string, string> example value => app value */
    public array $map = [];

    public function __construct(private readonly TestCase $test) {}

    public static function dir(): string
    {
        return base_path('../tools/themes/kit/api/examples');
    }

    /** @return array<string, mixed> */
    public static function load(string $name): array
    {
        $example = json_decode((string) file_get_contents(self::dir().'/'.$name.'.json'), true);
        Assert::assertIsArray($example, "example {$name} is JSON");

        return $example;
    }

    /**
     * @param  array<string, mixed>  $overrides  request parts to replace after substitution (path, headers, body, query)
     * @return array{0: TestResponse, 1: array<string, mixed>} [the app's answer, the example]
     */
    public function replay(string $name, array $overrides = []): array
    {
        $example = self::load($name);
        // `drop`: request keys the app does not take (removed from body and query at any depth, e.g. booking
        // `option_ids` — the booking engine has no service options yet); `expect`: known deviations of the answer
        // (dot path => the value the app gives instead), named in the test that uses them.
        $drop = (array) ($overrides['drop'] ?? []);
        $expect = (array) ($overrides['expect'] ?? []);
        unset($overrides['drop'], $overrides['expect']);
        $request = $this->substitute($example['request']);
        foreach ($overrides as $key => $value) {
            $request[$key] = $value;
        }
        if ($drop !== []) {
            foreach (['body', 'query'] as $part) {
                if (isset($request[$part]) && is_array($request[$part])) {
                    $request[$part] = self::without($request[$part], $drop);
                }
            }
        }
        $method = strtoupper((string) $request['method']);
        $path = (string) $request['path'];
        $query = (array) ($request['query'] ?? []);
        $headers = (array) ($request['headers'] ?? []);
        $body = $request['body'] ?? null;

        ApiContract::assertRequest($method, $path, $query, $headers, $body);

        $server = [];
        foreach ($headers as $header => $value) {
            $key = strtoupper(str_replace('-', '_', (string) $header));
            $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_'.$key] = (string) $value;
        }
        $uri = $path.($query !== [] ? '?'.http_build_query($query) : '');
        $content = $body === null ? null : (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $response = $this->test->call($method, $uri, [], [], [], $server, $content);

        $expected = (int) $example['response']['status'];
        Assert::assertSame($expected, $response->getStatusCode(), "{$name}: status\n".substr((string) $response->getContent(), 0, 3000));
        ApiContract::assertResponse((string) $example['operationId'], $method, $path, $response);

        foreach (['Cache-Control', 'Idempotent-Replayed', 'Deprecation', 'Sunset', 'Link', 'Retry-After', 'ETag'] as $header) {
            if (isset($example['response']['headers'][$header])) {
                Assert::assertTrue($response->headers->has($header), "{$name}: the answer carries {$header}");
            }
        }
        if (isset($example['response']['headers']['Cache-Control'])) {
            $want = (string) $example['response']['headers']['Cache-Control'];
            Assert::assertStringContainsString(explode(',', $want)[0], (string) $response->headers->get('Cache-Control'), "{$name}: Cache-Control family");
        }

        $want = (array) ($example['response']['body'] ?? []);
        foreach ($expect as $path => $value) {
            data_set($want, $path, $value);
        }
        $this->compare($name, $want, (array) (json_decode((string) $response->getContent(), true) ?? []));

        return [$response, $example];
    }

    /** @param  array<string, mixed>  $want  @param  array<string, mixed>  $got */
    private function compare(string $name, array $want, array $got): void
    {
        if (isset($want['code'])) {
            Assert::assertSame($want['code'], $got['code'] ?? null, "{$name}: error code");
            Assert::assertSame(array_keys((array) ($want['details'] ?? [])), array_keys((array) ($got['details'] ?? [])), "{$name}: error details keys");

            return;
        }
        if (isset($want['quote_hash'])) {
            $this->record($want['quote_hash'], $got['quote_hash'] ?? null);
            Assert::assertSame($want['quote']['total']['minor'], $got['quote']['total']['minor'] ?? null, "{$name}: quote total (design total)");
            Assert::assertSame(array_column($want['quote']['rows'], 'kind'), array_column($got['quote']['rows'] ?? [], 'kind'), "{$name}: quote rows");
            Assert::assertSame($want['requires'], $got['requires'] ?? null, "{$name}: requires");
            Assert::assertSame($want['payment_modes'], $got['payment_modes'] ?? null, "{$name}: payment modes");
            Assert::assertSame(array_column($want['warnings'], 'code'), array_column($got['warnings'] ?? [], 'code'), "{$name}: warnings");
        }
        if (isset($want['order']['track_token'])) {
            foreach (['public_id', 'number', 'track_token', 'track_url'] as $key) {
                $this->record($want['order'][$key] ?? null, $got['order'][$key] ?? null);
            }
            Assert::assertSame($want['order']['status'], $got['order']['status'] ?? null, "{$name}: order status");
        }
        if (isset($want['booking']['manage_token'])) {
            foreach (['public_id', 'number', 'manage_token', 'manage_url', 'ics_url'] as $key) {
                $this->record($want['booking'][$key] ?? null, $got['booking'][$key] ?? null);
            }
            Assert::assertSame($want['booking']['status'], $got['booking']['status'] ?? null, "{$name}: booking status");
            Assert::assertSame($want['booking']['reschedule_deadline_at'] === null, ($got['booking']['reschedule_deadline_at'] ?? null) === null, "{$name}: reschedule deadline");
        }
        if (isset($want['cancelled']['kind'])) {
            Assert::assertSame($want['cancelled']['kind'], $got['cancelled']['kind'] ?? null, "{$name}: cancelled kind");
            Assert::assertSame('cancelled', $got['cancelled']['status'] ?? null);
        }
        if (isset($want['payment'])) {
            Assert::assertSame($want['payment']['mode'], $got['payment']['mode'] ?? null, "{$name}: payment mode");
            Assert::assertSame($want['payment']['status'], $got['payment']['status'] ?? null, "{$name}: payment status");
            Assert::assertSame($want['payment']['next_action'] === null, ($got['payment']['next_action'] ?? null) === null, "{$name}: next_action");
        }
        if (array_key_exists('replayed', $want)) {
            Assert::assertSame($want['replayed'], $got['replayed'] ?? null, "{$name}: replayed");
        }
        if (isset($want['quote']['total']) && ! isset($want['quote_hash'])) {
            Assert::assertSame($want['quote']['total']['minor'], $got['quote']['total']['minor'] ?? null, "{$name}: order quote total");
        }
        if (isset($want['ok'])) { // legacy LegacyOrderCreated
            Assert::assertTrue($got['ok'] ?? false, "{$name}: ok");
            $this->record($want['track_token'], $got['track_token'] ?? null);
        }
        if (isset($want['message'], $want['expires_in_seconds'])) {
            Assert::assertSame($want['expires_in_seconds'], $got['expires_in_seconds'] ?? null);
        }
        if (isset($want['token_type'])) {
            Assert::assertSame('Bearer', $got['token_type'] ?? null);
            $this->record($want['token'], $got['token'] ?? null);
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $keys
     * @return array<array-key, mixed>
     */
    private static function without(array $data, array $keys): array
    {
        foreach ($data as $k => $v) {
            if (is_string($k) && in_array($k, $keys, true)) {
                unset($data[$k]);
            } elseif (is_array($v)) {
                $data[$k] = self::without($v, $keys);
            }
        }

        return $data;
    }

    public function record(mixed $exampleValue, mixed $appValue): void
    {
        if (is_string($exampleValue) && is_string($appValue) && $exampleValue !== '' && $exampleValue !== $appValue) {
            $this->map[$exampleValue] = $appValue;
        }
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function substitute(array $request): array
    {
        $json = (string) json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        // longest first, so a value never replaces part of a longer one
        $map = $this->map;
        uksort($map, fn ($a, $b) => strlen($b) <=> strlen($a));
        foreach ($map as $from => $to) {
            $json = str_replace($from, $to, $json);
        }

        return (array) json_decode($json, true);
    }
}
