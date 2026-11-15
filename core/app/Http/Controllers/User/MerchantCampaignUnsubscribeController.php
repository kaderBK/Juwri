<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http\Controllers\User;

use App\Services\MerchantCampaign;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\UserOption;

/**
 * The opt-out link at the bottom of a merchant campaign email.
 *
 * Reached from an inbox, so it is authorised by the signed URL rather than by a
 * session — the merchant is often on a device that is not logged in, and a
 * campaign people cannot switch off is a campaign that gets marked as spam.
 * The signature is what proves the link is theirs; the id alone proves nothing.
 */
class MerchantCampaignUnsubscribeController extends Controller
{
    /**
     * P6-222 pattern: mail scanners open GET links, so GET only shows a
     * confirmation page and POST actually records the opt-out.
     */
    public function unsubscribeConfirm(Request $request, int $user): View
    {
        abort_unless($request->hasValidSignature(), 403);

        return view('auth.merchant-campaign-unsubscribed', [
            'confirmUrl' => $request->fullUrl(),
        ]);
    }

    /** POST from the confirm page or RFC 8058 one-click (List-Unsubscribe-Post). */
    public function unsubscribe(Request $request, int $user): View
    {
        abort_unless($request->hasValidSignature(), 403);

        UserOption::updateUserOption($user, MerchantCampaign::OPT_OUT_KEY, '0');

        return view('auth.merchant-campaign-unsubscribed');
    }
}
