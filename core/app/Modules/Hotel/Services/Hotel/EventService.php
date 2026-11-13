<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hotel\Services\Hotel;

use Juwri\Modules\Hotel\Models\HotelEvent;

/**
 * HPMS A8 — hotel event lifecycle (banquets / venue hire). Status flow
 *   enquiry → confirmed → completed | cancelled.
 * Confirming (or completing) an event BILLS its items — to the linked stay's
 * folio, or to the event's own `hotel_event` ledger account when standalone.
 * A standalone event can only be cancelled at a ZERO balance (settle first); a
 * linked event's cancel reverses its item charges off the stay folio.
 */
class EventService
{
    /** Confirm an event (enquiry|confirmed → confirmed) and bill its items. */
    public static function confirm(HotelEvent $event): HotelEvent
    {
        if (in_array($event->status, [HotelEvent::STATUS_COMPLETED, HotelEvent::STATUS_CANCELLED], true)) {
            throw new \RuntimeException('A closed event cannot be confirmed.');
        }
        HotelFolioService::postEventItems($event);
        $event->status = HotelEvent::STATUS_CONFIRMED;
        $event->save();

        return $event->refresh();
    }

    /** Complete an event (must be confirmed); ensures its items are billed. */
    public static function complete(HotelEvent $event): HotelEvent
    {
        if ($event->status !== HotelEvent::STATUS_CONFIRMED) {
            throw new \RuntimeException('Only a confirmed event can be completed.');
        }
        HotelFolioService::postEventItems($event); // idempotent — no double billing
        $event->status = HotelEvent::STATUS_COMPLETED;
        $event->save();

        return $event->refresh();
    }

    /**
     * Cancel an event. Standalone: only at a zero balance (settle first). Linked:
     * reverse the event's item charges off the stay folio, then cancel.
     */
    public static function cancel(HotelEvent $event): HotelEvent
    {
        if ($event->status === HotelEvent::STATUS_COMPLETED) {
            throw new \RuntimeException('A completed event cannot be cancelled.');
        }

        if ($event->isLinkedToStay()) {
            HotelFolioService::reverseEventItems($event);
        } elseif (HotelFolioService::eventBalance($event) > 0.001) {
            throw new \RuntimeException('Settle the event balance before cancelling.');
        }

        $event->status = HotelEvent::STATUS_CANCELLED;
        $event->save();

        // Close the standalone account (non-financial flag), mirroring stay cancel.
        if (! $event->isLinkedToStay() && $event->folio_account_id) {
            \Juwri\Modules\Payments\Models\LedgerAccount::whereKey($event->folio_account_id)->update(['status' => 'settled']);
        }

        return $event->refresh();
    }
}
