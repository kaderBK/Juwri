<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Messaging\Contracts\MobilePush;

class Driver extends Model
{
    protected static function booted(): void
    {
        // P6-269: a rotated token (new sign-in, owner rotation), a deactivation or a
        // removal stops push to the driver's old device.
        static::saved(function (self $driver) {
            // wasChanged() is only filled by an UPDATE (an insert leaves it empty);
            // wasRecentlyCreated stays true on the instance, so it is no guard.
            if ($driver->wasChanged('token') || ($driver->wasChanged('active') && ! $driver->active)) {
                app(MobilePush::class)->revokeDevices(['driver_id' => (int) $driver->id]);
            }
        });
        static::deleted(function (self $driver) {
            app(MobilePush::class)->revokeDevices(['driver_id' => (int) $driver->id]);
        });
    }

    use BelongsToAccount;

    protected $guarded = [];

    protected $hidden = ['token'];

    protected $casts = [
        'active' => 'boolean',
        'is_fleet' => 'boolean',
        'capacity' => 'integer',
        'active_load' => 'integer',
        'last_lat' => 'float',
        'last_lng' => 'float',
        'last_location_at' => 'datetime',
    ];

    /** Marketplace fleet drivers (platform-owned, not store-owned). */
    public function scopeFleet($query)
    {
        return $query->where('is_fleet', true);
    }

    /** Drivers currently on shift. */
    public function scopeOnline($query)
    {
        return $query->where('shift_status', 'online');
    }

    /** Fleet drivers registered to a city. */
    public function scopeInCity($query, $cityId)
    {
        return $query->where('city_id', $cityId);
    }

    /** Online, active and with spare delivery capacity. */
    public function isAvailable(): bool
    {
        return $this->active
            && $this->shift_status === 'online'
            && (int) $this->active_load < (int) $this->capacity;
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'driver_id');
    }

    /** Public shape returned by the API (never leaks the token). */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'store_id' => $this->store_id,
            'active' => (bool) $this->active,
            'last_lat' => $this->last_lat,
            'last_lng' => $this->last_lng,
            'last_location_at' => $this->last_location_at,
            'created_at' => $this->created_at,
        ];
    }
}
