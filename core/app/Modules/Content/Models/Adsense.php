<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Adsense extends Model
{
    use HasFactory;

    public $table = 'ad_slots';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'provider_name',
        'slug',
        'code',
        'status',
    ];
}
