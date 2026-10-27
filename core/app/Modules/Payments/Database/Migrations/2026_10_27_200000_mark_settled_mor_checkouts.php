<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BL-038 (one-off, idempotent): before settled merchant-of-record checkouts were marked, a paid Paddle / Lemon
 * Squeezy checkout row kept a null status and the hourly cleanup deleted it after 3 hours — every renewal after that was
 * refused. Rows still present at deploy time are marked here, the way the settlement now marks them: a null-status
 * plan / add-on checkout with a matching `success` settlement on paddle / lemon_squeezy (same account, product, method,
 * frequency, amount and currency, settled within 2 days after it, and no later checkout of the same kind before that
 * settlement) becomes `recurring_setup` (a monthly / yearly subscription) or `checkout_settled`. Only null-status rows
 * are touched, so running it again changes nothing. Renewals of rows already deleted settle through
 * MorPaymentGuard::renewalCheckout.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('transaction')) {
            return;
        }
        DB::table('transaction')->whereNull('status')->whereIn('transaction_method', ['membership', 'addon'])
            ->chunkById(500, function ($checkouts): void {
                foreach ($checkouts as $checkout) {
                    if ($checkout->created_at === null) {
                        continue;
                    }
                    $from = Carbon::parse($checkout->created_at);
                    $same = fn ($q) => $q->where('user_id', $checkout->user_id)->where('product_id', $checkout->product_id)
                        ->where('transaction_method', $checkout->transaction_method)->where('frequency', $checkout->frequency);
                    $settlement = DB::table('transaction')->where($same)
                        ->where('status', 'success')->whereIn('transaction_gatway', ['paddle', 'lemon_squeezy'])
                        ->where('amount_minor', $checkout->amount_minor)->where('currency_code', $checkout->currency_code)
                        ->where('created_at', '>=', $from)->where('created_at', '<=', $from->copy()->addDays(2))
                        ->orderBy('created_at')->first();
                    if ($settlement === null) {
                        continue;
                    }
                    // The settlement belongs to the latest checkout of its kind started before it.
                    $later = DB::table('transaction')->where($same)->whereNull('status')->where('id', '>', $checkout->id)
                        ->where('created_at', '<=', $settlement->created_at)->exists();
                    if ($later) {
                        continue;
                    }
                    $recurring = in_array($checkout->frequency, ['MONTHLY', 'YEARLY'], true);
                    DB::table('transaction')->where('id', $checkout->id)->whereNull('status')
                        ->update(['status' => $recurring ? 'recurring_setup' : 'checkout_settled']);
                }
            });
    }

    public function down(): void
    {
        // A data repair: the marked rows stay marked (unmarking them would let the cleanup delete paid checkouts again).
    }
};
