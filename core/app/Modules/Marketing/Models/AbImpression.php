<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Model;

class AbImpression extends Model
{
    public $timestamps  = false;
    protected $guarded  = [];
    protected $table    = 'ab_impressions';
    protected $casts    = [
        'converted_at' => 'datetime',
        'created_at'   => 'datetime',
    ];

    public function experiment()
    {
        return $this->belongsTo(AbExperiment::class, 'experiment_id');
    }
}
