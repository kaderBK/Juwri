<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Customers\Services;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Juwri\Modules\Payments\Contracts\Ledger;
use Juwri\Modules\Payments\Models\LedgerAccount;

/**
 * GOL C1 (rev/guest-order-linking): fold a passwordless "shadow" Customer —
 * the row guest checkout's capture block creates — into the canonical account
 * row, moving EVERYTHING that references it, then delete the shadow.
 *
 * Security guards (HANDOFF §1, locked): a row with a password is a real
 * account and must NEVER be merged away; cross-restaurant merges are refused
 * (Customer is per-restaurant). Callers prove ownership BEFORE calling
 * (session proof / verified phone / verified email) — this service only
 * enforces the invariants it can see.
 *
 * Reference inventory this must stay in sync with (all tables that point at
 * customers.id — re-grep migrations when touching this):
 *   plain repoints .... customer_addresses, orders, customer_tokens,
 *                       customer_feedback, meal_plans, menu_reviews,
 *                       store_reviews, campaign_recipients, shopper_customer,
 *                       gift_cards.purchaser_customer_id, customer_notifications,
 *                       return_requests, hotel_bookings, skin_profiles
 *   unique-pair ....... loyalty_accounts [customer_id, store_id],
 *                       restaurant_follows [customer_id, store_id],
 *                       coupon_redemptions [coupon_id, customer_id, seq],
 *                       wishlist_items [store_id, customer_id, menu_id],
 *                       stock_alerts [store_id, target, contact_hash]
 *   ledger-backed ..... ledger_accounts (owner_type='customer',
 *                       kind='customer_store_credit') — balance is
 *                       SUM(entries), so collisions move money via a balanced
 *                       LedgerService::post, never a raw column write
 *   aggregates ........ customers.orders_count / total_spent / last_order_at
 */
class CustomerMergeService
{
    /**
     * Every ShopperPrivacyService::COLUMNS key the merge moves onto the canonical
     * row (kept in sync with ShopperPrivacyCompletenessTest: a COLUMNS entry that
     * is neither here nor in {@see SKIPPED} fails the completeness test).
     *
     * @var list<string>
     */
    public const HANDLED = [
        'orders.customer_id',
        'customer_addresses.customer_id',
        'customer_tokens.customer_id',
        'customer_feedback.customer_id',
        'meal_plans.customer_id',
        'menu_reviews.customer_id',
        'store_reviews.customer_id',
        'campaign_recipients.customer_id',
        'shopper_customers.customer_id',
        'gift_cards.purchaser_customer_id',
        'store_follows.customer_id',
        'loyalty_accounts.customer_id',
        'coupon_redemptions.customer_id',
        'customer_notifications.customer_id',
        'return_requests.customer_id',
        'hotel_bookings.customer_id',
        'skin_profiles.customer_id',
        'wishlist_items.customer_id',
        'stock_alerts.customer_id',
    ];

    /**
     * COLUMNS keys the merge deliberately does NOT move, with the reason.
     *
     * @var array<string, string>
     */
    public const SKIPPED = [
        'customer_passkeys.customer_id' => 'a passwordless shadow has no passkeys',
    ];

    /**
     * Merge $shadow into $canonical inside one transaction.
     * Returns a summary of what moved: ['orders' => int, ...].
     *
     * @return array<string, int|float>
     *
     * @throws \InvalidArgumentException when the merge would violate a guard.
     */
    public static function merge(Customer $shadow, Customer $canonical): array
    {
        if ($shadow->id === $canonical->id) {
            throw new \InvalidArgumentException('Cannot merge a customer into itself.');
        }
        if ($shadow->store_id !== $canonical->store_id) {
            throw new \InvalidArgumentException('Cannot merge customers across restaurants.');
        }
        if (! empty($shadow->password)) {
            throw new \InvalidArgumentException('Refusing to merge a customer that has a password (real account).');
        }

        return DB::transaction(function () use ($shadow, $canonical) {
            $moved = [];

            /* Plain repoints — no unique constraint involves customer_id. */
            $moved['orders'] = DB::table('orders')
                ->where('customer_id', $shadow->id)->update(['customer_id' => $canonical->id]);
            $moved['addresses'] = DB::table('customer_addresses')
                ->where('customer_id', $shadow->id)->update(['customer_id' => $canonical->id]);
            foreach (['customer_tokens', 'customer_feedback', 'meal_plans', 'menu_reviews', 'store_reviews', 'campaign_recipients', 'shopper_customers'] as $tbl) {
                $moved[$tbl] = DB::table($tbl)
                    ->where('customer_id', $shadow->id)->update(['customer_id' => $canonical->id]);
            }
            $moved['gift_cards'] = DB::table('gift_cards')
                ->where('purchaser_customer_id', $shadow->id)
                ->update(['purchaser_customer_id' => $canonical->id]);

            /* restaurant_follows — unique [customer_id, store_id]: drop the
               shadow's row when the canonical already follows, else repoint. */
            $canonicalFollows = DB::table('store_follows')
                ->where('customer_id', $canonical->id)->pluck('store_id')->all();
            if ($canonicalFollows !== []) {
                DB::table('store_follows')
                    ->where('customer_id', $shadow->id)
                    ->whereIn('store_id', $canonicalFollows)
                    ->delete();
            }
            $moved['follows'] = DB::table('store_follows')
                ->where('customer_id', $shadow->id)->update(['customer_id' => $canonical->id]);

            /* loyalty_accounts — unique [customer_id, store_id]. If only the
               shadow has an account, repoint it (its ledger follows). If both do,
               repoint the shadow's LEDGER rows to the canonical account (order_id
               stays globally unique — same rows, new account — so earn()'s
               double-earn dedupe keeps working), fold the balances/lifetimes, and
               delete the emptied shadow account BEFORE its FK cascade could ever
               touch the moved history. */
            $moved['loyalty_points'] = 0;
            $shadowLoyalty = DB::table('loyalty_accounts')->where('customer_id', $shadow->id)->first();
            if ($shadowLoyalty) {
                $canonicalLoyalty = DB::table('loyalty_accounts')
                    ->where('customer_id', $canonical->id)
                    ->where('store_id', $shadowLoyalty->store_id)
                    ->first();
                if (! $canonicalLoyalty) {
                    DB::table('loyalty_accounts')->where('id', $shadowLoyalty->id)
                        ->update(['customer_id' => $canonical->id]);
                } else {
                    DB::table('loyalty_ledger_entries')->where('loyalty_account_id', $shadowLoyalty->id)
                        ->update(['loyalty_account_id' => $canonicalLoyalty->id]);
                    DB::table('loyalty_accounts')->where('id', $canonicalLoyalty->id)->update([
                        'balance' => $canonicalLoyalty->balance + $shadowLoyalty->balance,
                        'lifetime_earned' => $canonicalLoyalty->lifetime_earned + $shadowLoyalty->lifetime_earned,
                        'lifetime_redeemed' => $canonicalLoyalty->lifetime_redeemed + $shadowLoyalty->lifetime_redeemed,
                    ]);
                    DB::table('loyalty_accounts')->where('id', $shadowLoyalty->id)->delete();
                }
                $moved['loyalty_points'] = (int) $shadowLoyalty->balance;
            }

            /* coupon_redemptions — unique [coupon_id, customer_id, seq]: per-row
               repoint; on a seq collision, renumber past the canonical's max.
               Guest shadows carry at most a handful of rows. */
            $moved['coupon_redemptions'] = 0;
            $shadowRedemptions = DB::table('coupon_redemptions')
                ->where('customer_id', $shadow->id)->orderBy('seq')->get();
            foreach ($shadowRedemptions as $row) {
                $clash = DB::table('coupon_redemptions')
                    ->where('coupon_id', $row->coupon_id)
                    ->where('customer_id', $canonical->id)
                    ->where('seq', $row->seq)
                    ->exists();
                $newSeq = $row->seq;
                if ($clash) {
                    $newSeq = (int) DB::table('coupon_redemptions')
                        ->where('coupon_id', $row->coupon_id)
                        ->where('customer_id', $canonical->id)
                        ->max('seq') + 1;
                }
                DB::table('coupon_redemptions')->where('id', $row->id)
                    ->update(['customer_id' => $canonical->id, 'seq' => $newSeq]);
                $moved['coupon_redemptions']++;
            }

            /* Store credit — ledger_accounts unique [owner_type, owner_id, kind,
               currency]; balance is SUM(entries). No canonical account in that
               currency → repoint the account (entries follow). Both exist → move
               the balance with a balanced account→account post (a TRANSFER, not
               issuance — deliberately not CustomerWalletService::credit(), whose
               customer_wallet gate must never strand a diner's money mid-merge),
               then close the emptied shadow account. */
            $moved['store_credit'] = 0.0;
            $shadowAccounts = LedgerAccount::where('owner_type', 'customer')
                ->where('owner_id', $shadow->id)
                ->where('kind', 'customer_store_credit')
                ->get();
            foreach ($shadowAccounts as $acct) {
                $canonicalAcct = LedgerAccount::where('owner_type', 'customer')
                    ->where('owner_id', $canonical->id)
                    ->where('kind', 'customer_store_credit')
                    ->where('currency', $acct->currency)
                    ->first();
                if (! $canonicalAcct) {
                    $acct->update(['owner_id' => $canonical->id]);
                    $moved['store_credit'] += app(Ledger::class)->accountBalance($acct); // exact (bug batch 4 · 1)

                    continue;
                }
                $balance = app(Ledger::class)->accountBalance($acct); // exact in the account currency (bug batch 4 · 1)
                if ($balance > 0) {
                    app(Ledger::class)->post([
                        ['account' => $acct, 'amount' => -$balance, 'ref' => 'cwmerge:'.$shadow->id.':'.$canonical->id.':'.$acct->currency.':src', 'reason' => 'customer merge'],
                        ['account' => $canonicalAcct, 'amount' => $balance, 'ref' => 'cwmerge:'.$shadow->id.':'.$canonical->id.':'.$acct->currency.':dst', 'reason' => 'customer merge'],
                    ], null, 'customer merge');
                    $moved['store_credit'] += $balance;
                }
                $acct->update(['status' => 'closed']);
            }

            /* P6-345: tables ShopperPrivacyService::COLUMNS declares that the old
               merge left pointing at the deleted shadow. Plain repoints first (no
               unique on customer_id) — customer_notifications, return_requests,
               hotel_bookings, skin_profiles (skin_profiles has only an index on
               customer_id, so a plain repoint is safe). Edition tables skipped. */
            foreach (['customer_notifications', 'return_requests', 'hotel_bookings', 'skin_profiles'] as $tbl) {
                if (! Schema::hasTable($tbl)) {
                    continue;
                }
                $moved[$tbl] = DB::table($tbl)
                    ->where('customer_id', $shadow->id)
                    ->update(['customer_id' => $canonical->id]);
            }

            /* wishlist_items — unique [store_id, customer_id, menu_id]: drop the
               shadow's rows for menus the canonical already saved, then repoint. */
            if (Schema::hasTable('wishlist_items')) {
                $canonicalMenus = DB::table('wishlist_items')
                    ->where('customer_id', $canonical->id)->pluck('menu_id')->all();
                if ($canonicalMenus !== []) {
                    DB::table('wishlist_items')
                        ->where('customer_id', $shadow->id)
                        ->whereIn('menu_id', $canonicalMenus)
                        ->delete();
                }
                $moved['wishlist_items'] = DB::table('wishlist_items')
                    ->where('customer_id', $shadow->id)
                    ->update(['customer_id' => $canonical->id]);
            }

            /* stock_alerts — unique [store_id, target, contact_hash] ("one request
               per contact and item"): drop the shadow's rows the canonical already
               has (same target + contact hash), then repoint the rest. */
            if (Schema::hasTable('stock_alerts')) {
                foreach (DB::table('stock_alerts')->where('customer_id', $canonical->id)
                    ->get(['target', 'contact_hash']) as $row) {
                    DB::table('stock_alerts')
                        ->where('customer_id', $shadow->id)
                        ->where('target', $row->target)
                        ->where('contact_hash', $row->contact_hash)
                        ->delete();
                }
                $moved['stock_alerts'] = DB::table('stock_alerts')
                    ->where('customer_id', $shadow->id)
                    ->update(['customer_id' => $canonical->id]);
            }

            /* CRM aggregates + missing profile fields. Delete the shadow FIRST so
               the per-restaurant unique [store_id, email/phone] indexes free
               its identity values for the canonical row. */
            $fill = [
                'orders_count' => $canonical->orders_count + $shadow->orders_count,
                'total_spent' => money_round((float) $canonical->total_spent + (float) $shadow->total_spent, money_decimals(store_currency((int) $canonical->store_id))),
                'last_order_at' => max($canonical->last_order_at, $shadow->last_order_at),
                'marketing_consent' => $canonical->marketing_consent || $shadow->marketing_consent,
                'sms_consent' => ($canonical->sms_consent ?? false) || ($shadow->sms_consent ?? false),
            ];
            foreach (['email', 'phone', 'birthday'] as $field) {
                if (empty($canonical->{$field}) && ! empty($shadow->{$field})) {
                    $fill[$field] = $shadow->{$field};
                    if ($field === 'phone' && $shadow->phone_verified_at && ! $canonical->phone_verified_at) {
                        $fill['phone_verified_at'] = $shadow->phone_verified_at;
                    }
                    if ($field === 'email' && $shadow->email_verified_at && ! $canonical->email_verified_at) {
                        $fill['email_verified_at'] = $shadow->email_verified_at;
                    }
                }
            }
            $shadow->delete();
            $canonical->forceFill($fill)->save();

            return $moved;
        });
    }
}
