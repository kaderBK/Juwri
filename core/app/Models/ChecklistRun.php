<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/** SYSTEMS-RUN T-P2: a dated instance of a checklist template. */
class ChecklistRun extends Model
{
    use BelongsToAccount;

    protected $guarded = [];
    protected $casts = ['date' => 'date', 'item_states' => 'array'];

    public function template()
    {
        return $this->belongsTo(ChecklistTemplate::class, 'checklist_template_id');
    }

    public function progress(): int
    {
        $states = (array) ($this->item_states ?? []);
        if (empty($states)) {
            return 0;
        }
        $done = count(array_filter($states, fn ($s) => !empty($s['done'])));
        return (int) round($done / count($states) * 100);
    }
}
