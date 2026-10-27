<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Controllers\Admin\Concerns;

use Illuminate\Http\UploadedFile;

/**
 * One picture per record, stored under a public folder with a shared
 * placeholder file name (e.g. `default.png`) that must never be deleted.
 */
trait KeepsPanelImage
{
    /**
     * Save a new upload (removing the record's previous own file) or keep what
     * the record already has; a record without a picture gets the placeholder.
     */
    protected function panelImage(?UploadedFile $upload, string $folder, string $size, ?string $current, string $placeholder): string
    {
        if ($upload === null) {
            return $current ?: $placeholder;
        }

        $previous = ($current && $current !== $placeholder) ? $current : null;

        return image_upload($upload, $folder, $size, null, $previous);
    }

    protected function dropPanelImage(?string $file, string $folder, string $placeholder): void
    {
        if ($file && $file !== $placeholder) {
            remove_file($folder.$file);
        }
    }
}
