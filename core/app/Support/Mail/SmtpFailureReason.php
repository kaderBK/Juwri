<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Support\Mail;

use Throwable;

/**
 * P6-176: classify a SMTP test-mail failure into a short, owner-facing reason
 * (without leaking credentials or the raw exception text) and return the
 * friendly message. The full exception is reported via Laravel's exception
 * handler — the panel only ever sees the message below.
 */
final class SmtpFailureReason
{
    public const AUTH_FAILED = 'auth_failed';

    public const DNS = 'dns';

    public const TLS = 'tls';

    public const CONNECTION_REFUSED = 'connection_refused';

    public const TIMEOUT = 'timeout';

    public const OTHER = 'other';

    /**
     * Walk the whole previous-exception chain, join every message, lower-case
     * it, then match against known SMTP failure shapes (in priority order).
     *
     * @return array{code: string, message: string}
     */
    public static function classify(Throwable $e, ?string $host = null, ?int $port = null): array
    {
        $blob = self::collectMessages($e);
        $host = ($host === null || $host === '') ? '?' : $host;
        $port = $port ?? '?';

        if (self::matchesAuth($blob)) {
            return ['code' => self::AUTH_FAILED, 'message' => ___('The mail server did not accept the user name or password. Check the Username and Password fields.')];
        }
        if (self::matchesDns($blob)) {
            return ['code' => self::DNS, 'message' => ___('The host name :host was not found. Check the Host field for typing mistakes.', ['host' => $host])];
        }
        // Refused is checked before TLS: an implicit-TLS URL such as "ssl://host:465" appears in the message of a plain
        // "Connection refused", and a host name may itself contain "ssl" or "tls".
        if (self::matchesConnectionRefused($blob)) {
            return ['code' => self::CONNECTION_REFUSED, 'message' => self::connectionRefusedMessage($host, $port)];
        }
        if (self::matchesTls($blob)) {
            return ['code' => self::TLS, 'message' => ___('The secure connection (TLS/SSL) could not be set up. Check that Encryption matches the port: TLS for port 587, SSL for port 465.')];
        }
        if (self::matchesTimeout($blob)) {
            return ['code' => self::TIMEOUT, 'message' => self::timeoutMessage($host, $port)];
        }

        return ['code' => self::OTHER, 'message' => ___('The mail server returned an error. Check all the mail settings and try again.')];
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return [self::AUTH_FAILED, self::DNS, self::TLS, self::CONNECTION_REFUSED, self::TIMEOUT, self::OTHER];
    }

    /** Walk the previous chain and concatenate every message (lowercased). */
    private static function collectMessages(Throwable $e): string
    {
        $parts = [];
        $current = $e;
        $seen = [];
        while ($current !== null && ! isset($seen[spl_object_hash($current)])) {
            $seen[spl_object_hash($current)] = true;
            $parts[] = (string) $current->getMessage();
            $current = $current->getPrevious();
        }

        return strtolower(implode("\n", $parts));
    }

    private static function matchesAuth(string $blob): bool
    {
        if (str_contains($blob, 'failed to authenticate')
            || str_contains($blob, 'authentication failed')
            || str_contains($blob, 'code "535"')
            || str_contains($blob, '535 ')
            || str_contains($blob, 'username and password not accepted')
            || str_contains($blob, 'bad credentials')) {
            return true;
        }
        if (str_contains($blob, 'authenticator')) {
            foreach (['535', '534', '530'] as $needle) {
                if (str_contains($blob, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function matchesDns(string $blob): bool
    {
        foreach (['getaddrinfo', 'name or service not known', 'nodename nor servname',
            'php_network_getaddresses', 'could not resolve',
            'temporary failure in name resolution', 'no such host'] as $needle) {
            if (str_contains($blob, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function matchesTls(string $blob): bool
    {
        // Specific phrases only: a bare "ssl"/"tls" would also match a host name like "smtp-tls.example.com".
        foreach (['stream_socket_enable_crypto', 'ssl operation failed', 'ssl routines', 'ssl: ', 'openssl',
            'certificate', 'wrong version number', 'handshake', 'unexpected eof', 'connection reset',
            'unable to complete the tls', 'tls negotiation', 'starttls'] as $needle) {
            if (str_contains($blob, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function matchesConnectionRefused(string $blob): bool
    {
        return str_contains($blob, 'connection refused')
            || str_contains($blob, 'errno=111')
            || str_contains($blob, '(111)');
    }

    private static function matchesTimeout(string $blob): bool
    {
        if (str_contains($blob, 'timed out') || str_contains($blob, 'timeout')) {
            return true;
        }
        if (str_contains($blob, 'errno=110') || str_contains($blob, '(110)')) {
            return true;
        }

        return str_contains($blob, 'connection could not be established');
    }

    private static function connectionRefusedMessage(string $host, int|string $port): string
    {
        $base = ___('The connection to :host on port :port was refused. The port may be blocked by your host, or the port number is wrong.', ['host' => $host, 'port' => (string) $port]);

        return $base.' '.self::portHint((int) $port);
    }

    private static function timeoutMessage(string $host, int|string $port): string
    {
        $base = ___('The mail server at :host did not answer in time. Your host may block outgoing mail on port :port.', ['host' => $host, 'port' => (string) $port]);

        return $base.' '.self::portHint((int) $port);
    }

    private static function portHint(int $port): string
    {
        if ($port === 587) {
            return ___('Port 587 looks blocked by your host. Try port 465 with SSL encryption.');
        }
        if ($port === 465) {
            return ___('Port 465 looks blocked by your host. Try port 587 with TLS encryption.');
        }

        return '';
    }
}
