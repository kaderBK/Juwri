<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Http\Controllers\User;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Payments\Contracts\StoreTransfers;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Gateways\Settlement;

/**
 * P1-23 part 6 — manual transfers of a store's OWN customers' payments (gift cards, store-credit top-ups, deposits):
 *  - claim (public, signed URL from the wallet-transfer page): the customer names the wallet + transaction id they
 *    sent from — recorded as a CLAIM for the owner to check, nothing settles;
 *  - confirm / reject (the store owner, from the Order Hub): only the owner of the payment's store; the platform never.
 */
class StoreTransferController extends Controller
{
    public function claim(Request $request, Transaction $transaction): RedirectResponse
    {
        if (! Settlement::isStorePayment($transaction)
            || $transaction->transaction_gatway !== 'wallet_transfer'
            || $transaction->status !== Transaction::STATUS_PENDING) {
            abort(422, ___('This payment can no longer be claimed.'));
        }
        if (! empty(optional($transaction->details)->claimed_at)) {
            abort(422, ___('A payment claim has already been submitted for this payment.'));
        }
        $store = Store::find($transaction->store_id);
        abort_if($store === null, 404);

        // Only wallets the store configured are valid claim sources.
        $opts = store_options($store->id);
        $configured = [];
        foreach (['syriatel' => 'syriatel_number', 'mtn' => 'mtn_number', 'shamcash' => 'shamcash_number'] as $key => $field) {
            if (! empty($opts->{'restaurant_wallet_transfer_'.$field})) {
                $configured[] = $key;
            }
        }
        $validator = Validator::make($request->all(), [
            'txn_id' => 'required|string|max:120',
            'wallet' => [$configured === [] ? 'nullable' : 'required', 'string', Rule::in($configured ?: ['syriatel', 'mtn', 'shamcash'])],
        ]);
        if ($validator->fails()) {
            app_alert_error($validator->errors()->first());

            return back()->withInput();
        }

        $details = (array) ($transaction->details ?? []);
        $details['wallet'] = $request->input('wallet');
        $details['txn_id'] = trim((string) $request->input('txn_id'));
        $details['claimed_at'] = now()->toIso8601String();
        $transaction->update(['details' => $details]);

        app_alert_info(___('Thanks — we sent your payment details to the store. Your payment is confirmed once they verify the transfer.'));

        return redirect(route('publicView', $store->slug));
    }

    public function confirm(Request $request, Transaction $transaction): JsonResponse|RedirectResponse
    {
        return $this->answer($request, app(StoreTransfers::class)->confirm($transaction, (int) auth()->id()), ___('Payment confirmed.'));
    }

    public function reject(Request $request, Transaction $transaction): JsonResponse|RedirectResponse
    {
        return $this->answer($request, app(StoreTransfers::class)->reject($transaction, (int) auth()->id()), ___('Payment rejected.'));
    }

    private function answer(Request $request, bool $done, string $message): JsonResponse|RedirectResponse
    {
        if (! $done) {
            // Not this owner's pending store payment (another store's, a platform purchase, already handled): no change.
            $refused = ___('This payment is not waiting for your confirmation.');
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => $refused], 403);
            }
            abort(403, $refused);
        }
        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['success' => true, 'message' => $message]);
        }
        app_alert_success($message);

        return back();
    }
}
