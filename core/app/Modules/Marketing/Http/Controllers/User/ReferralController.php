<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Marketing\Http\Controllers\User;

use Juwri\Core\Http\Controllers\Controller;
use App\Models\Referral;

class ReferralController extends Controller
{
    public function index()
    {
        $user     = auth()->user();
        $referral = Referral::forUser($user);

        $conversions = $referral->conversions()
            ->with('newUser')
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('owner.marketing.referral', compact('referral', 'conversions'));
    }
}
