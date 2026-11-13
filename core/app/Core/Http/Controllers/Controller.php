<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    /**
     * R3f: the resolved storefront/admin theme path. 21 controllers assign
     * `$this->activeTheme = active_theme()`; declaring it here stops each of those
     * assignments from creating a dynamic property (an E_DEPRECATED under PHP 8.3
     * that becomes fatal in 9). Empty until a controller sets it — behaviour
     * unchanged.
     */
    protected string $activeTheme = '';
}
