<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services;

use Illuminate\Support\Collection;
use Juwri\Modules\Payments\Contracts\CustomerGateways;

/**
 * P1-23 — the gateways a store's OWN customers may pay the store through outside an order: a gift card, a store-credit
 * top-up, a reservation or catering deposit ({@see StorePayments::METHODS}).
 *
 * The promise: "your customers' money goes directly to your gateway account; juwri never holds it" (website, Terms
 * 4.3). These flows used to list the PLATFORM's installed gateways (`payment_gateways.payment_install`) and the
 * adapters charged them with the platform's credentials — on juwri Cloud the diner paid JUWRI. They now list only
 * the gateways the store itself switched on (`restaurant_<folder>_install`, the order pay page's source) whose
 * adapter charges a store payment with the store's credentials ({@see Gateways\Settlement::usesStoreGateway()}):
 * the GatewayAdapter family. A gateway on a hand-written controller would charge the platform's account, so it is
 * never offered here. P1-23 part 5: Tap, Paymob, Tabby, Tamara, Geidea, Paylink and noon moved to adapters; part 6:
 * Fawry, Fatora, eCash, QRPay, TDSP, Flutterwave and the manual wallet / wire transfers (confirmed by the store owner).
 * The BNPL ones (Tabby, Tamara) also need the owner's BNPL switch ({@see BnplEligibility::availableForStorePayment()}),
 * as at the order checkout. {@see PLATFORM_ONLY} adapters are never offered to a store's customers.
 */
final class StoreCustomerGateways implements CustomerGateways
{
    /**
     * Folders whose controller is a GatewayAdapterController (PaymentMethods\<Studly>Controller) — kept equal to that
     * set by StoreCustomerGatewaysTest. Value = the store-option infix of `restaurant_<infix>_install`.
     */
    public const ADAPTER_FOLDERS = [
        'paypal' => 'paypal', 'stripe' => 'stripe', 'razorpay' => 'razorpay', 'mollie' => 'mollie', 'paytm' => 'paytm',
        'paystack' => 'paystack', 'payumoney' => 'payumoney', 'iyzico' => 'iyzico', 'midtrans' => 'midtrans',
        'paytabs' => 'paytabs', 'telr' => 'telr', 'ccavenue' => 'ccavenue', 'two_checkout' => '2checkout',
        // P1-23 part 5 (MENA)
        'tap' => 'tap',
        'geidea' => 'geidea',
        'paymob' => 'paymob',
        'tabby' => 'tabby',
        'tamara' => 'tamara',
        'paylink' => 'paylink',
        'noon' => 'noon',
        'moyasar' => 'moyasar', // Phase 4 item 18
        // P1-23 part 6
        'fawry' => 'fawry',
        'fatora' => 'fatora',
        'ecash' => 'ecash',
        'qrpay' => 'qrpay',
        'tdsp' => 'tdsp',
        'flutterwave' => 'flutterwave',
        // manual tenders: the store's own wallet numbers / bank details; only the store owner confirms (StoreTransfers)
        'wallet_transfer' => 'wallet_transfer',
        'wire_transfer' => 'wire_transfer',
    ];

    /**
     * P1-23 part 6: adapters that only ever take PLATFORM purchases (plans, add-ons) with the platform's keys — never an
     * order or a store payment. GoCardless is a Direct Debit mandate flow (collected days later, confirmed by a later
     * webhook; stores have no GoCardless settings), see GocardlessAdapter.
     */
    public const PLATFORM_ONLY = ['gocardless'];

    /**
     * The gateways to offer, shaped like the PaymentGateway rows the views used (id = folder).
     *
     * @return Collection<int, object{id: string, payment_folder: string, payment_title: string}>
     */
    public function forStore(int $storeId): Collection
    {
        $options = store_options($storeId);
        /** @var list<object{id: string, payment_folder: string, payment_title: string}> $out */
        $out = [];
        foreach (self::ADAPTER_FOLDERS as $folder => $infix) {
            if (empty($options->{'restaurant_'.$infix.'_install'})) {
                continue;
            }
            if (in_array($folder, BnplEligibility::GATEWAYS, true) && ! BnplEligibility::availableForStorePayment($storeId, $folder)) {
                continue;
            }
            $title = trim((string) ($options->{'restaurant_'.$infix.'_title'} ?? ''));
            $out[] = (object) [
                'id' => $folder,
                'payment_folder' => $folder,
                'payment_title' => $title !== '' ? $title : ucwords(str_replace('_', ' ', $folder)),
            ];
        }

        return collect($out);
    }

    /** The chosen gateway's folder when the store offers it to its customers, else null. */
    public function resolve(int $storeId, mixed $choice): ?string
    {
        $choice = is_scalar($choice) ? (string) $choice : '';

        return $this->forStore($storeId)->firstWhere('id', $choice)?->payment_folder;
    }
}
