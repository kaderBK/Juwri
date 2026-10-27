<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;

class Campaign extends Model
{
    use BelongsToAccount;
    use HasFactory;

    protected $fillable = [
        'store_id',
        'channel',
        'subject',
        'body',
        'segment',
        'status',
        'scheduled_at',
        'sent_count',
        'failed_count',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'sent_count' => 'integer',
        'failed_count' => 'integer',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function recipients()
    {
        return $this->hasMany(CampaignRecipient::class);
    }
}
