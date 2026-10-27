<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Customers\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use App\Models\Customer;

class CustomerToken extends Model
{
    use BelongsToAccount;

    protected $guarded = [];
    protected $hidden = ['token'];
    protected $casts = [
        'last_used_at' => 'datetime',
        'expires_at'   => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Issue a new token for a customer and return the raw value (shown once).
     */
    public static function issue(Customer $customer, ?string $name = null, ?int $expiresDays = null): string
    {
        $raw = Str::random(48);

        static::create([
            'customer_id' => $customer->id,
            'name'        => $name,
            'token'       => hash('sha256', $raw),
            'expires_at'  => $expiresDays ? now()->addDays($expiresDays) : null,
        ]);

        return $raw;
    }
}
