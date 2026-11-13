<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Controllers;

use Juwri\Core\Support\OwnerUi;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Living reference for the owner UI kit (assets/owner-ui + <x-ui.*>): every
 * component in every state, rendered inside the real dashboard layout so the
 * theme toggle, RTL locales and brand colour apply exactly as on product
 * screens. Local/testing: any signed-in user. Elsewhere: admins only (404).
 */
class OwnerUiKitController extends Controller
{
    public function show(Request $request): View
    {
        abort_unless(OwnerUi::kitPageAllowed($request->user()), 404);

        return view('owner.ui-kit');
    }
}
