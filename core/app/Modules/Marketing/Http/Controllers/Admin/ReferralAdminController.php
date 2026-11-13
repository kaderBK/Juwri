<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Marketing\Http\Controllers\Admin;

use App\Models\ReferralConversion;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Payments\Contracts\Wallet;

class ReferralAdminController extends Controller
{
    public function index(Request $request)
    {
        $q = ReferralConversion::with(['referral.user', 'newUser'])
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $q->where('status', $request->get('status'));
        }

        $conversions = $q->paginate(30);

        return view('admin.referrals.index', compact('conversions'));
    }

    public function approve(ReferralConversion $conversion)
    {
        // Reseller v2 P6 (6.2): wire the payout leg — approving a conversion credits
        // the referrer's wallet with the reward and marks it paid. Idempotent: an
        // already-paid conversion never double-credits (the transaction ref is keyed
        // to the conversion id).
        if ($conversion->status === 'paid') {
            app_alert_success('Conversion already paid.');

            return back();
        }

        $referrer = optional($conversion->referral)->user;
        $reward = (float) $conversion->reward_amount;

        if ($referrer && $reward > 0) {
            app(Wallet::class)->credit(
                $referrer,
                $reward,
                'Referral reward',
                'referral-conversion-'.$conversion->id
            );
            $conversion->update([
                'status' => 'paid',
                'approved_at' => $conversion->approved_at ?? now(),
                'paid_at' => now(),
            ]);
            app_alert_success('Conversion approved and reward paid to referrer wallet.');
        } else {
            // No referrer/reward to pay — just approve.
            $conversion->update(['status' => 'approved', 'approved_at' => now()]);
            app_alert_success('Conversion approved.');
        }

        return back();
    }

    public function reject(ReferralConversion $conversion)
    {
        $conversion->update(['status' => 'rejected']);
        app_alert_success('Conversion rejected.');

        return back();
    }
}
