<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Customers\Services\Crm;

use App\Models\CampaignRecipient;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyLedger;
use App\Models\Order;
use App\Models\Reservation;
use App\Models\WaitlistEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SYSTEMS-RUN C-P1: merge a guest's all-channel history into one date-sorted
 * feed. Orders link by customer_id; reservations/waitlist by phone (they carry
 * no customer_id); loyalty via the customer's loyalty account; campaign sends by
 * customer_id. Feedback is an S-P1 seam — folded in when that table exists.
 *
 * Each event: ['type','at'(Carbon),'title','detail'].
 */
class GuestTimeline
{
    public function for(Customer $customer, int $limit = 50): array
    {
        $events = [];

        foreach (Order::where('customer_id', $customer->id)->latest('created_at')->limit($limit)->get() as $o) {
            $events[] = ['type' => 'order', 'at' => $o->created_at, 'title' => ___('Order #:id', ['id' => $o->id]), 'detail' => (string) money_round((float) $o->total, money_decimals($o->currencyCode() ?: store_currency((int) $o->store_id)))];
        }

        if ($customer->phone) {
            foreach (Reservation::where('store_id', $customer->store_id)->where('phone', $customer->phone)->latest('reserved_at')->limit($limit)->get() as $r) {
                $events[] = ['type' => 'reservation', 'at' => $r->reserved_at ?? $r->created_at, 'title' => ___('Reservation'), 'detail' => $r->status.' · '.$r->party_size.'p'];
            }
            foreach (WaitlistEntry::where('store_id', $customer->store_id)->where('phone', $customer->phone)->latest('created_at')->limit($limit)->get() as $w) {
                $events[] = ['type' => 'waitlist', 'at' => $w->created_at, 'title' => ___('Waitlist'), 'detail' => $w->status];
            }
        }

        $account = LoyaltyAccount::where('customer_id', $customer->id)->first();
        if ($account) {
            foreach (LoyaltyLedger::where('loyalty_account_id', $account->id)->latest('created_at')->limit($limit)->get() as $l) {
                $events[] = ['type' => 'loyalty', 'at' => $l->created_at, 'title' => ___('Loyalty :t', ['t' => $l->type]), 'detail' => sprintf('%+d', (int) $l->delta)];
            }
        }

        foreach (CampaignRecipient::where('customer_id', $customer->id)->latest('sent_at')->limit($limit)->get() as $c) {
            $events[] = ['type' => 'campaign', 'at' => $c->sent_at ?? $c->created_at, 'title' => ___('Campaign send'), 'detail' => $c->status];
        }

        // Feedback seam (S-P1). Render when the table lands.
        if (Schema::hasTable('customer_feedback')) {
            foreach (DB::table('customer_feedback')->where('customer_id', $customer->id)->latest('created_at')->limit($limit)->get() as $f) {
                $events[] = ['type' => 'feedback', 'at' => Carbon::parse($f->created_at), 'title' => ___('Feedback'), 'detail' => (string) ($f->rating ?? '')];
            }
        }

        usort($events, fn ($a, $b) => ($b['at']->timestamp ?? 0) <=> ($a['at']->timestamp ?? 0));

        return array_slice($events, 0, $limit);
    }
}
