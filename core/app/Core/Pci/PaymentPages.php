<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Pci;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Juwri\Core\Http\Middleware\AddPaymentPageCsp;

/**
 * Which payment page (PCI DSS 4.0 6.4.3) the current response renders. A view composer on every inventory view
 * marks the request; {@see AddPaymentPageCsp} then sends that page's strict CSP. No
 * route has to remember a middleware: a gateway hand-off rendered from any checkout (plans, orders, gift cards,
 * deposits, top-ups) is covered the moment its view is in the inventory.
 */
final class PaymentPages
{
    public const ATTRIBUTE = 'pci.payment_page';

    public static function register(): void
    {
        $views = PaymentScriptInventory::views();
        if ($views === []) {
            return;
        }
        View::composer($views, function (\Illuminate\View\View $view): void {
            self::mark(PaymentScriptInventory::pageForView($view->name()));
        });
    }

    /** Mark the current request as payment page $key (the first payment view rendered wins). */
    public static function mark(?string $key): void
    {
        if ($key === null || ! app()->bound('request')) {
            return;
        }
        $request = request();
        if (! $request->attributes->has(self::ATTRIBUTE)) {
            $request->attributes->set(self::ATTRIBUTE, $key);
        }
    }

    public static function current(Request $request): ?string
    {
        foreach ([$request, app()->bound('request') ? app('request') : null] as $candidate) {
            $key = $candidate instanceof Request ? $candidate->attributes->get(self::ATTRIBUTE) : null;
            if (is_string($key) && $key !== '') {
                return $key;
            }
        }

        return null;
    }
}
