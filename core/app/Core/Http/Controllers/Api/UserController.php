<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Controllers\Api;

use Juwri\Core\Http\Controllers\Controller;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * Return the authenticated API user.
     */
    public function show(Request $request)
    {
        return $request->user();
    }
}
