<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Bookings\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Concerns\StoresUtc;

/**
 * P3-10: a person customers can book (a stylist, a therapist, a technician). Not a login: staff accounts stay in
 * Team; a booking staff member only needs a name, an optional role and photo, and the services they do.
 *
 * @property int $id
 * @property string|null $public_id
 * @property int $store_id
 * @property string $name
 * @property string|null $role
 * @property string|null $photo_url
 * @property bool $active
 * @property int $position
 */
class BookingStaff extends Model
{
    use BelongsToAccount;
    use StoresUtc;
    use HasUuids;

    protected $table = 'booking_staff';

    protected $guarded = ['id', 'account_id'];

    protected $casts = ['active' => 'boolean', 'position' => 'integer', 'store_id' => 'integer'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** @return BelongsToMany<BookableService, $this> */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(BookableService::class, 'booking_staff_services', 'staff_id', 'service_id');
    }

    /** @return HasMany<BookingHour, $this> */
    public function hours(): HasMany
    {
        return $this->hasMany(BookingHour::class, 'staff_id');
    }
}
