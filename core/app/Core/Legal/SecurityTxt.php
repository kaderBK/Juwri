<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Legal;

use Carbon\CarbonImmutable;

/**
 * P1-23: /.well-known/security.txt (RFC 9116) from security.md §9 and config/legal.php `security_txt`. URLs are this
 * instance's own (APP_URL); Encryption / Acknowledgments are left out until they exist (a pgp-key.txt file, an
 * acknowledgments page) so the file never points at a missing resource.
 */
final class SecurityTxt
{
    public static function render(): string
    {
        $cfg = (array) config('legal.security_txt', []);
        $lines = [];
        foreach ((array) ($cfg['contact'] ?? []) as $contact) {
            $lines[] = 'Contact: '.$contact;
        }
        if (($report = SecurityReport::url()) !== null) {
            $lines[] = 'Contact: '.$report;
        }
        $lines[] = 'Contact: '.url('/'.self::path('security'));
        $lines[] = 'Expires: '.self::expires()->format('Y-m-d\TH:i:s.v\Z');
        $encryption = trim((string) ($cfg['encryption'] ?? ''));
        if ($encryption === '' && self::pgpKeyFile() !== null) {
            $encryption = url('/.well-known/pgp-key.txt');
        }
        if ($encryption !== '') {
            $lines[] = 'Encryption: '.$encryption;
        }
        $lines[] = 'Policy: '.url('/'.self::path('security'));
        if (($ack = trim((string) ($cfg['acknowledgments'] ?? ''))) !== '') {
            $lines[] = 'Acknowledgments: '.$ack;
        }
        $lines[] = 'Preferred-Languages: '.($cfg['preferred_languages'] ?? 'en');
        $lines[] = 'Canonical: '.url('/.well-known/security.txt');

        return implode("\n", $lines)."\n";
    }

    public static function expires(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) config('legal.security_txt.expires', '1970-01-01T00:00:00Z'))->utc();
    }

    /** The armoured public key file, when configured and present. */
    public static function pgpKeyFile(): ?string
    {
        $path = trim((string) config('legal.security_txt.pgp_key_path', ''));
        if ($path === '') {
            return null;
        }
        if (! str_starts_with($path, '/')) {
            $path = storage_path('app/'.$path);
        }

        return is_file($path) ? $path : null;
    }

    private static function path(string $document): string
    {
        return LegalDocuments::all()[$document]['path'] ?? $document;
    }
}
