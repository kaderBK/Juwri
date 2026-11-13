<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Http\Controllers\User;

use App\Models\Order;
use App\Models\StoreOption;
use App\Providers\E2eStubServiceProvider;
use App\Services\KdsService;
use App\Services\Loyalty\LoyaltyAccrual;
use App\Services\OrderApiService;
use App\Services\StorefrontToggleMask;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\RefundSwitches;
use Juwri\Modules\Ordering\Contracts\DeliverySlotBook;
use Juwri\Modules\Ordering\Services\OrderPaidSideEffects;
use Juwri\Modules\Ordering\Services\Orders\NewOrderAlert;
use Juwri\Modules\Ordering\Services\Payments\HostedOrderPayments;
use Juwri\Modules\Ordering\Services\Payments\LatePayments;
use Juwri\Modules\Payments\Contracts\Gateways;
use Juwri\Modules\Payments\Contracts\Outbox;
use Juwri\Modules\Payments\Data\OrderPayLink;
use Juwri\Modules\Payments\Models\BillingReceipt;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Modules\Payments\Models\Transaction;

/**
 * The diner's side of an online order payment: the pay page listing the
 * store's gateways, the hand-off to the chosen gateway, the wallet-transfer
 * claim, and paySuccess() which every gateway calls once the order is paid.
 *
 * Only `order` transactions of a store whose owner account is active and on
 * an enabled plan can be paid here (an order transaction's `store_id` is the store).
 */
class PaymentController extends Controller
{
    /** Store gateways offered on the pay page, in display order (folder = option infix). */
    public const STORE_GATEWAYS = [
        'paypal', 'stripe', 'razorpay', 'mollie', 'paytm', 'paystack', 'payumoney', 'iyzico', 'midtrans', 'paytabs', 'telr', 'ccavenue',
        'tap', 'flutterwave', // #65: regional gateways
        'tdsp', // TDSP / T-Lync (Libya)
        // PAYMENTS-RUN: 9 new gateways (drivers auto-dispatched by folder).
        'paylink', 'paymob', 'geidea', 'tamara', 'tabby', 'fawry', 'fatora', 'noon',
        'moyasar', // Phase 4 item 18 (KSA)
        // Syria gateways (wallet_transfer = manual tender; ecash/qrpay = redirect acquirers).
        'wallet_transfer', 'ecash', 'qrpay',
    ];

    /** P6-254: order statuses that can no longer take a payment (cancelled, rejected or refunded). */
    public const CLOSED_ORDER_STATUSES = ['cancelled', 'canceled', 'cancel', 'rejected', 'declined', 'refunded'];

    /** P6-254: has this order payment already been taken (settled, consumed or a card hold authorised)? */
    public static function alreadyPaid(Transaction $transaction, ?Order $order): bool
    {
        $status = (string) $transaction->status;

        return in_array($status, [Transaction::STATUS_SUCCESS, Transaction::STATUS_CONSUMED, 'authorised'], true)
            || ($order !== null && (int) $order->is_paid === 1);
    }

    /** The pay page for an order transaction. */
    public function index(Request $request, Transaction $transaction): View
    {
        // P6-09 V-15: a phone-agent order's pay page opens only through the signed, expiring SMS link
        // (`payment.phone`, `signed` middleware) — the plain sequential-id URL is guessable.
        if (! $request->routeIs('payment.phone') && OrderPayLink::isPhoneOrder($transaction)) {
            abort(403);
        }
        [$store, $plan] = $this->payableStore($transaction);

        $postOptions = store_options($store->id);
        // F7-11: storefront read path — mask plan-gated toggles centrally.
        $postOptions = StorefrontToggleMask::apply($postOptions, (int) $store->id);
        $theme = resolve_store_theme($postOptions->store_template ?? null, $store->store_type ?? null);
        $postOptions->store_template = $theme;

        /* prices on the page are in the store's currency */
        remember_platform_currency(); // bug batch 10 · 1
        config([
            'settings.currency_sign' => $postOptions->currency_sign ?? null,
            'settings.currency_pos' => $postOptions->currency_pos ?? null,
            'settings.currency_code' => $postOptions->currency_code ?? null,
        ]);
        // Gateway-currency bug fix: the amount is in the transaction's own currency; when the store's currency
        // changed since the order was placed, show the transaction's ISO code instead of the store's new sign.
        $txCurrency = app(Gateways::class)->transactionCurrency($transaction);
        if ($txCurrency !== app(Gateways::class)->accountCurrency($transaction, $txCurrency)) {
            config(['settings.currency_code' => $txCurrency, 'settings.currency_sign' => $txCurrency]);
        }

        return view('store_templates.payment', [
            'transaction' => $transaction,
            'store' => $store,
            'postOptions' => $postOptions,
            'theme' => $theme,
            'plan' => $plan,
            'paymentGateways' => $this->transactionGateways($store, $postOptions, $transaction),
        ]);
    }

    /** Hand the order transaction to the gateway the diner picked. */
    public function pay(Request $request, Transaction $transaction)
    {
        [$store] = $this->payableStore($transaction);

        $folder = (string) $request->input('payment_method');
        $offered = array_column($this->transactionGateways($store, store_options($store->id), $transaction), 'payment_folder');
        $controller = app(Gateways::class)->controllerFor($folder);

        if (! in_array($folder, $offered, true) || ! method_exists($controller, 'pay')) {
            app_alert_error(___('Please choose a valid payment method.'));

            return redirect(OrderPayLink::url($transaction));
        }

        return $controller::pay($transaction);
    }

    /**
     * The store behind an order transaction and its owner's plan; 404 unless it
     * is an order of a store whose owner is active on an enabled plan.
     *
     * @return array{0: Store, 1: mixed}
     */
    private function payableStore(Transaction $transaction): array
    {
        abort_unless($transaction->transaction_method === 'order', 404);

        // P6-254: a settled payment or a dead order is never handed to a gateway again — the gateway would take a
        // second charge that Settlement::claimPaid then refuses to book (never recorded, never refunded).
        $order = Order::find($transaction->product_id);
        abort_if(self::alreadyPaid($transaction, $order), 409, ___('This order has already been paid.'));

        // P3-06b (API-10): a headless order's hosted payment page takes payment only inside its 15-minute window.
        if ($order !== null && $order->payment_mode === 'hosted') {
            $hosted = app(HostedOrderPayments::class);
            if ($order->status === 'unpaid') {
                $hosted->expire($order); // no-op while the window is open
            }
            abort_unless($hosted->isOpen($order), 410, ___('This payment link has expired. Please place your order again.'));
        }
        abort_if($order !== null && in_array((string) $order->status, self::CLOSED_ORDER_STATUSES, true), 410, ___('This order was cancelled and can no longer be paid.'));

        $store = Store::find($transaction->store_id);
        $owner = $store?->user;
        abort_unless($owner !== null && $owner->status == 1, 404);

        // check the owner's plan is enabled
        $plan = $owner->plan();
        abort_unless(! empty($plan->status), 404);

        return [$store, $plan];
    }

    /**
     * P3-06b: can an online payment of `$orderTotal` (major units, store currency) be taken for this store on the
     * pay page — at least one of its gateways is switched on for that amount? The headless API offers the `hosted`
     * payment mode only then.
     */
    public static function hasStoreGateway(Store $store, float $orderTotal, bool $authorising = false): bool
    {
        return (new self)->storeGateways($store, store_options($store->id), $orderTotal, $authorising) !== [];
    }

    /**
     * The gateways this store has switched on (PAYMENTS-RUN: BNPL ones only
     * when the order clears BnplEligibility), then 2Checkout, whose option
     * keys say `2checkout` while its folder is `two_checkout`.
     *
     * @return array<int, object{id: string, payment_folder: string, payment_title: string}>
     */
    private function storeGateways(Store $store, object $postOptions, float $orderTotal, bool $authorising = false): array
    {
        $gateways = $this->offeredGateways($store, $postOptions, $orderTotal);
        if (! $authorising) {
            return $gateways;
        }

        // P3-09 (D7, basket §9): a weighed basket's card hold — only gateways that authorise now and capture later
        $holds = app(Gateways::class);

        return array_values(array_filter($gateways, fn (object $g) => $holds->authorises((string) $g->payment_folder)));
    }

    /**
     * The gateways the pay page offers for a transaction: a weighed basket's card hold (`details.capture = manual`)
     * lists only the gateways that can hold and capture, unless the store refunds the weighed difference (D7) — then
     * any gateway may charge the hold amount up front.
     *
     * @return array<int, object{id: string, payment_folder: string, payment_title: string}>
     */
    private function transactionGateways(Store $store, object $postOptions, Transaction $transaction): array
    {
        $holds = data_get($transaction->details, 'capture') === 'manual'
            && ! RefundSwitches::allows((int) $store->id, 'weighed_difference');

        return $this->storeGateways($store, $postOptions, (float) $transaction->amount, $holds);
    }

    /** @return array<int, object{id: string, payment_folder: string, payment_title: string}> */
    private function offeredGateways(Store $store, object $postOptions, float $orderTotal): array
    {
        $gateways = [];
        foreach (self::STORE_GATEWAYS as $folder) {
            if (empty($postOptions->{'restaurant_'.$folder.'_install'})) {
                continue;
            }
            // BNPL gateways only surface when the basket clears the
            // owner threshold AND BNPL is enabled (single source of
            // truth: BnplEligibility).
            if (in_array($folder, app(Gateways::class)->bnplGateways(), true)
                && ! app(Gateways::class)->bnplAvailableForOrder($store, $orderTotal, $folder)) {
                continue;
            }
            $gateways[] = (object) [
                'id' => $folder,
                'payment_folder' => $folder,
                'payment_title' => $postOptions->{'restaurant_'.$folder.'_title'} ?? ucfirst($folder),
            ];
        }

        if (! empty($postOptions->restaurant_2checkout_install)) {
            $gateways[] = (object) [
                'id' => 'two_checkout',
                'payment_folder' => 'two_checkout',
                'payment_title' => $postOptions->restaurant_2checkout_title ?? '2Checkout',
            ];
        }

        // E2E-only fake gateway (E2eStubServiceProvider): offered only while the stubs are armed
        // (local/testing + E2E_STUBS) AND an installed `stub` catalog row exists — a database that only an
        // E2E seeder ever writes. In every other environment this adds nothing.
        if (E2eStubServiceProvider::armed()
            && PaymentGateway::query()->where('payment_folder', 'stub')->where('payment_install', '1')->exists()) {
            $gateways[] = (object) [
                'id' => 'stub',
                'payment_folder' => 'stub',
                'payment_title' => 'E2E Stub Gateway',
            ];
        }

        return $gateways;
    }

    /**
     * Syria A3 — wallet_transfer customer claim.
     *
     * The customer transferred money in their wallet app and submits the wallet +
     * transaction-id they were shown. This does NOT settle the order — it records
     * the CLAIM on the transaction and pings the owner to confirm it against their
     * wallet SMS (A4). The txn-id is an unverified claim, never proof of payment.
     */
    public function claim(Request $request, Transaction $transaction): RedirectResponse
    {
        // Hard invariants — a 4xx that settles nothing. Only a PENDING
        // wallet_transfer order transaction that has not already been claimed
        // can accept a claim.
        if ($transaction->transaction_gatway !== 'wallet_transfer'
            || $transaction->transaction_method !== 'order'
            || $transaction->status !== Transaction::STATUS_PENDING) {
            abort(422, ___('This payment can no longer be claimed.'));
        }

        // Already claimed? (details is 'object'-cast; guard against a re-submit.)
        if (! empty(optional($transaction->details)->claimed_at)) {
            abort(422, ___('A payment claim has already been submitted for this order.'));
        }

        $store = Store::find($transaction->store_id);
        if (! $store) {
            abort(404);
        }

        // Only wallets the store actually configured are valid claim sources.
        $configured = [];
        $opts = store_options($store->id);
        foreach (['syriatel' => 'syriatel_number', 'mtn' => 'mtn_number', 'shamcash' => 'shamcash_number'] as $key => $field) {
            if (! empty($opts->{'restaurant_wallet_transfer_'.$field})) {
                $configured[] = $key;
            }
        }

        $validator = Validator::make($request->all(), [
            'txn_id' => 'required|string|max:120',
            'wallet' => [empty($configured) ? 'nullable' : 'required', 'string', Rule::in($configured ?: ['syriatel', 'mtn', 'shamcash'])],
        ]);
        if ($validator->fails()) {
            app_alert_error($validator->errors()->first());

            return back()->withInput();
        }

        // Trap #1: `details` is cast 'object' — assign a RAW ARRAY (json_encode would
        // double-encode). Merge so we never clobber whatsapp_url / customer_name.
        $details = (array) ($transaction->details ?? []);
        $details['wallet'] = $request->input('wallet');
        $details['txn_id'] = trim((string) $request->input('txn_id'));
        $details['claimed_at'] = now()->toIso8601String();
        $transaction->update(['details' => $details]);

        // Ping the store owner to confirm the claim in the Order Hub.
        $order = Order::find($transaction->product_id);
        create_notification(
            ___('A customer submitted a wallet-transfer payment to confirm.'),
            'new_payment',
            $order ? route('store.orderTrack', $order->track_token) : null
        );

        app_alert_info(___('Thanks — we sent your payment details to the restaurant. Your order is confirmed once they verify the transfer.'));

        if ($order) {
            return redirect(route('store.orderTrack', $order->track_token));
        }

        return redirect(route('publicView', $store->slug));
    }

    /**
     * Settle a paid order (every gateway calls this once the provider confirms):
     * marks the order paid, credits the store wallet and runs the paid-order
     * side effects once, then prints the hand-off script for the diner.
     */
    public static function paySuccess(Transaction $transaction)
    {
        // MC-02: `consumed` is terminal. A duplicate gateway delivery (or a
        // browser refresh of the return URL) that re-enters here must be a no-op
        // — the order is already paid and the wallet already credited.
        //
        // Replay-safety: providers retry webhooks and ~25 gateways funnel their
        // ipn()/webhook() through here, so two deliveries can arrive concurrently.
        // An in-memory status check let BOTH pass and double-credit the wallet /
        // re-fire order.paid. Claim the transaction ATOMICALLY (single conditional
        // UPDATE, the same idiom as updateUserPlan's credited_at claim) — only the
        // delivery that flips the row to CONSUMED proceeds; the loser is a no-op.
        // NB: a fresh transaction's status is NULL, and SQL `status != 'consumed'`
        // does NOT match NULL rows — so the claim must also accept NULL/non-consumed.
        //
        // J25-7: the side effects below (accounting sync, POS push, GL post, partner webhook) are recorded in the
        // transactional outbox in the SAME DB transaction as the claim, so they exist exactly when the claim does.
        $outbox = app(Outbox::class);
        $sideEffects = [];

        /* P3-06b (API-10, API-21): a headless order paid on this page has a 15-minute window. A window that ended
           before this confirmation (swept or not yet) makes it a LATE payment: the order stays cancelled, the money is
           recorded for the owner to accept or refund (LatePayments), and none of the paid-order side effects run.
           The decision is taken under the order's row lock, in the claim's transaction, so the expiry sweep and this
           settlement can never both win. Every other order settles exactly as before. */
        $hostedOrder = Order::find($transaction->product_id);
        $hosted = $hostedOrder !== null && $hostedOrder->payment_mode === 'hosted';
        if ($hosted && $hostedOrder->status === 'unpaid') {
            app(HostedOrderPayments::class)->expire($hostedOrder); // no-op while the window is open
        }
        $late = false;
        // P3-09: the capture of a weighed basket's card hold — the order went ahead when the hold was authorised
        // (CardHoldOrders::authorised announced it); this settles the captured amount without announcing it again.
        $capturedHold = $hosted && $hostedOrder->getAttribute('hold_status') === 'captured';

        $claimed = DB::transaction(function () use ($transaction, $outbox, &$sideEffects, $hosted, $hostedOrder, &$late, $capturedHold) {
            // P6-254: every order is locked — a non-hosted order cancelled while its pay page was open is a late
            // payment too (never revived to `pending`, the owner accepts or refunds it).
            $locked = $hostedOrder !== null ? Order::query()->whereKey($transaction->product_id)->lockForUpdate()->first() : null;
            $claimed = Transaction::whereKey($transaction->id)
                ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', Transaction::STATUS_CONSUMED))
                ->update(['status' => Transaction::STATUS_CONSUMED]);
            if ($claimed === 1) {
                $late = LatePayments::isLate($locked);
                if (! $late) {
                    $sideEffects = OrderPaidSideEffects::record($outbox, $transaction);
                }
                if ($hosted && ! $late && $locked !== null && $locked->status === 'unpaid') {
                    $locked->forceFill(['is_paid' => 1, 'payment_gateway' => $transaction->transaction_gatway, 'status' => 'pending'])->save();
                } elseif ($capturedHold && ! $late && $locked !== null) {
                    $locked->forceFill(['is_paid' => 1, 'payment_gateway' => $transaction->transaction_gatway])->save();
                }
            }

            return $claimed;
        });
        if ($claimed !== 1) {
            return;
        }
        $transaction->status = Transaction::STATUS_CONSUMED;

        if ($late && $hostedOrder !== null) {
            app(LatePayments::class)->receive($hostedOrder, $transaction);
            // The money did arrive in the store's gateway account: the store wallet and the receipt record it like any
            // payment; the order's own side effects wait for the owner's decision.
            StoreOption::incrementStoreOption((int) $transaction->store_id, 'wallet_amount', (float) $transaction->amount);
            echo self::handOffScript($transaction, $hostedOrder->fresh(), Store::query()->find($transaction->store_id));
            BillingReceipt::recordFromTransaction($transaction);
            $transaction->update(['status' => Transaction::STATUS_CONSUMED]);

            return;
        }

        // the order is paid; it now waits in the kitchen queue
        $paidOrder = Order::find($transaction->product_id);
        if (! $hosted) {
            $paidOrder?->update([
                'is_paid' => 1,
                'payment_gateway' => $transaction->transaction_gatway,
                'status' => 'pending',
            ]);
            // P6-255: the payment is confirmed (claimed once above) - announce the order now.
            if ($paidOrder) {
                NewOrderAlert::send($paidOrder->refresh());
            }
        } elseif ($paidOrder !== null && ! $capturedHold) {
            // P3-09: its delivery / collection slot is the order's for good now
            app(DeliverySlotBook::class)->settleForOrder($paidOrder);
            // P3-06b: a hosted order was kept out of the kitchen and unannounced while it waited (OrderApiService
            // `awaiting_payment`); now it is paid, it reaches the KDS and is announced once.
            try {
                KdsService::syncOrderStatus($paidOrder->id);
                $paidOrder->refresh();
            } catch (\Throwable $e) {
                report($e);
            }
            $owner = (int) (Store::query()->whereKey($paidOrder->store_id)->value('user_id') ?? 0);
            OrderApiService::announceCreated($owner, (int) $paidOrder->store_id, $paidOrder, (string) ($paidOrder->source ?: 'storefront_api'));
        }

        $store_id = (int) $transaction->store_id;
        // Atomic accumulation: two distinct paid orders for this store settling
        // concurrently must not lose one via a read-modify-write on wallet_amount.
        StoreOption::incrementStoreOption($store_id, 'wallet_amount', (float) $transaction->amount);

        $store = Store::query()->find($store_id);
        $order = Order::find($transaction->product_id);

        /* A2 vesting: an online order earns its loyalty points on PAYMENT, not at
           placement — so an abandoned unpaid order never earns. This success path is
           already replay-claimed above (consumed-status CAS), and earn is idempotent
           per order, so it credits exactly once. Self-guarded. Covers storefront AND
           marketplace online orders (both settle through here). */
        if ($order) {
            LoyaltyAccrual::earnForOrder($order);
        }

        // #98 accounting sync, B1 POS push, A-P1 GL post, #99 partner `order.paid` webhook — performed from the outbox
        // here, where they always ran (J25-7; see OrderPaidSideEffects). A failing one is retried by the relay. After
        // commit: inside the durable credit (Settlement::settle) a credit attempt that rolls back performs none of them.
        $outbox->relayAfterCommit($sideEffects);

        echo self::handOffScript($transaction, $order, $store);

        /* MC-02: keep the charge as durable history instead of destroying it.
           Write the receipt first, then mark the transaction consumed (terminal). */
        BillingReceipt::recordFromTransaction($transaction);
        $transaction->update(['status' => Transaction::STATUS_CONSUMED]);
    }

    /**
     * The script paySuccess prints for the returning diner: opens the order's
     * WhatsApp hand-off (http/https links only) and moves on to order tracking
     * (or the store page when the order is gone). Every value is JSON-encoded
     * with HTML-significant characters escaped, so nothing in the order data
     * can end the string or the script element.
     */
    public static function handOffScript(Transaction $transaction, ?Order $order, ?Store $store): string
    {
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

        // P3-06b: a headless storefront order goes back to the client's return_url (its origin was checked against
        // the storefront token's allowed origins when the order was placed); the client polls the order for the outcome.
        $returnUrl = (string) ($transaction->details->return_url ?? '');
        $next = match (true) {
            preg_match('#^https?://#i', $returnUrl) === 1 => $returnUrl,
            // J25-7 bug fix: an order without a track token sent the diner to a route that cannot be built — the
            // exception came after the order was paid, and inside the durable credit it rolled the credit back.
            $order !== null && (string) $order->track_token !== '' => route('store.orderTrack', $order->track_token),
            $store !== null => route('publicView', $store->slug),
            default => url('/'),
        };

        $js = '';
        $whatsapp = (string) ($transaction->details->whatsapp_url ?? '');
        if (preg_match('#^https?://#i', $whatsapp)) {
            $js .= 'window.open('.json_encode($whatsapp, $flags).', "_blank");';
        }
        $js .= 'location.href = '.json_encode($next, $flags).';';

        $nonce = function_exists('csp_nonce') ? (string) csp_nonce() : '';

        return '<script nonce="'.e($nonce).'">'.$js.'</script>';
    }
}
