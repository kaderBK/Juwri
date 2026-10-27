<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;
use Juwri\Modules\Messaging\Services\MobilePush\MobilePushService;
use Juwri\Modules\Reservations\Jobs\PushRwgWaitlistRtuJob;
use Juwri\Modules\Reservations\Services\Rwg\WaitlistStateMap;

class WaitlistEntry extends Model
{
    use BelongsToAccount;

    protected $guarded = [];

    protected $casts = [
        'notified_at' => 'datetime',
    ];

    /* PARITY-RUN T9: "table ready" to the store's installed apps. Fires on the
       transition INTO `notified`, not on every save, so re-saving a notified
       entry cannot re-alert. Guarded like every other best-effort listener.

       google-partner D4: this same observer also mirrors state changes to Google
       (RTU) for source='google' entries — put here (not the controller) so ANY
       actor's transition pushes, and only Google-sourced rows push out. */
    protected static function booted(): void
    {
        static::updated(function (WaitlistEntry $entry) {
            if (! $entry->wasChanged('status')) {
                return;
            }
            $status = (string) $entry->status;

            // Staff in-app "table ready" push on the transition into notified.
            if ($status === 'notified') {
                try {
                    MobilePushService::waitlistNotified($entry);
                } catch (\Throwable $e) {
                    // best-effort only
                }
            }

            // google-partner D4: RwG real-time update. Only Google-sourced entries
            // push; dark-guarded so a missing-creds env dispatches zero jobs.
            if ((string) $entry->source === 'google' && config('services.reserve_with_google.enabled')) {
                try {
                    PushRwgWaitlistRtuJob::dispatch(
                        (int) $entry->id,
                        WaitlistStateMap::toGoogle($status),
                    );
                } catch (\Throwable $e) {
                    // best-effort only — an RTU enqueue failure must never roll back
                    // the waitlist status write (matches the sibling push above and
                    // the Reservation observer).
                }
            }
        });
    }

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    // Position in the active waiting queue for this restaurant
    public function getPositionAttribute(): int
    {
        return static::where('store_id', $this->store_id)
            ->where('status', 'waiting')
            ->where('id', '<=', $this->id)
            ->count();
    }

    // Estimated wait minutes based on position × avg-turn-time (default 20 min)
    public function estimatedWait(int $avgTurnMinutes = 20): int
    {
        return $this->position * $avgTurnMinutes;
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['waiting', 'notified'])->orderBy('created_at');
    }

    public function scopeForRestaurant($query, int $storeId)
    {
        return $query->where('store_id', $storeId);
    }
}
