<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

class ImageMenu extends Model
{
    use BelongsToAccount;
    use HasFactory;

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * A deleted picture-menu page takes its uploaded image and the responsive
     * `-w900.webp` variant (FP B6) with it; the shared default.png stays. Runs
     * on `deleted` so this row no longer counts as a reference — see Menu.
     */
    protected static function booted(): void
    {
        static::deleted(function (self $page): void {
            $image = $page->image;
            if (empty($image) || $image === 'default.png') {
                return;
            }
            foreach ([$image, pathinfo((string) $image, PATHINFO_FILENAME).'-w900.webp'] as $file) {
                remove_file('storage/menu/', $file, $page->store_id ?? null);
            }
        });
    }
}
