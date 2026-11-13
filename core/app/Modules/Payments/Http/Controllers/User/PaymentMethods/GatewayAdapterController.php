<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods;

use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\GatewayCurrencies;
use Juwri\Modules\Payments\Services\Gateways\GatewayAdapter;
use Juwri\Modules\Payments\Services\Gateways\StoreWebhooks;
use Symfony\Component\HttpFoundation\Response;

/**
 * The thin entry point every adapter-backed gateway shares (WI-06).
 *
 * Checkouts resolve `PaymentMethods\<Folder>Controller` from a gateway's
 * `payment_folder` and call `::pay($transaction)`; the `ipn/{gateway}` and
 * `webhook/{gateway}` routes reach `::ipn()` / `::webhook()` through
 * PaymentMethods\PaymentController. A gateway's controller only names its
 * adapter; the adapter does the work.
 */
abstract class GatewayAdapterController extends Controller
{
    /** @return class-string<GatewayAdapter> */
    abstract protected static function adapterClass(): string;

    public static function adapter(): GatewayAdapter
    {
        return app(static::adapterClass());
    }

    public static function pay(Transaction $transaction): Response
    {
        $adapter = static::adapter();
        // A gateway that cannot charge the transaction's own currency refuses it before any provider call.
        $refusal = GatewayCurrencies::refusal($adapter->key(), $transaction);
        if ($refusal !== null) {
            app_alert_error($refusal);

            return back();
        }

        return $adapter->initiate($transaction);
    }

    public static function ipn(Request $request): Response
    {
        return static::adapter()->handleReturn($request);
    }

    public static function webhook(Request $request): Response
    {
        return static::adapter()->handleWebhook($request);
    }

    /** `webhook/{gateway}/store/{store}`: a store's own provider account notifying (404 for gateways without it). */
    public static function storeWebhook(Request $request, int $storeId): Response
    {
        $adapter = static::adapter();
        abort_unless($adapter instanceof StoreWebhooks, 404);

        return $adapter->handleStoreWebhook($request, $storeId);
    }
}
