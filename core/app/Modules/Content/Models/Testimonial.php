<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Testimonial extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'designation',
        'content',
        'image',
        'translations',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'translations' => 'object',
    ];
}
