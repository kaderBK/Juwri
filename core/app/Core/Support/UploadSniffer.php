<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Core\Support;

/**
 * P6-78: content checks for uploads that the client extension cannot be trusted for. Every check reads the real
 * bytes; callers store under a server-chosen extension returned here, never the client's.
 */
final class UploadSniffer
{
    /** @return string|null 'woff2' when the bytes are a WOFF2 font, else null (only woff2 is accepted by the menu font). */
    public static function font(string $bytes): ?string
    {
        return str_starts_with($bytes, 'wOF2') ? 'woff2' : null;
    }

    /** @return string|null the safe extension for a licence scan (pdf, jpg, png, webp) from its real content type. */
    public static function licenceExtension(string $path): ?string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($path);

        return match ($mime) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => null,
        };
    }

    /** True when the bytes start with the ZIP local-file signature (an .xlsx is a ZIP). */
    public static function isZip(string $bytes): bool
    {
        return str_starts_with($bytes, "PK\x03\x04");
    }

    /** True when the bytes look like text: no NUL byte, and not starting with a ZIP/PDF/PE/ELF signature. */
    public static function isPlainText(string $bytes): bool
    {
        if (str_contains($bytes, "\0")) {
            return false;
        }

        return ! preg_match('/\A(PK\x03\x04|%PDF|MZ|\x7fELF|GIF8|\x89PNG|\xFF\xD8\xFF)/', $bytes);
    }
}
