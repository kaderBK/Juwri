<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Http\Controllers\User;

use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Concerns\ResolvesCurrentStore;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Payments\Models\Dispute;
use Stripe\StripeClient;

/**
 * GAPS-RUN-2 B11-P1: owner disputes list — status, amount, deadline countdown,
 * linked order, Stripe-dashboard deep link. Tenant scoped; behind the disputes
 * kill-switch.
 *
 * WIRING-RUN E6: evidence submission is no longer deferred. Owners can send Stripe's
 * text evidence fields from here instead of being told to go to the Stripe dashboard.
 * File-upload evidence (receipts, signed delivery proof) still needs the dashboard —
 * that requires Stripe's file-upload API and a storage path, which is a separate job.
 */
class DisputeController extends Controller
{
    use ResolvesCurrentStore;

    /**
     * Stripe's free-text evidence fields. Deliberately a fixed allow-list: whatever the
     * form posts is forwarded to Stripe verbatim, so it must never be caller-controlled.
     * https://docs.stripe.com/api/disputes/update
     */
    private const EVIDENCE_FIELDS = [
        'product_description',
        'customer_name',
        'customer_email_address',
        'billing_address',
        'shipping_address',
        'shipping_carrier',
        'shipping_tracking_number',
        'service_date',
        'refund_policy_disclosure',
        'cancellation_policy_disclosure',
        'uncategorized_text',
    ];

    public function index(Request $request)
    {
        $rid = $this->currentRestaurantId($request);

        $disputes = Dispute::where('store_id', $rid)
            ->orderByRaw('closed_at is null desc')
            ->orderBy('evidence_due_at')
            ->limit(100)
            ->get();

        return view('owner.orders.disputes', [
            'rid' => $rid,
            'disputes' => $disputes,
            'evidenceFields' => self::EVIDENCE_FIELDS,
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }

    /**
     * WIRING-RUN E6: submit text evidence to Stripe and mark the dispute as submitted.
     *
     * Tenant scoping is the whole security story here — the dispute is resolved through
     * the CURRENT restaurant, never from the posted id alone, so an owner cannot submit
     * evidence against another tenant's dispute.
     */
    public function submitEvidence(Request $request, $dispute)
    {
        $rid = $this->currentRestaurantId($request);

        $dispute = Dispute::where('id', (int) $dispute)
            ->where('store_id', $rid)
            ->firstOrFail();

        if (! $dispute->isOpen()) {
            return back()->with('error', ___('This dispute is already closed.'));
        }

        $rules = [];
        foreach (self::EVIDENCE_FIELDS as $field) {
            $rules[$field] = ['nullable', 'string', 'max:20000'];
        }
        $data = $request->validate($rules);

        $evidence = array_filter($data, fn ($v) => is_string($v) && trim($v) !== '');
        if (! $evidence) {
            return back()->with('error', ___('Add at least one piece of evidence before submitting.'));
        }

        $secret = $this->stripeSecret($rid);
        if (! $secret) {
            return back()->with('error', ___('Connect Stripe for this store before submitting evidence.'));
        }

        try {
            $client = new StripeClient($secret);
            $client->disputes->update($dispute->external_id, ['evidence' => $evidence]);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', ___('Stripe rejected the evidence: ').$e->getMessage());
        }

        $dispute->update(['evidence_submitted_at' => now()]);

        return back()->with('success', ___('Evidence submitted to Stripe.'));
    }

    /** Per-restaurant key first (BYOK), else the platform key — same order as StripeController. */
    private function stripeSecret(int $rid): ?string
    {
        $own = store_options($rid)->restaurant_stripe_secret_key ?? null;
        if (is_string($own) && trim($own) !== '') {
            return $own;
        }
        $platform = config('settings.stripe_secret_key');

        return (is_string($platform) && trim($platform) !== '') ? $platform : null;
    }
}
