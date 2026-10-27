<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;
use Juwri\Modules\Pos\Services\Printing\BridgeUrl;

/**
 * PARITY-RUN T1-1: one configured printer — where it is, how we reach it, and
 * what it prints. The dispatcher matches profiles on event × station × branch.
 */
class PrinterProfile extends Model
{
    use BelongsToAccount;
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'events' => 'array',
        'station_ids' => 'array',
        'auto_print' => 'boolean',
        'active' => 'boolean',
        'width_mm' => 'integer',
        'copies' => 'integer',
        'position' => 'integer',
    ];

    /** Transports the dispatcher knows. `sunmi` is the pre-existing kiosk lane (read-only). */
    public const TRANSPORTS = ['browser', 'webserial', 'webusb', 'rawbt', 'bridge', 'sunmi'];

    /** Events a profile may subscribe to. */
    public const EVENTS = ['new_order', 'paid', 'kot', 'shift_close'];

    /** Paper widths (mm) → ESC/POS font-A column counts. */
    public const WIDTHS = [80 => 48, 57 => 32];

    /** The bridge base URL frozen by rev/parity/contracts/print-bridge.md. */
    public const DEFAULT_BRIDGE_URL = 'http://127.0.0.1:8543';

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function jobs()
    {
        return $this->hasMany(PrintJob::class, 'profile_id');
    }

    /**
     * D3: the printer a failed job re-routes to (nullable → no fallback).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<PrinterProfile, $this>
     */
    public function fallbackProfile(): BelongsTo
    {
        return $this->belongsTo(PrinterProfile::class, 'fallback_profile_id');
    }

    /** Font-A columns for this profile's paper width (defaults to 80 mm / 48 cols). */
    public function columns(): int
    {
        return self::WIDTHS[(int) $this->width_mm] ?? 48;
    }

    /**
     * D8 — resolve the effective printer for a print, in priority order:
     * terminal override → branch-bound profile → store-wide default. A terminal's
     * pinned profile wins when it is set + active; otherwise a profile bound to the
     * branch beats the store-wide (branch_id null) one, so a device with no override
     * still prints where its branch prints. Null when the store has no printer at all.
     */
    public static function resolveForContext(int $storeId, ?int $branchId, ?PosTerminal $terminal = null): ?self
    {
        if ($terminal && $terminal->printer_profile_id) {
            $pinned = self::where('id', $terminal->printer_profile_id)
                ->where('store_id', $storeId)
                ->where('active', true)->first();
            if ($pinned) {
                return $pinned;
            }
        }

        $candidates = self::where('store_id', $storeId)
            ->where('active', true)
            ->orderBy('position')->orderBy('id')->get()
            ->filter(fn (self $p) => $p->servesBranch($branchId))
            ->values();

        return $candidates->first(fn (self $p) => $p->branch_id !== null) // branch default
            ?? $candidates->first(); // store-wide default (branch_id null)
    }

    /** Does this profile print the given event? */
    public function handlesEvent(string $event): bool
    {
        return in_array($event, (array) ($this->events ?: []), true);
    }

    /**
     * Does this profile serve the given branch? A profile with no branch is the
     * store-wide default and serves every branch, including orders with no branch.
     */
    public function servesBranch(?int $branchId): bool
    {
        return $this->branch_id === null || (int) $this->branch_id === (int) $branchId;
    }

    /**
     * Station filter for KOT tickets. An empty filter means "everything" — the
     * single-printer kitchen that wants one ticket with every line on it.
     */
    public function stationFilter(): array
    {
        return array_values(array_filter(array_map('intval', (array) ($this->station_ids ?: []))));
    }

    /** Stable slug used inside job keys / bridge jobIds (a-z0-9, ≤ 20 chars). */
    public function slug(): string
    {
        $slug = Str::slug((string) $this->name) ?: 'printer';

        return Str::limit($slug, 20, '').'-'.$this->id;
    }

    /** Effective bridge base URL (loopback default per the frozen contract). */
    public function bridgeBase(): string
    {
        // P6-298: a stored value that is not a plain bridge origin (legacy rows saved before
        // validation) falls back to the loopback default instead of reaching the CSP or a request.
        return BridgeUrl::normalize($this->bridge_url) ?? self::DEFAULT_BRIDGE_URL;
    }

    /**
     * Transports whose bytes are pushed by a client that holds the device handle.
     * Everything except `sunmi` (the kiosk app pulls its own stream) is client-driven;
     * `bridge` may ALSO be driven server-side on a single-host install (config
     * printing.server_bridge), which is why it is listed here too.
     */
    public function isClientDriven(): bool
    {
        return in_array($this->transport, ['webserial', 'webusb', 'rawbt', 'bridge', 'browser'], true);
    }
}
