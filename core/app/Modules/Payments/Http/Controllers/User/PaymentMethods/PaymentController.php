<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods;

use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use ReflectionMethod;

/**
 * Provider callbacks for every gateway (WI-06c).
 *
 * `ipn/{gateway}` is where the buyer's browser comes back, `webhook/{gateway}`
 * is where the provider's server notifies us. The segment must name a row in
 * payment_gateways (by payment_folder); the row's folder then picks the
 * gateway's own controller (`two_checkout` → TwoCheckoutController), whose
 * static `ipn()` / `webhook()` answers the request as-is.
 *
 * Unknown folder → 404. A known gateway whose controller has no such public
 * static entry point (e.g. wire transfer has no webhook) → 404 as well, rather
 * than the 500 an undefined-method error used to give. Disabled gateways
 * (payment_install = 0) still receive callbacks for payments already started.
 */
class PaymentController extends Controller
{
    public function ipn(Request $request, string $gateway): mixed
    {
        return $this->forward($request, $gateway, 'ipn');
    }

    public function webhook(Request $request, string $gateway): mixed
    {
        return $this->forward($request, $gateway, 'webhook');
    }

    /**
     * `webhook/{gateway}/store/{store}`: a store's OWN provider account (Stripe, PayPal) notifying — verified with that
     * store's webhook secret only (StoreWebhooks). Unknown gateway or one without store webhooks → 404.
     */
    public function storeWebhook(Request $request, string $gateway, int $store): mixed
    {
        $folder = PaymentGateway::query()->where('payment_folder', $gateway)->value('payment_folder');
        abort_if($folder === null, 404);
        $controller = self::controllerFor((string) $folder);
        abort_unless(self::hasStaticEntryPoint($controller, 'storeWebhook'), 404);

        return $controller::storeWebhook($request, $store);
    }

    /** The controller class that handles a gateway's payment_folder. */
    public static function controllerFor(string $folder): string
    {
        return __NAMESPACE__.'\\'.Str::studly($folder).'Controller';
    }

    private function forward(Request $request, string $segment, string $entryPoint): mixed
    {
        $folder = PaymentGateway::query()->where('payment_folder', $segment)->value('payment_folder');
        abort_if($folder === null, 404);

        $controller = self::controllerFor((string) $folder);
        abort_unless(self::hasStaticEntryPoint($controller, $entryPoint), 404);

        return $controller::$entryPoint($request);
    }

    private static function hasStaticEntryPoint(string $controller, string $entryPoint): bool
    {
        if (! class_exists($controller) || ! method_exists($controller, $entryPoint)) {
            return false;
        }

        $method = new ReflectionMethod($controller, $entryPoint);

        return $method->isPublic() && $method->isStatic();
    }
}
