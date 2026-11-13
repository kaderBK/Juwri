<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Media\Contracts;

use Juwri\Modules\Media\Models\MediaAsset;
use Juwri\Modules\Media\Services\MediaCopyService;

/**
 * P1-08 L1 public surface: copy an image into an owner's media library (quota enforced). Implemented by
 * {@see MediaCopyService}; failures throw a {@see MediaCopyFailed}.
 */
interface MediaCopier
{
    /** @param  array<string, mixed>  $meta */
    public function fromLocalFile(string $absolutePath, int $storeId, array $meta = []): ?MediaAsset;

    /** @param  array<string, mixed>  $meta */
    public function fromUrl(string $url, int $storeId, array $meta = []): ?MediaAsset;

    /** @param  array<string, mixed>  $meta */
    public function fromBytes(string $bytes, int $storeId, array $meta = []): ?MediaAsset;
}
