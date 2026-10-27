<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\AppStore\Http\Controllers\User;

use App\Models\App;
use App\Models\Theme;
use App\Services\StorePurchaseService;
use Illuminate\Http\Request;
use Juwri\Core\Contracts\Billing\AddonTax;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Modules\Payments\Models\Transaction;

/**
 * ST4 (features-run-4): the in-dashboard purchase surface for the App Store /
 * Theme Store CTAs. Buy/install run through StorePurchaseService, which reuses
 * the EXISTING wallet debit + top-up choke points — no new gateway/money code.
 *
 * Contract:
 *   - completed / owned → { ok:true, status }
 *   - shortfall         → { ok:false, status:'shortfall', shortfall, topup_url }
 *     the CTA then POSTs to topupContinue() to fund the exact shortfall and,
 *     on gateway success, the purchase auto-completes at the top-up choke point.
 */
class StorePurchaseController extends Controller
{
    /** ST4: buy a marketplace theme license from the dashboard. */
    public function buyTheme(string $slug, StorePurchaseService $store)
    {
        abort_unless(auth()->check(), 403);
        $theme = Theme::where('status', 'published')->where('slug', $slug)->firstOrFail();

        $outcome = $store->attemptTheme(auth()->user(), $theme);

        return $this->respond($outcome, StorePurchaseService::INTENT_THEME, $theme->id);
    }

    /**
     * P4-3 (feature-platform, D1 dual-rail): pay an app's first period / one-time
     * price by CARD directly — a pending 'app_purchase' Transaction hands off to
     * the chosen gateway; on confirmation the updateUserPlan choke point installs
     * the app prepaid. Wallet remains the instant path (installApp below);
     * renewals ride the wallet either way.
     */
    public function buyAppWithCard(Request $request, int $appId)
    {
        abort_unless(auth()->check(), 403);
        $app = App::where('status', 'approved')->findOrFail($appId);
        abort_unless(in_array($app->billing_type, ['recurring', 'one_time']) && $app->price > 0, 422,
            ___('This app has no card-payable price.'));

        $gateway = PaymentGateway::where('id', $request->input('payment_method'))
            ->withoutMor()->where('payment_install', true)->first();
        if (! $gateway) {
            return back()->withErrors(['payment_method' => ___('Please choose a valid payment method.')]);
        }

        $price = (float) $app->price;
        // Bug batch 7 · 1: tax and total in the billing (platform) currency's decimals — KWD fils, JPY yen.
        $currency = platform_currency(); // never the dashboard's working-store swap
        $taxed = app(AddonTax::class)->exclusiveTaxFor(auth()->user(), $price, $currency); // D5: same tax rail

        $transaction = Transaction::create([
            'product_name' => 'App: '.$app->name,
            'product_id' => 0,
            'user_id' => auth()->id(),
            'base_amount' => $price,
            'amount' => money_round($price + $taxed['tax'], money_decimals($currency)),
            'currency_code' => $currency,
            'transaction_method' => 'app_purchase',
            'transaction_ip' => $request->ip(),
            'transaction_description' => 'App purchase: '.$app->slug,
            'frequency' => 'LIFETIME', // card pays this period one-off; renewals ride the wallet
            'details' => ['app_id' => $app->id, 'tax_breakdown' => $taxed['breakdown']],
            'status' => null,
        ]);

        $paymentController = 'App\\Http\\Controllers\\User\\PaymentMethods\\'
            .str_replace(' ', '', ucwords(str_replace('_', ' ', $gateway->payment_folder)))
            .'Controller';

        return $paymentController::pay($transaction);
    }

    /** ST4: install an app (charging its first period) from the dashboard. */
    public function installApp(int $appId, StorePurchaseService $store)
    {
        abort_unless(auth()->check(), 403);
        $app = App::where('status', 'approved')->findOrFail($appId);

        $outcome = $store->attemptApp(auth()->user(), $app);

        return $this->respond($outcome, StorePurchaseService::INTENT_APP, $app->id);
    }

    /**
     * ST4 shortfall path: create a wallet top-up carrying the purchase intent and
     * hand off to the chosen gateway. On gateway success the existing wallet
     * top-up choke point credits the wallet AND auto-completes the purchase.
     */
    public function topupContinue(Request $request, StorePurchaseService $store)
    {
        abort_unless(auth()->check(), 403);

        if (! (bool) config('settings.wallet_topup_enabled', true)) {
            return response()->json(['ok' => false, 'message' => ___('Wallet top-up is currently unavailable.')], 422);
        }

        $data = $request->validate([
            'purchase' => ['required', 'in:theme,app'],
            'id' => ['required', 'integer', 'min:1'],
            'payment_method' => ['required'],
        ]);

        $gateway = PaymentGateway::where('id', $data['payment_method'])
            ->withoutMor()->where('payment_install', true)->first();
        if (! $gateway) {
            return response()->json(['ok' => false, 'message' => ___('Please choose a valid payment method.')], 422);
        }

        $user = auth()->user();

        // Recompute the shortfall server-side — never trust a client amount.
        $outcome = $data['purchase'] === StorePurchaseService::INTENT_THEME
            ? $store->attemptTheme($user, Theme::where('status', 'published')->findOrFail($data['id']))
            : $store->attemptApp($user, App::where('status', 'approved')->findOrFail($data['id']));

        // Balance already covers it (or it's free/owned) → nothing to top up.
        if ($outcome['status'] !== StorePurchaseService::STATUS_SHORTFALL) {
            return response()->json(['ok' => true, 'status' => $outcome['status']]);
        }

        $transaction = $store->makeTopupIntent(
            $user,
            $data['purchase'],
            (int) $data['id'],
            $outcome['shortfall'],
            $gateway->payment_folder,
            $request->ip(),
        );

        if (! $transaction) {
            /* P0-7 (feature-platform): this endpoint is a plain full-page form POST —
               a JSON 422 rendered as raw JSON in the browser (silent dead-end).
               Redirect back with a visible flash instead. */
            $message = ___('That amount is above the maximum single top-up. Please top up your wallet first from the Wallet page, or contact support to raise the limit.');
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'message' => $message], 422);
            }

            return back()->with('store_error', $message);
        }

        $paymentController = 'App\\Http\\Controllers\\User\\PaymentMethods\\'
            .str_replace(' ', '', ucwords(str_replace('_', ' ', $gateway->payment_folder)))
            .'Controller';

        return $paymentController::pay($transaction);
    }

    /** Shape the buy/install JSON response, adding the top-up hand-off on shortfall. */
    private function respond(array $outcome, string $intent, int $id)
    {
        if ($outcome['status'] === StorePurchaseService::STATUS_SHORTFALL) {
            return response()->json([
                'ok' => false,
                'status' => StorePurchaseService::STATUS_SHORTFALL,
                'price' => $outcome['price'],
                'balance' => $outcome['balance'],
                'shortfall' => $outcome['shortfall'],
                'message' => ___('Top up :amount to complete this purchase.', [
                    'amount' => price_symbol_format($outcome['shortfall']),
                ]),
                'topup_url' => route('user.store.topup.continue'),
                'topup_intent' => ['purchase' => $intent, 'id' => $id],
            ], 402);
        }

        return response()->json([
            'ok' => true,
            'status' => $outcome['status'],
            'balance' => $outcome['balance'],
        ]);
    }
}
