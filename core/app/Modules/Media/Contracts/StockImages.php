<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Media\Contracts;

use Juwri\Modules\Media\Services\StockImageService;

/**
 * P1-08 L1 public surface: stock-photo search (Pexels/Unsplash/Google). Implemented by
 * {@see StockImageService}.
 */
interface StockImages
{
    /** Providers whose results are unlicensed web images (BYOK-only). */
    public const UNSAFE_PROVIDERS = ['google_cse'];

    public function isSupported(string $provider): bool;

    /** @return array<int|string, mixed> */
    public function availableProviders(?int $storeId = null): array;

    /** @return array<int|string, mixed> */
    public function search(string $provider, string $query, int $page = 1, ?int $storeId = null): array;

    /** Tell the provider an image was used (Unsplash's download ping); no-op for providers without one. */
    public function triggerDownload(string $provider, ?string $downloadLocation, ?int $storeId = null): void;
}
