<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Language extends Model
{
    use HasFactory;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'code',
        'direction',
        'active',
    ];

    /**
     * P1: flush the per-request language memos (current_language / get_active_languages
     * in Helper.php) whenever a language row is written, so an edit is never stale.
     */
    protected static function booted(): void
    {
        $flush = static function () {
            if (app()->bound('memo.current_language')) {
                app()->forgetInstance('memo.current_language');
            }
            if (app()->bound('memo.active_languages')) {
                app()->forgetInstance('memo.active_languages');
            }
        };

        static::saved($flush);
        static::deleted($flush);
    }
}
