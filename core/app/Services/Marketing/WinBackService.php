<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services\Marketing;

use App\Mails\CampaignMail;
use App\Models\Coupon;
use App\Models\Customer;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Support\PlanGate;
use Juwri\Modules\Messaging\Contracts\Sms;

/**
 * GAPS-RUN-2 B2-P2: lapsed-customer win-back. Mirrors BirthdayOfferService — for
 * each restaurant that opted in, find customers whose last_order_at is older than
 * the owner threshold, are consenting, and haven't been win-back-nudged within the
 * frequency window; issue a one-shot auto-coupon and deliver it (email/SMS).
 *
 * Idempotent per period via last_winback_at, so re-running the daily command never
 * double-nudges. Consent-gated: a customer with neither marketing nor SMS consent is
 * never contacted. Governed by the marketing_automation kill-switch.
 */
class WinBackService
{
    public function __construct(protected Sms $sms) {}

    /** @return array{restaurants:int, offers:int} */
    public function run(?Carbon $today = null): array
    {
        if (! feature_enabled('marketing_automation')) {
            return ['stores' => 0, 'offers' => 0];
        }

        $stores = 0;
        $offers = 0;

        Store::query()->chunkById(100, function ($chunk) use (&$stores, &$offers, $today) {
            foreach ($chunk as $store) {
                if (! store_options($store->id, 'winback_enabled')) {
                    continue;
                }
                $stores++;
                $offers += $this->runForRestaurant($store, $today);
            }
        });

        return ['stores' => $stores, 'offers' => $offers];
    }

    protected function runForRestaurant(Store $store, ?Carbon $today): int
    {
        $owner = $store->user;
        if (! $owner) {
            return 0;
        }
        // Pricing follow-ups: no win-back coupon a customer could not redeem (the plan withholds coupons).
        if (! PlanGate::allows($owner, PlanGate::COUPONS)) {
            return 0;
        }

        $now = $today ? $today->copy() : Carbon::now();
        $afterDays = max(1, (int) (store_options($store->id, 'winback_after_days') ?: 30));
        $freqDays = max(1, (int) (store_options($store->id, 'winback_frequency_days') ?: 90));
        $reward = max(1, min(100, (int) (store_options($store->id, 'winback_reward_percentage') ?: 15)));
        $validity = max(1, (int) (store_options($store->id, 'winback_validity_days') ?: 14));
        $channels = $this->channels($store->id);

        $lapsedBefore = $now->copy()->subDays($afterDays);
        $freqBefore = $now->copy()->subDays($freqDays);

        $count = 0;

        Customer::query()
            ->where('store_id', $store->id)
            ->whereNotNull('last_order_at')
            ->where('last_order_at', '<=', $lapsedBefore)
            ->where(function ($q) {
                $q->where('marketing_consent', true)->orWhere('sms_consent', true);
            })
            ->where(function ($q) use ($freqBefore) {
                $q->whereNull('last_winback_at')->orWhere('last_winback_at', '<=', $freqBefore);
            })
            ->chunkById(200, function ($customers) use (&$count, $store, $owner, $reward, $validity, $channels, $now) {
                foreach ($customers as $customer) {
                    try {
                        $coupon = $this->makeCoupon($owner->id, $reward, $now, $validity);
                        $this->deliver($customer, $store, $coupon, $reward, $channels);
                        $customer->forceFill(['last_winback_at' => $now])->save();
                        $count++;
                    } catch (\Throwable $e) {
                        Log::error("[winback] failed for customer {$customer->id}: ".$e->getMessage());
                    }
                }
            });

        return $count;
    }

    protected function makeCoupon(int $ownerId, int $reward, Carbon $now, int $validity): Coupon
    {
        do {
            $code = 'WB'.strtoupper(Str::random(7));
        } while (Coupon::codeTaken($code, $ownerId));

        return Coupon::create([
            'code' => $code,
            'percentage' => $reward,
            'limit' => 1,
            'used' => 0,
            'expiry_at' => $now->copy()->addDays($validity)->endOfDay(),
            'coupon_type' => 'percentage',
            'user_id' => $ownerId,
        ]);
    }

    protected function deliver(Customer $customer, Store $store, Coupon $coupon, int $reward, array $channels): void
    {
        $subject = ___('We miss you at :name!', ['name' => $store->title]);
        $body = ___('We miss you! Here is :reward% off your next order with code :code. Valid until :date.', [
            'reward' => $reward,
            'code' => $coupon->code,
            'date' => $coupon->expiry_at->toFormattedDateString(),
        ]);

        if (in_array('email', $channels, true) && $customer->email && $customer->marketing_consent) {
            try {
                $unsubscribeUrl = CampaignMail::unsubscribeUrlFor((int) $customer->id);
                Mail::to($customer->email)->send(new CampaignMail($subject, $body, $unsubscribeUrl));
            } catch (\Throwable $e) {
                Log::warning("[winback] email failed for {$customer->id}: ".$e->getMessage());
            }
        }

        if (in_array('sms', $channels, true) && $customer->phone && $customer->sms_consent) {
            $this->sms->send($customer->phone, $body."\n\n".___('Reply STOP to unsubscribe.'), $store->id, 'winback', true);
        }
    }

    /** @return array<string> enabled channels (defaults to email + sms) */
    protected function channels(int $storeId): array
    {
        $opt = store_options($storeId, 'winback_channel');
        if (empty($opt)) {
            return ['email', 'sms'];
        }
        if (is_string($opt)) {
            return array_map('trim', explode(',', $opt));
        }

        return (array) $opt;
    }
}
