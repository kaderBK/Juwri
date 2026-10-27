<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Unit\Support\Mail;

use App\Support\Mail\SmtpFailureReason;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * P6-176: SmtpFailureReason maps the wild text of a Symfony Mailer exception
 * into a short, owner-facing message and a stable code. The password string,
 * the username and the raw exception message must never leak.
 */
class SmtpFailureReasonTest extends TestCase
{
    /**
     * Real-world exception messages straight out of Symfony Mailer 8.x.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function cases(): array
    {
        return [
            'wrong password (full chain)' => [
                'Failed to authenticate on SMTP server with username "x" using the following authenticators: "LOGIN". Authenticator "LOGIN" returned "Expected response code "235" but got code "535", with message "535 5.7.8 Authentication failed".".',
                'auth_failed',
            ],
            'connection refused' => [
                'Connection could not be established with host "smtp.example.com:587": stream_socket_client(): Unable to connect to smtp.example.com:587 (Connection refused)',
                'connection_refused',
            ],
            'connection timeout' => [
                'Connection to "smtp.example.com:587" timed out.',
                'timeout',
            ],
            'tls wrong version' => [
                'Connection could not be established with host "ssl://smtp.example.com:465": stream_socket_client(): SSL operation failed with code 1. OpenSSL Error messages: error:0A00010B:SSL routines::wrong version number',
                'tls',
            ],
            'dns getaddrinfo' => [
                'php_network_getaddresses: getaddrinfo for nope.invalid failed: Name or service not known',
                'dns',
            ],
            'something else entirely' => [
                'Some other unrelated transport error.',
                'other',
            ],
        ];
    }

    #[DataProvider('cases')]
    public function test_classifies_real_symfony_mailer_messages(string $message, ?string $expectedCode): void
    {
        $result = SmtpFailureReason::classify(new RuntimeException($message), 'smtp.example.com', 587);
        $this->assertSame($expectedCode, $result['code']);
        $this->assertNotEmpty($result['message']);
        $this->assertStringNotContainsString($message, $result['message'], 'panel must never echo the raw exception text');
    }

    public function test_codes_lists_all_six(): void
    {
        $codes = SmtpFailureReason::codes();
        $this->assertCount(6, $codes);
        $this->assertContains('auth_failed', $codes);
        $this->assertContains('dns', $codes);
        $this->assertContains('tls', $codes);
        $this->assertContains('connection_refused', $codes);
        $this->assertContains('timeout', $codes);
        $this->assertContains('other', $codes);
    }

    public function test_dns_message_includes_the_host(): void
    {
        $result = SmtpFailureReason::classify(
            new RuntimeException('php_network_getaddresses: getaddrinfo for nope.invalid failed'),
            'nope.invalid',
            587
        );
        $this->assertSame('dns', $result['code']);
        $this->assertStringContainsString('nope.invalid', $result['message']);
    }

    public function test_connection_refused_message_includes_host_and_port_with_587_hint(): void
    {
        $result = SmtpFailureReason::classify(
            new RuntimeException('Connection refused'),
            'smtp.example.com',
            587
        );
        $this->assertSame('connection_refused', $result['code']);
        $this->assertStringContainsString('smtp.example.com', $result['message']);
        $this->assertStringContainsString('587', $result['message']);
        $this->assertStringContainsString('465', $result['message']);
    }

    public function test_timeout_message_includes_host_and_port_with_465_hint_for_465(): void
    {
        $result = SmtpFailureReason::classify(
            new RuntimeException('Connection timed out'),
            'smtp.example.com',
            465
        );
        $this->assertSame('timeout', $result['code']);
        $this->assertStringContainsString('smtp.example.com', $result['message']);
        $this->assertStringContainsString('465', $result['message']);
        $this->assertStringContainsString('587', $result['message']);
    }

    public function test_null_host_and_port_render_as_question_marks(): void
    {
        $result = SmtpFailureReason::classify(
            new RuntimeException('Connection refused'),
            null,
            null
        );
        $this->assertSame('connection_refused', $result['code']);
        $this->assertStringContainsString('?', $result['message']);
    }

    public function test_password_in_exception_message_is_never_echoed_back(): void
    {
        $sensitive = 'S3cret-Pw';
        $exceptionMessage = "Connection refused — user sent password \"$sensitive\" and host answered nothing";
        $result = SmtpFailureReason::classify(new RuntimeException($exceptionMessage), 'smtp.example.com', 587);
        $this->assertStringNotContainsString($sensitive, $result['message']);
    }

    public function test_walks_the_previous_chain_to_find_dns(): void
    {
        // Outer exception is opaque; only the chained previous carries the DNS hint.
        $previous = new RuntimeException('php_network_getaddresses: getaddrinfo for nope.invalid failed');
        $outer = new RuntimeException('Some wrapper around the previous failure', 0, $previous);
        $result = SmtpFailureReason::classify($outer, 'nope.invalid', 587);
        $this->assertSame('dns', $result['code']);
    }

    public function test_walks_the_previous_chain_to_find_auth(): void
    {
        $previous = new RuntimeException('535 5.7.8 Authentication failed');
        $outer = new RuntimeException('Sent message failed', 0, $previous);
        $result = SmtpFailureReason::classify($outer, 'smtp.example.com', 587);
        $this->assertSame('auth_failed', $result['code']);
    }

    public function test_walks_the_previous_chain_to_find_connection_refused(): void
    {
        $previous = new RuntimeException('Connection refused (errno=111)');
        $outer = new RuntimeException('Outer failure', 0, $previous);
        $result = SmtpFailureReason::classify($outer, 'smtp.example.com', 587);
        $this->assertSame('connection_refused', $result['code']);
        $this->assertStringContainsString('smtp.example.com', $result['message']);
    }

    public function test_refused_on_an_implicit_tls_url_is_not_called_a_tls_error(): void
    {
        // "ssl://" is in the message of a plain refusal on port 465, and host names may contain "ssl" or "tls".
        $result = SmtpFailureReason::classify(
            new RuntimeException('Connection could not be established with host "ssl://smtp-tls.example.com:465": stream_socket_client(): Unable to connect to ssl://smtp-tls.example.com:465 (Connection refused)'),
            'smtp-tls.example.com',
            465
        );
        $this->assertSame('connection_refused', $result['code']);
        $this->assertStringContainsString('587', $result['message']);
    }

    public function test_a_reset_during_the_secure_handshake_is_a_tls_error(): void
    {
        $result = SmtpFailureReason::classify(
            new RuntimeException('Connection could not be established with host "ssl://127.0.0.1:587": stream_socket_client(): SSL: Connection reset by peer'),
            '127.0.0.1',
            587
        );
        $this->assertSame('tls', $result['code']);
    }

    public function test_a_host_name_containing_tls_alone_is_not_a_tls_error(): void
    {
        $result = SmtpFailureReason::classify(new RuntimeException('Connection to "tls.example.com:587" timed out.'), 'tls.example.com', 587);
        $this->assertSame('timeout', $result['code']);
    }
}
