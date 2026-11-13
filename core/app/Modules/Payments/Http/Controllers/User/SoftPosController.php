<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Http\Controllers\User;

use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Payments\Services\Terminal\SoftPos\SoftPos;
use Symfony\Component\HttpFoundation\Response;

/** P5-21: a SoftPOS provider's callback to one store's URL (404 while the provider is off; signature with that store's secret). */
class SoftPosController extends Controller
{
    public function webhook(Request $request, string $provider, int $store, SoftPos $softPos): Response
    {
        abort_unless(isset(SoftPos::PROVIDERS[$provider]), 404);

        return $softPos->webhook($store, $provider, $request);
    }
}
