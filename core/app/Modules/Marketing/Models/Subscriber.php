<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subscriber extends Model
{
    use HasFactory;

    public $table = 'newsletter_subscribers';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'email',
        'joined',
    ];
}
