<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Unit\HostedServices;

use Juwri\Core\Services\HostedServices\RequestSignature;
use PHPUnit\Framework\TestCase;

/** The one signing scheme of the juwri-hosted services (owner 2026-10-05): pure functions, both sides use it. */
class RequestSignatureTest extends TestCase
{
    private const SECRET = 'a3f1c0de5eedfacecafebabe0123456789abcdef0123456789abcdef01234567';

    public function test_the_canonical_string_covers_method_path_time_nonce_body_and_every_context_header(): void
    {
        $canonical = RequestSignature::canonical('post', '/api/hosted/v1/relay?x=1', '1790000000', 'nonce-0123456789ab', '{"a":1}', [
            RequestSignature::STORE => '12', RequestSignature::STORE_TYPE => 'retail', RequestSignature::BRANCH => '7',
            RequestSignature::REPORTED_STORES => '2', RequestSignature::REPORTED_BRANCHES => '4', 'X-Other' => 'ignored',
        ]);

        $this->assertSame(implode("\n", ['juwri-hosted-v1', 'POST', '/api/hosted/v1/relay?x=1', '1790000000', 'nonce-0123456789ab', hash('sha256', '{"a":1}'), '12', 'retail', '7', '2', '4']), $canonical);
        $this->assertSame(implode("\n", ['juwri-hosted-v1', 'GET', '/p', '1', 'n', hash('sha256', ''), '', '', '', '', '']), RequestSignature::canonical('GET', '/p', '1', 'n', '', []));
    }

    public function test_sign_and_match_constant_time_and_any_change_breaks_it(): void
    {
        $base = ['POST', '/api/hosted/v1/relay', '1790000000', 'nonce-0123456789ab', '{"a":1}', [RequestSignature::STORE => '12']];
        $signature = RequestSignature::sign(self::SECRET, RequestSignature::canonical(...$base));

        $this->assertMatchesRegularExpression('/^v1=[0-9a-f]{64}$/', $signature);
        $this->assertTrue(RequestSignature::matches(self::SECRET, RequestSignature::canonical(...$base), $signature));
        $this->assertTrue(RequestSignature::matches(self::SECRET, RequestSignature::canonical(...$base), " $signature "), 'surrounding blanks');
        $this->assertFalse(RequestSignature::matches(str_repeat('0', 64), RequestSignature::canonical(...$base), $signature), 'another secret');
        foreach ([0 => 'PUT', 1 => '/api/hosted/v1/other', 2 => '1790000001', 3 => 'nonce-0123456789ac', 4 => '{"a":2}', 5 => [RequestSignature::STORE => '13']] as $i => $changed) {
            $args = $base;
            $args[$i] = $changed;
            $this->assertFalse(RequestSignature::matches(self::SECRET, RequestSignature::canonical(...$args), $signature), "changed argument $i");
        }
        $this->assertFalse(RequestSignature::matches(self::SECRET, RequestSignature::canonical(...$base), strtoupper($signature)));
    }

    public function test_freshness_window(): void
    {
        $this->assertTrue(RequestSignature::fresh('1000', 1300));
        $this->assertTrue(RequestSignature::fresh('1300', 1000));
        $this->assertFalse(RequestSignature::fresh('1000', 1301));
        $this->assertFalse(RequestSignature::fresh('1301', 1000));
        $this->assertFalse(RequestSignature::fresh('', 1000));
        $this->assertFalse(RequestSignature::fresh('1e3', 1000));
        $this->assertFalse(RequestSignature::fresh('-5', 0));
        $this->assertTrue(RequestSignature::fresh('1000', 1060, 60));
    }

    public function test_headers_of_a_signed_request(): void
    {
        $headers = RequestSignature::headers('ci_01abc', self::SECRET, 'POST', '/api/hosted/v1/relay', '{}', [
            RequestSignature::STORE => 12, RequestSignature::STORE_TYPE => 'restaurant', RequestSignature::BRANCH => null,
            RequestSignature::REPORTED_STORES => 1, RequestSignature::REPORTED_BRANCHES => 3, 'X-Juwri-Edition' => 'enterprise',
        ], 1790000000, 'nonce-0123456789ab');

        $this->assertSame('ci_01abc', $headers[RequestSignature::INSTALL]);
        $this->assertSame('1790000000', $headers[RequestSignature::TIMESTAMP]);
        $this->assertSame('12', $headers[RequestSignature::STORE]);
        $this->assertArrayNotHasKey(RequestSignature::BRANCH, $headers, 'an absent value is not sent');
        $this->assertArrayNotHasKey('X-Juwri-Edition', $headers, 'there is no edition header: the service never takes one');
        $canonical = RequestSignature::canonical('POST', '/api/hosted/v1/relay', '1790000000', 'nonce-0123456789ab', '{}', $headers);
        $this->assertTrue(RequestSignature::matches(self::SECRET, $canonical, $headers[RequestSignature::SIGNATURE]), 'verifiable from the sent headers alone');
        $this->assertMatchesRegularExpression(RequestSignature::NONCE_PATTERN, RequestSignature::nonce());
        $this->assertNotSame(RequestSignature::nonce(), RequestSignature::nonce());
    }

    public function test_reference_pattern(): void
    {
        foreach (['12', 'ci_01j9zzzz', 'JUWRI-CHAIN-0005', 'b.2:x'] as $ok) {
            $this->assertMatchesRegularExpression(RequestSignature::REF_PATTERN, $ok);
        }
        foreach (['', ' 12', "12\n", 'a b', str_repeat('a', 65), '-12', "x\ny"] as $bad) {
            $this->assertDoesNotMatchRegularExpression(RequestSignature::REF_PATTERN, $bad);
        }
    }
}
