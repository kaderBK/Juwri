<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services;

use App\Models\App;
use App\Models\AppInstallation;
use App\Models\Theme;
use Juwri\Core\Contracts\Billing\AddonTax;
use Juwri\Core\Models\User;
use Juwri\Core\Money\Currencies;
use Juwri\Core\Money\Money;
use Juwri\Modules\Payments\Contracts\Ledger;
use Juwri\Modules\Payments\Contracts\Wallet;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Models\WalletTransaction;

/**
 * ST4 (features-run-4): store purchase UX orchestration.
 *
 * This service moves NO money of its own — it is a thin decision + resume layer
 * over the EXISTING wallet choke points:
 *   - themes  → ThemePurchaseService::purchase (WalletService::debit inside)
 *   - apps    → AppInstallService::install → AppBillingService::chargeInstall
 *
 * The purchase surfaces call attemptTheme()/attemptApp(). When the wallet can't
 * cover the item, we DO NOT build any new gateway logic: we compute the exact
 * shortfall and hand the buyer to the existing self-service wallet top-up flow
 * (WalletController@topup*), carrying a purchase INTENT on the top-up
 * Transaction's `details`. When the gateway confirms the top-up, the existing
 * choke point (CheckoutController::creditWalletTopup) credits the wallet and
 * then calls resumeFromTopup(), which auto-completes the original purchase.
 *
 * Seam note: because top-up completion is driven by the gateway
 * confirmation/return (not a synchronous call), the purchase is COMPLETED on the
 * top-up-success choke point rather than in the buyer's request. If the buyer
 * abandons the top-up, nothing is bought and any credited balance simply stays
 * in the wallet (surfaced in the UI copy).
 */
class StorePurchaseService
{
    public const STATUS_COMPLETED = 'completed'; // bought/installed now

    public const STATUS_OWNED = 'owned';     // already entitled/installed

    public const STATUS_SHORTFALL = 'shortfall'; // needs a top-up to continue

    public const INTENT_THEME = 'theme';

    public const INTENT_APP = 'app';

    /**
     * Try to license a theme against the wallet right now.
     *
     * @return array{status:string, price:float, balance:float, shortfall:float}
     */
    public function attemptTheme(User $user, Theme $theme): array
    {
        $price = (float) $theme->price;

        if (ThemePurchaseService::entitled($user, $theme)) {
            // Free themes or an already-held license: nothing to charge.
            return $this->result(self::STATUS_OWNED, $price, app(Wallet::class)->balanceFor($user));
        }

        return $this->attempt(
            $user,
            $price,
            fn () => app(ThemePurchaseService::class)->purchase($user, $theme),
        );
    }

    /**
     * Try to install an app against the wallet right now. `recurring` and
     * `one_time` apps carry an upfront charge; free/usage apps install without
     * a wallet gate.
     *
     * @return array{status:string, price:float, balance:float, shortfall:float}
     */
    public function attemptApp(User $user, App $app): array
    {
        /* F4-1 (feature-platform gap fix): the wallet app charge is now taxed
           like the card rail, so the balance pre-check and the quoted shortfall
           must be tax-inclusive too — otherwise a buyer holding exactly the
           list price passed this gate and then hit an "insufficient funds"
           throw inside chargeInstall, surfacing as a shortfall of 0.00.

           F7-12: `one_time` apps were priced at 0.0 here, so they skipped the
           balance pre-check ENTIRELY — AppBillingService::chargeInstall charges
           them at install exactly like recurring, so a buyer who could not
           afford one hit the insufficient-funds throw and was handed a
           shortfall of 0.00, i.e. a top-up CTA asking for nothing. Both paid
           billing types are priced through the same taxed quote now. */
        $chargesUpfront = in_array($app->billing_type, ['recurring', 'one_time'], true);
        $price = $chargesUpfront ? $this->taxedPrice($user, (float) $app->price) : 0.0;

        $active = AppInstallation::where('app_id', $app->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();
        if ($active) {
            return $this->result(self::STATUS_OWNED, $price, app(Wallet::class)->balanceFor($user));
        }

        /* Catalog fix (P4-4 gap): a FIRST install of a trial app charges
           nothing — install() starts the trial — so the wallet-balance
           pre-check must not block it. Reinstalls charge immediately and keep
           the shortfall path. */
        $trialEligible = $app->billing_type === 'recurring'
            && $app->trial_days > 0
            && $price > 0
            && ! AppInstallation::where('app_id', $app->id)->where('user_id', $user->id)->exists();

        /* F7-12 (mirror of the trial carve-out): a `one_time` app is charged once
           per installation row, and install() REUSES that row on reinstall — so
           chargeInstall's ref-idempotent debit charges nothing the second time.
           Quoting the price again would gate a reinstall the buyer already owns. */
        $alreadyPaidOneTime = $app->billing_type === 'one_time'
            && $price > 0
            && $this->oneTimeSettled($app, $user);

        return $this->attempt(
            $user,
            ($trialEligible || $alreadyPaidOneTime) ? 0.0 : $price,
            fn () => app(AppInstallService::class)->install($app, $user),
        );
    }

    /**
     * Run a purchase closure that debits the wallet; on an insufficient-balance
     * failure, report the exact shortfall instead of bubbling the exception.
     *
     * @param  callable():mixed  $complete
     * @return array{status:string, price:float, balance:float, shortfall:float}
     */
    protected function attempt(User $user, float $price, callable $complete): array
    {
        $balance = app(Wallet::class)->balanceFor($user);

        // Balance can't cover it — don't even attempt the debit; report shortfall.
        if ($price > 0 && $balance + 0.001 < $price) {
            return $this->result(self::STATUS_SHORTFALL, $price, $balance);
        }

        try {
            $complete();
        } catch (\RuntimeException $e) {
            // A concurrent debit (or a nested chargeInstall) drained the balance
            // between the read and the write: fall back to the shortfall path.
            if ($this->isInsufficientFunds($e)) {
                return $this->result(self::STATUS_SHORTFALL, $price, app(Wallet::class)->balanceFor($user));
            }
            throw $e;
        }

        return $this->result(self::STATUS_COMPLETED, $price, app(Wallet::class)->balanceFor($user));
    }

    /**
     * Create a pending wallet top-up Transaction that carries the purchase
     * intent, so the existing gateway flow can resume the purchase on success.
     * The amount is the EXACT shortfall, clamped up to the admin top-up minimum
     * (any excess simply stays as wallet credit).
     *
     * Returns the Transaction ready for the gateway hand-off, or null when the
     * shortfall can't be topped up in one go (over the configured max).
     */
    public function makeTopupIntent(User $user, string $intentType, int $intentId, float $shortfall, string $gatewayFolder, string $ip): ?Transaction
    {
        $min = max(0.5, (float) config('settings.wallet_topup_min', 5));
        $max = max($min, (float) config('settings.wallet_topup_max', 1000));

        // Bug batch 7 · 2: the exact shortfall in the wallet currency's exponent (1.297 fils, not 1.30 — and never short).
        $amount = Money::round(max($shortfall, $min), Currencies::exponent(app(Ledger::class)->defaultCurrency()));
        if ($amount > $max + 0.001) {
            return null; // shortfall exceeds a single top-up — caller seams/aborts
        }

        return Transaction::create([
            'product_name' => ___('Wallet top-up'),
            'product_id' => 0,
            'user_id' => $user->id,
            'base_amount' => $amount,
            'amount' => $amount,
            'currency_code' => app(Ledger::class)->defaultCurrency(), // the wallet currency
            'transaction_method' => 'wallet_topup',
            'transaction_gatway' => $gatewayFolder,
            'transaction_ip' => $ip,
            'transaction_description' => ___('Wallet top-up'),
            'frequency' => 'LIFETIME',
            'status' => null,
            'details' => [
                'purchase' => $intentType,     // 'theme' | 'app'
                'id' => $intentId,       // Theme id or App id
            ],
        ]);
    }

    /**
     * Resume a store purchase after its top-up was confirmed + credited.
     * Called from the wallet-top-up choke point (CheckoutController) exactly
     * once per transaction (that choke point is credited_at-guarded), and the
     * underlying purchase/install calls are themselves idempotent — so a
     * replayed gateway callback can never double-buy.
     */
    public static function resumeFromTopup(Transaction $transaction): void
    {
        $intent = $transaction->details->purchase ?? null;
        $id = (int) ($transaction->details->id ?? 0);
        if (! $intent || $id <= 0) {
            return;
        }

        $user = $transaction->user;
        if (! $user) {
            return;
        }

        try {
            if ($intent === self::INTENT_THEME) {
                $theme = Theme::find($id);
                if ($theme) {
                    app(ThemePurchaseService::class)->purchase($user, $theme);
                }
            } elseif ($intent === self::INTENT_APP) {
                $app = App::find($id);
                if ($app && $app->isInstallable()) {
                    app(AppInstallService::class)->install($app, $user);
                }
            }
        } catch (\Throwable $e) {
            // The wallet keeps the credited balance; the buyer can retry the
            // purchase from the store with funds already on hand.
            report($e);
        }
    }

    /**
     * F7-12: has this buyer's one_time charge for this app already settled?
     * Same source of truth the debit itself is idempotent on —
     * `WalletTransaction.ref`, built by AppBillingService::chargeInstall as
     * `app:{installation_id}:onetime`.
     */
    protected function oneTimeSettled(App $app, User $user): bool
    {
        $installationId = AppInstallation::where('app_id', $app->id)
            ->where('user_id', $user->id)
            ->value('id');

        return $installationId
            && WalletTransaction::where('ref', 'app:'.$installationId.':onetime')->exists();
    }

    /** F4-1: list price + the buyer's exclusive plan taxes (same rail as the charge). */
    protected function taxedPrice(User $user, float $price): float
    {
        if ($price <= 0) {
            return 0.0;
        }

        // Bug batch 7 · 1: in the wallet (platform) currency's decimals — the total chargeWallet debits.
        $currency = app(Ledger::class)->defaultCurrency();

        return Money::round($price + app(AddonTax::class)->exclusiveTaxFor($user, $price, $currency)['tax'], money_decimals($currency));
    }

    protected function isInsufficientFunds(\RuntimeException $e): bool
    {
        $msg = strtolower($e->getMessage());

        return str_contains($msg, 'insufficient')
            || str_contains($msg, 'payment failed');
    }

    /** @return array{status:string, price:float, balance:float, shortfall:float} */
    protected function result(string $status, float $price, float $balance): array
    {
        // Bug batch 7 · 1: the wallet currency's decimals (a KWD 1.297 quote is not 1.30).
        $dec = money_decimals(app(Ledger::class)->defaultCurrency());
        $shortfall = $status === self::STATUS_SHORTFALL
            ? Money::round(max(0.0, $price - $balance), $dec)
            : 0.0;

        return [
            'status' => $status,
            'price' => Money::round($price, $dec),
            'balance' => Money::round($balance, $dec),
            'shortfall' => $shortfall,
        ];
    }
}
