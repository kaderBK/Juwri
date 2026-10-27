<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Gateways;

use Illuminate\Support\Facades\Validator;
use Juwri\Modules\Payments\Models\Transaction;
use Symfony\Component\HttpFoundation\Response;

/**
 * P1-23 part 5: who is paying, for the gateways that send the buyer's name / email / phone to the provider (Tap,
 * Paymob, Tabby, Tamara, Paylink). Moved out of their hand-written controllers so the adapters share one rule:
 *
 *  - a store order: the checkout form's `name`, `email`, `phone` (required by {@see requireOrderContact()});
 *  - a store payment (gift card, store-credit top-up, reservation / catering deposit — a store's OWN customer): the
 *    form's fields, else the gift-card purchaser's email, else the signed-in customer (guard `customer`);
 *  - a platform purchase (plan, add-on, platform wallet): the signed-in account, as before.
 */
final class GatewayBuyer
{
    /** @return array{name: string, email: string, phone: string} */
    public static function of(Transaction $transaction): array
    {
        $request = request();
        $field = static fn (string $key): string => is_scalar($request->input($key)) ? trim((string) $request->input($key)) : '';

        if (Settlement::isOrder($transaction)) {
            return ['name' => $field('name') !== '' ? $field('name') : 'Customer', 'email' => $field('email'), 'phone' => $field('phone')];
        }

        if (Settlement::isStorePayment($transaction)) {
            $customer = auth('customer')->user();
            $pick = static function (string ...$values): string {
                foreach ($values as $value) {
                    if (trim($value) !== '') {
                        return trim($value);
                    }
                }

                return '';
            };

            return [
                'name' => $pick($field('name'), (string) ($customer->name ?? ''), 'Customer'),
                'email' => $pick($field('email'), $field('purchaser_email'), (string) ($customer->email ?? '')),
                'phone' => $pick($field('phone'), (string) ($customer->phone ?? '')),
            ];
        }

        $user = $request->user();

        return [
            'name' => (string) ($user->name ?? 'Customer'),
            'email' => (string) ($user->email ?? ''),
            'phone' => $field('phone'),
        ];
    }

    /**
     * Store orders need the diner's email and phone (the order pay page always sends them). Null when fine, else the
     * answer to give (first validation error, back to the form) — unchanged from the controllers.
     */
    public static function requireOrderContact(Transaction $transaction): ?Response
    {
        if (! Settlement::isOrder($transaction)) {
            return null;
        }
        $validator = Validator::make(request()->all(), ['email' => 'required|email', 'phone' => 'required|string']);
        if (! $validator->fails()) {
            return null;
        }
        app_alert_error($validator->errors()->first());

        return back()->withInput();
    }
}
