<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;
use Juwri\Modules\Tables\Models\TableMergeGroup;
use Juwri\Modules\Tables\Models\TableMergeMember;
use Juwri\Modules\Tables\Models\TableSession;
use Juwri\Modules\Tables\Services\TableSessionService;

/**
 * Hotel dynamic relations (P1-13, registered by the Hotel module):
 *
 * @method \Illuminate\Database\Eloquent\Relations\HasMany<\Illuminate\Database\Eloquent\Model, $this> stays() stays that used this row as a room
 * @method \Illuminate\Database\Eloquent\Relations\HasOne<\Illuminate\Database\Eloquent\Model, $this> activeStay() the checked-in stay on this room
 * @method \Illuminate\Database\Eloquent\Relations\BelongsTo<\Illuminate\Database\Eloquent\Model, $this> roomType() HPMS A1 sellable room type
 *
 * @property-read Collection<int, Model> $stays
 * @property-read Model|null $activeStay
 * @property-read Model|null $roomType
 */
class StoreTable extends Model
{
    use BelongsToAccount;
    use HasFactory;

    public $table = 'store_tables';

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];

    protected $casts = [
        'status_changed_at' => 'datetime',
        // PARITY-RUN T3: rotating tent-card verification code. This model has
        // $timestamps = false, so the rotation stamp is its own column.
        'verify_code_rotated_at' => 'datetime',
    ];

    /** P6-270: clearing a table (free/dirty/cleaning) ends its QR sessions. */
    protected static function booted(): void
    {
        static::updated(function (StoreTable $table) {
            if ($table->wasChanged('status') && in_array((string) $table->status, TableSessionService::CLEARED_STATUSES, true)) {
                TableSessionService::endSessionsForTable($table);
            }
        });
    }

    /**
     * The store this table (or hotel room) belongs to.
     *
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id', 'id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    /** PARITY-RUN T3: dine-in scan sessions proven against this table. */
    public function tableSessions()
    {
        return $this->hasMany(TableSession::class, 'table_id');
    }

    /** PARITY-RUN T4: every merge this table has ever been part of. */
    public function mergeMemberships()
    {
        return $this->hasMany(TableMergeMember::class, 'table_id');
    }

    /** PARITY-RUN T4: merges this table led (it carried the party's bill). */
    public function mergeGroupsLed()
    {
        return $this->hasMany(TableMergeGroup::class, 'primary_table_id');
    }

    /**
     * Is this row a hotel room? Guarded so it is safe before the H1 migration
     * that adds `area_type` has run (defaults to the legacy 'table' meaning).
     */
    public function isRoom(): bool
    {
        return ($this->getAttribute('area_type') ?? 'table') === 'room';
    }

    /**
     * HPMS A1: housekeeping cleanliness axis (dirty|clean|inspected). Defaults to
     * 'clean' before the PMS column exists so pre-migration reads never blank out.
     */
    public function hkStatus(): string
    {
        $v = $this->getAttribute('hk_status');

        return in_array($v, ['dirty', 'clean', 'inspected'], true) ? $v : 'clean';
    }

    /** HPMS A1: out-of-service marker (none|out_of_service|out_of_order). */
    public function ooStatus(): string
    {
        $v = $this->getAttribute('oo_status');

        return in_array($v, ['none', 'out_of_service', 'out_of_order'], true) ? $v : 'none';
    }

    /**
     * HPMS A1: is this room out of order (subtracted from availability) on/through
     * the given date? out_of_service is a soft flag and never blocks availability.
     */
    public function isOutOfOrder(?string $onDate = null): bool
    {
        if ($this->ooStatus() !== 'out_of_order') {
            return false;
        }
        $until = $this->getAttribute('oo_until');
        if (! $until || ! $onDate) {
            return true; // open-ended OO, or no date context → treat as OO
        }

        return (string) Carbon::parse($until)->toDateString()
            >= (string) Carbon::parse($onDate)->toDateString();
    }
}
