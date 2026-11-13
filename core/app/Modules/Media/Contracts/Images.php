<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Media\Contracts;

use Juwri\Modules\Media\Services\StorageService;
use Juwri\Modules\Media\Services\UploadGuard;

/**
 * P1-08 L1 public surface: image storage helpers and upload limits for modules above Media (Menu). Same semantics as
 * {@see StorageService} / {@see UploadGuard}.
 */
interface Images
{
    /** Convert a stored image to WebP; the new file name or null (StorageService::convertToWebp). */
    public function convertToWebp(string $path, string $filename, ?int $storeId = null): ?string;

    /** Write a thumbnail of an upload; the thumb file name or null (StorageService::thumbnail). */
    public function thumbnail(mixed $file, string $path, string $filename, string $size = '200', ?int $storeId = null): ?string;

    /** Whether an uploaded image's pixel size is acceptable (UploadGuard::pixelsOk). */
    public function uploadPixelsOk(int $w, int $h): bool;

    /** Most pages a menu scan may have (UploadGuard::maxScanPages). */
    public function maxScanPages(): int;
}
