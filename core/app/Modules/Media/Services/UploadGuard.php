<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Media\Services;

use Juwri\Core\Services\AbuseGuard;

/**
 * Abuse Guard P5 (5.3): pure upload-hardening helper.
 *
 * Stateless static predicates the upload/scan pipelines can consult before
 * accepting a file. Every limit is sourced through AbuseGuard::value() so it
 * flows from the Options system (config('settings.abuse_upload_*') /
 * config('settings.abuse_scan_*')) with a safe code default. No wiring here —
 * the caller decides what to do with a false/true.
 */
class UploadGuard
{
    /** Max allowed upload size in megabytes. */
    public static function maxMb(): int
    {
        return (int) AbuseGuard::value('upload', 'max_mb', 8);
    }

    /** Max allowed decoded image pixel count (w*h). */
    public static function maxPixels(): int
    {
        return (int) AbuseGuard::value('upload', 'max_pixels', 25000000);
    }

    /** Max allowed pages for a scan/import document. */
    public static function maxScanPages(): int
    {
        return (int) AbuseGuard::value('scan', 'max_pages', 30);
    }

    /** True when a byte size is within the configured MB cap. */
    public static function sizeOk(int $bytes): bool
    {
        return $bytes <= self::maxMb() * 1024 * 1024;
    }

    /** True when width*height is within the configured pixel cap. */
    public static function pixelsOk(int $w, int $h): bool
    {
        return $w * $h <= self::maxPixels();
    }

    /** True when the mime type is in the caller-supplied allow list. */
    public static function mimeAllowed(string $mime, array $allowed): bool
    {
        return in_array($mime, $allowed, true);
    }

    /** True when a page count is within the configured scan-page cap. */
    public static function pagesOk(int $pages): bool
    {
        return $pages <= self::maxScanPages();
    }

    /** Default image mime allow-list for uploads. */
    public const IMAGE_MIMES = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif',
        // Logos/favicons accept vector + icon containers too; SVG is sanitised in
        // StorageService::sanitizeSvg() before it is written to a public path.
        'image/svg+xml', 'image/avif', 'image/bmp', 'image/x-ms-bmp',
        'image/x-icon', 'image/vnd.microsoft.icon',
    ];

    /**
     * Gate one uploaded file through the Abuse Guard. Runs mime-sniff + size +
     * pixel-budget checks and routes any failure through AbuseGuard::hit('upload',…):
     * WARN logs the would-be reject and allows, ENFORCE denies (returns false).
     * Returns TRUE = accept the file.
     */
    public static function allow($file, ?int $ownerId = null, array $mimes = self::IMAGE_MIMES): bool
    {
        if (AbuseGuard::mode('upload') === AbuseGuard::OFF || ! $file) {
            return true;
        }

        $reason = null;
        $meta = [];

        $mime = method_exists($file, 'getMimeType') ? (string) $file->getMimeType() : '';
        $size = method_exists($file, 'getSize') ? (int) $file->getSize() : 0;
        $path = method_exists($file, 'getRealPath') ? (string) $file->getRealPath() : '';

        if ($mime !== '' && ! self::mimeAllowed($mime, $mimes)) {
            $reason = 'mime';
            $meta = ['mime' => $mime];
        } elseif ($size > 0 && ! self::sizeOk($size)) {
            $reason = 'size';
            $meta = ['bytes' => $size, 'max_mb' => self::maxMb()];
        } elseif ($path !== '' && ! self::imageWithinPixels($path)) {
            $reason = 'pixels';
            $meta = ['max_pixels' => self::maxPixels()];
        }

        if ($reason === null) {
            return true;
        }

        return AbuseGuard::hit('upload', 'reject_'.$reason, ['user_id' => $ownerId, 'meta' => $meta]);
    }

    /**
     * Decode $path with getimagesize and check its pixel budget.
     * Fail-open: returns true if the file is missing or undecodable so the
     * caller — not this helper — decides how to treat a read error.
     */
    public static function imageWithinPixels(string $path): bool
    {
        if ($path === '' || ! @is_file($path)) {
            return true;
        }

        $info = @getimagesize($path);

        if ($info === false || ! isset($info[0], $info[1])) {
            return true;
        }

        return self::pixelsOk((int) $info[0], (int) $info[1]);
    }
}
