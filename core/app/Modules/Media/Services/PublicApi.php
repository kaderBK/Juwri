<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Media\Services;

use Juwri\Modules\Media\Contracts\Images;
use Juwri\Modules\Media\Contracts\StorageQuotas;

/**
 * P1-08 L1 public surface: Media's implementation of its static-service contracts ({@see StorageQuotas},
 * {@see Images}). Pure delegation — no behaviour of its own.
 */
final class PublicApi implements Images, StorageQuotas
{
    public function all(): array
    {
        return StorageQuota::all();
    }

    public function add(int $userId, int $bytes): void
    {
        StorageQuota::add($userId, $bytes);
    }

    public function sub(int $userId, int $bytes): void
    {
        StorageQuota::sub($userId, $bytes);
    }

    public function allow(int $userId, int $incomingBytes): bool
    {
        return StorageQuota::allow($userId, $incomingBytes);
    }

    public function convertToWebp(string $path, string $filename, ?int $storeId = null): ?string
    {
        return StorageService::convertToWebp($path, $filename, $storeId);
    }

    public function thumbnail(mixed $file, string $path, string $filename, string $size = '200', ?int $storeId = null): ?string
    {
        return StorageService::thumbnail($file, $path, $filename, $size, $storeId);
    }

    public function uploadPixelsOk(int $w, int $h): bool
    {
        return UploadGuard::pixelsOk($w, $h);
    }

    public function maxScanPages(): int
    {
        return UploadGuard::maxScanPages();
    }
}
