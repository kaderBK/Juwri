<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Controllers;

use Juwri\Core\Pci\PaymentPageProbes;
use Symfony\Component\HttpFoundation\Response;

/**
 * PCI DSS 4.0 11.6.1: serves one payment page rendered with a synthetic payment (PaymentPageProbes) to the
 * change-detection job. Signed + expiring URL (route middleware), read-only; 404 when probes are off or the page
 * has no probe here.
 */
class PaymentPageProbeController extends Controller
{
    public function show(string $page): Response
    {
        abort_unless((bool) config('payment_scripts.monitor.probes', true), 404);
        $response = PaymentPageProbes::render($page);
        abort_if($response === null, 404);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
