<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;

class ActivityLog extends Model
{
    use BelongsToAccount;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    // Resolve subject polymorphically without a formal relation
    public function getSubjectAttribute()
    {
        if (! $this->subject_type || ! $this->subject_id) {
            return null;
        }

        return $this->subject_type::find($this->subject_id);
    }

    // Human-readable action label
    public function getActionLabelAttribute(): string
    {
        $map = [
            'menu.create' => ___('Created menu item'),
            'menu.update' => ___('Updated menu item'),
            'menu.delete' => ___('Deleted menu item'),
            'menu.bulk' => ___('Bulk action on items'),
            'category.create' => ___('Created category'),
            'category.update' => ___('Updated category'),
            'category.delete' => ___('Deleted category'),
            'customer.ltv_recalculated' => ___('Recalculated customer lifetime value'),
            'customer.data_export' => ___('A customer downloaded their data'),
            'customer.self_erased' => ___('A customer deleted their account'),
            'store.update' => ___('Updated restaurant settings'),
            'store.clone' => ___('Cloned restaurant'),
            'store.platform_restore' => ___('Store restored from a platform backup'),
            'order.status' => ___('Changed order status'),
            'order.delete' => ___('Deleted order'),
            'review.update' => ___('Moderated review'),
            'coupon.create' => ___('Created coupon'),
            'coupon.delete' => ___('Deleted coupon'),
            'csv.import' => ___('Imported menu CSV'),
            'csv.export' => ___('Exported menu CSV'),
        ];

        return $map[$this->action] ?? ___(ucfirst(str_replace(['.', '_'], ' ', $this->action)));
    }

    public function scopeForOwner($query, $userId)
    {
        return $query->where('actor_user_id', $userId);
    }

    public function scopeForRestaurant($query, $storeId)
    {
        return $query->where('store_id', $storeId);
    }
}
