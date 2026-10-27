<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Customers\Models;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;

/** SYSTEMS-RUN S-P1: a customer feedback request + response. */
class CustomerFeedback extends Model
{
    use BelongsToAccount;

    protected $table = 'customer_feedback';

    protected $guarded = [];

    protected $casts = ['answers' => 'array', 'submitted_at' => 'datetime'];

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}
