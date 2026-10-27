<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hotel\Models;

use App\Models\StoreTable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;
use Juwri\Modules\Payments\Models\LedgerAccount;

/**
 * FEATURES-RUN-4 T1 (hotel): a guest's stay in a room. The room is a
 * `store_tables` row (area_type='room') referenced by `table_id`; the
 * charge-to-room folio is a {@see LedgerAccount} of kind 'hotel_folio' pointed
 * at by `folio_account_id` (H6 posts orders/settlements through LedgerService).
 * New model per decision D5 (rooms reuse store_tables; stays/folio are new).
 */
class HotelStay extends Model
{
    use BelongsToAccount;
    use HasFactory;

    protected $table = 'hotel_stays';

    public const STATUSES = ['checked_in', 'checked_out', 'cancelled'];

    protected $guarded = [];

    /**
     * A11: the per-stay `token` is a guest-folio bearer credential (C3's public
     * `hotel/{slug}/folio/{token}` page). Keep it out of array/JSON serialisation so
     * it never leaks through a stay payload; the guest-folio link is built explicitly.
     */
    protected $hidden = ['token'];

    protected $casts = [
        'checked_in_at' => 'datetime',
        'checked_out_at' => 'datetime',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    /** The room this stay occupies (a store_tables row, area_type='room'). */
    public function room()
    {
        return $this->belongsTo(StoreTable::class, 'table_id');
    }

    /** The folio ledger account (kind 'hotel_folio'); nullable until opened. */
    public function folioAccount()
    {
        return $this->belongsTo(LedgerAccount::class, 'folio_account_id');
    }

    public function serviceRequests()
    {
        return $this->hasMany(ServiceRequest::class, 'stay_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'checked_in';
    }
}
