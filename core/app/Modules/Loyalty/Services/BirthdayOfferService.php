<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Loyalty\Services;

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
 * #63: sends each opted-in customer a one-per-year birthday discount coupon.
 * Idempotent — a customer whose last_birthday_offer_sent_year is the current
 * year is skipped, so re-running the daily command never double-sends.
 *
 * P6-326: the coupon is kept (and the year stamped) only when at least one
 * channel actually delivered; a failed delivery deletes the coupon and leaves
 * the year unstamped so the 3-day grace window retries it on the next run.
 */
class BirthdayOfferService
{
    public function __construct(protected Sms $sms) {}

    /**
     * @param  Carbon|null  $today  inject for testing; defaults to now() per restaurant tz
     * @return array{restaurants:int, offers:int}
     */
    public function run(?Carbon $today = null): array
    {
        $stores = 0;
        $offers = 0;

        Store::query()->with('user')->chunkById(100, function ($chunk) use (&$stores, &$offers, $today) {
            foreach ($chunk as $store) {
                if (! store_options($store->id, 'birthday_offers_enabled')) {
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
        // Pricing follow-ups: no birthday coupon a customer could not redeem (the plan withholds coupons).
        if (! PlanGate::allows($owner, PlanGate::COUPONS)) {
            return 0;
        }

        $tz = store_options($store->id, 'timezone') ?: config('app.timezone', 'UTC');
        $now = $today ? $today->copy() : Carbon::now($tz);
        // The year is the dedupe key. At a year boundary the window can match a customer
        // on the "wrong" side (e.g. a 31 Dec birthday matched on 1 Jan stamps the next
        // year) — accepted: it still sends at most once per run-year.
        $year = (int) $now->year;
        $pairs = $this->birthdayMatchPairs($now);

        $reward = (int) (store_options($store->id, 'birthday_reward_percentage') ?: 10);
        $reward = max(1, min(100, $reward));
        $validity = (int) (store_options($store->id, 'birthday_validity_days') ?: 7);
        $validity = max(1, $validity);
        $channels = $this->channels($store->id);

        $count = 0;

        Customer::query()
            ->where('store_id', $store->id)
            ->whereNotNull('birthday')
            ->where(function ($q) use ($pairs) {
                $first = true;
                foreach ($pairs as [$month, $day]) {
                    $match = function ($inner) use ($month, $day) {
                        $inner->whereMonth('birthday', $month)->whereDay('birthday', $day);
                    };
                    if ($first) {
                        $q->where($match);
                        $first = false;
                    } else {
                        $q->orWhere($match);
                    }
                }
            })
            ->where(function ($q) {
                $q->where('marketing_consent', true)->orWhere('sms_consent', true);
            })
            ->where(function ($q) use ($year) {
                $q->whereNull('last_birthday_offer_sent_year')
                    ->orWhere('last_birthday_offer_sent_year', '!=', $year);
            })
            ->chunkById(200, function ($customers) use (&$count, $store, $owner, $reward, $validity, $channels, $now, $year) {
                foreach ($customers as $customer) {
                    $coupon = null;
                    try {
                        $coupon = $this->makeCoupon($owner->id, $reward, $now, $validity);
                        if (! $this->deliver($customer, $store, $coupon, $reward, $channels)) {
                            $coupon->delete();

                            continue;
                        }
                        $customer->forceFill(['last_birthday_offer_sent_year' => $year])->save();
                        $count++;
                    } catch (\Throwable $e) {
                        if ($coupon !== null && $coupon->exists) {
                            $coupon->delete();
                        }
                        Log::error("[birthday] failed for customer {$customer->id}: ".$e->getMessage());
                    }
                }
            });

        return $count;
    }

    protected function makeCoupon(int $ownerId, int $reward, Carbon $now, int $validity): Coupon
    {
        do {
            $code = 'BDAY'.strtoupper(Str::random(6));
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

    /**
     * @return bool true when at least one enabled channel actually delivered (no
     *              throw, and for SMS a truthy send result)
     */
    protected function deliver(Customer $customer, Store $store, Coupon $coupon, int $reward, array $channels): bool
    {
        $subject = ___('Happy Birthday from :name!', ['name' => $store->title]);
        $body = ___('Happy Birthday! Enjoy :reward% off your next order with code :code. Valid until :date.', [
            'reward' => $reward,
            'code' => $coupon->code,
            'date' => $coupon->expiry_at->toFormattedDateString(),
        ]);

        $delivered = false;

        if (in_array('email', $channels, true) && $customer->email && $customer->marketing_consent) {
            try {
                $unsubscribeUrl = CampaignMail::unsubscribeUrlFor((int) $customer->id);
                Mail::to($customer->email)->send(new CampaignMail($subject, $body, $unsubscribeUrl));
                $delivered = true;
            } catch (\Throwable $e) {
                Log::warning("[birthday] email failed for {$customer->id}: ".$e->getMessage());
            }
        }

        if (in_array('sms', $channels, true) && $customer->phone && $customer->sms_consent) {
            try {
                if ($this->sms->send($customer->phone, $body."\n\n".___('Reply STOP to unsubscribe.'), $store->id, 'birthday', true)) {
                    $delivered = true;
                }
            } catch (\Throwable $e) {
                Log::warning("[birthday] sms failed for {$customer->id}: ".$e->getMessage());
            }
        }

        return $delivered;
    }

    /**
     * The (month, day) pairs a birthday must land on to be due this run: today and
     * the previous two days, so an unstamped customer is retried by the next run.
     *
     * @return list<array{int, int}>
     */
    protected function birthdayMatchPairs(Carbon $now): array
    {
        $pairs = [];
        for ($offset = 0; $offset <= 2; $offset++) {
            $day = $now->copy()->subDays($offset);
            $pairs[] = [(int) $day->month, (int) $day->day];
        }

        // 29 Feb birthdays: in a non-leap year there is no 29 Feb, so send on 28 Feb
        // (the window already holds 28 Feb) instead of letting it wait a whole year.
        // In a leap year 29 Feb matches normally and must NOT be sent early on 28 Feb.
        if (! $now->isLeapYear()) {
            foreach ($pairs as [$month, $day]) {
                if ($month === 2 && $day === 28) {
                    $pairs[] = [2, 29];
                    break;
                }
            }
        }

        return $pairs;
    }

    /**
     * @return array<string> enabled channels: email and/or sms (defaults to both)
     */
    protected function channels(int $storeId): array
    {
        $opt = store_options($storeId, 'birthday_channel');
        if (empty($opt)) {
            return ['email', 'sms'];
        }
        if (is_string($opt)) {
            return array_map('trim', explode(',', $opt));
        }

        return (array) $opt;
    }
}
