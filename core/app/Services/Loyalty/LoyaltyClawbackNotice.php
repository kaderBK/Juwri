<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services\Loyalty;

use App\Models\Customer;
use App\Models\LoyaltyProgram;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Juwri\Core\Models\Store;
use Juwri\Modules\Customers\Mail\LoyaltyPointsClawedBack;
use Juwri\Modules\Messaging\Contracts\Sms;

/**
 * P6-253 (J46): when a refund takes loyalty points back, the customer is told — by e-mail and
 * SMS where we have the address / number. Best-effort: a delivery failure never fails the refund.
 */
class LoyaltyClawbackNotice
{
    /** The plain sentence shown (page banner, e-mail, SMS) when the balance is below zero. */
    public static function negativeText(int $balance, int $threshold): string
    {
        return ___('Your balance is -:debt because points from a refunded order were already used. Earn :more more points to redeem again.', [
            'debt' => abs($balance),
            'more' => max(1, $threshold - $balance),
        ]);
    }

    /** The sentence for one claw-back (history line, no balance part). */
    public static function lineText(int $orderId, int $points): string
    {
        return ___('Order #:order refunded: -:points points', ['order' => $orderId, 'points' => $points]);
    }

    public static function send(Order $order, int $points, int $balanceAfter): void
    {
        if ($points <= 0 || ! $order->customer_id) {
            return;
        }
        try {
            $customer = Customer::find($order->customer_id);
            if (! $customer) {
                return;
            }
            $store = Store::find($order->store_id);
            $storeName = (string) ($store->title ?? '');
            $text = ___('Because order #:order was refunded, :points loyalty points earned on it were taken back.', [
                'order' => $order->id, 'points' => $points,
            ]);
            if ($balanceAfter < 0) {
                $threshold = (int) LoyaltyProgram::where('store_id', $order->store_id)->value('reward_threshold');
                $text .= ' '.self::negativeText($balanceAfter, $threshold);
            }

            $email = (string) $customer->email;
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                try {
                    Mail::to($email)->send(new LoyaltyPointsClawedBack($storeName, (string) $customer->name, (int) $order->id, $text, app()->getLocale()));
                } catch (\Throwable $e) {
                    Log::warning('loyalty.clawback.mail_failed: '.$e->getMessage());
                }
            }
            $phone = (string) $customer->phone;
            if ($phone !== '') {
                try {
                    app(Sms::class)->send($phone, ($storeName !== '' ? $storeName.': ' : '').$text, (int) $order->store_id, 'loyalty_refund');
                } catch (\Throwable $e) {
                    Log::warning('loyalty.clawback.sms_failed: '.$e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            Log::warning('loyalty.clawback.notice_failed: '.$e->getMessage());
        }
    }
}
