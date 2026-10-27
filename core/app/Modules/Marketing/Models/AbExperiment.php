<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Model;

class AbExperiment extends Model
{
    protected $guarded = [];
    protected $table   = 'ab_experiments';
    protected $casts   = [
        'variants'   => 'array',
        'started_at' => 'datetime',
        'ended_at'   => 'datetime',
    ];

    public function impressions()
    {
        return $this->hasMany(AbImpression::class, 'experiment_id');
    }

    // Per-variant stats: total impressions + conversions
    public function stats(): array
    {
        return $this->impressions()
            ->selectRaw('variant, COUNT(*) as impressions, COUNT(converted_at) as conversions')
            ->groupBy('variant')
            ->get()
            ->keyBy('variant')
            ->toArray();
    }
}
