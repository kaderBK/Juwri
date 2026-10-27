<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\AppStore\Services;

use App\Models\App;
use App\Models\AppInstallation;
use App\Services\AppInstallService;
use App\Services\PlatformMetrics;
use App\Services\SellerPayoutService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Juwri\Core\Contracts\Billing\AddonTax;
use Juwri\Core\Models\User;
use Juwri\Core\Support\OverduePolicy;
use Juwri\Modules\AppStore\Mail\AppDunningMail;
use Juwri\Modules\AppStore\Models\AppUsageRecord;
use Juwri\Modules\Payments\Contracts\Ledger;
use Juwri\Modules\Payments\Contracts\PurchaseReceipts;
use Juwri\Modules\Payments\Contracts\Wallet;
use Juwri\Modules\Payments\Models\Transaction;

/**
 * P3-4: per-app billing — every charge goes through the EXISTING WalletService
 * choke point (idempotent refs, ledgered, same rails as top-ups and WhatsApp
 * metering). Recurring apps charge per period; usage apps meter per reported
 * unit. Revenue-share accounting to developers rides Phase-4 payouts.
 */
class AppBillingService
{
    /**
     * F4-1 + F4-2 (feature-platform gap fix): the SINGLE wallet-charge choke
     * point for the app rail. Every wallet app charge (install, one_time,
     * renewal, trial-convert, usage) now goes through here, so it:
     *
     *   1. applies `AddonTaxService` exactly like the card rail
     *      (`StorePurchaseController::buyAppWithCard`) — the same app costs the
     *      same on either rail, and VAT is actually collected on the wallet one;
     *   2. writes an `app_purchase` Transaction mirroring the card rail's shape
     *      (pre-tax `base_amount`, taxed `amount`, tax breakdown in `details`),
     *      so wallet purchases appear in invoice history instead of existing
     *      only as a bare WalletTransaction.
     *
     * Idempotence: the wallet debit is already ref-idempotent, and the
     * Transaction is keyed on the SAME ref via `payment_id` — a retried install,
     * a double-fired renewal cron or a replayed partner usage report can never
     * mint a second ledger row. Usage reports without a partner ref have no
     * idempotency key on either side (pre-existing behaviour), so they get a
     * plain create.
     *
     * NOTE (deliberate carve-out): plan/membership wallet charges
     * (`SubscriptionsTick`, reason `subscription_renewal`) are NOT routed here.
     * They carry their own tax path via the plan checkout; taxing them again
     * would double-charge VAT.
     *
     * @param  float  $base  the PRE-TAX price (developer revenue share keeps using this).
     * @return float the taxed total actually debited.
     */
    protected function chargeWallet(
        User $user,
        App $app,
        float $base,
        string $reason,
        string $ref,
        string $description,
        ?AppInstallation $installation = null,
    ): float {
        // Bug batch 7 · 1: tax and total in the wallet (platform) currency's decimals — KWD fils, JPY yen.
        $currency = app(Ledger::class)->defaultCurrency();
        $taxed = app(AddonTax::class)->exclusiveTaxFor($user, $base, $currency);
        $total = money_round($base + $taxed['tax'], money_decimals($currency));

        // Throws RuntimeException on insufficient funds — callers own that path.
        app(Wallet::class)->debit($user, $total, $reason, $ref);

        $attributes = [
            'product_name' => 'App: '.$app->name,
            'product_id' => 0,
            'user_id' => $user->id,
            'base_amount' => $base,
            'amount' => $total,
            'currency_code' => $currency, // the ledger currency the wallet was debited in
            'transaction_method' => 'app_purchase',
            'transaction_gatway' => 'wallet',
            'transaction_description' => $description,
            'frequency' => 'LIFETIME',
            'status' => Transaction::STATUS_SUCCESS,
            'details' => [
                'app_id' => $app->id,
                'app_installation_id' => $installation?->id,
                'wallet_ref' => $ref ?: null,
                'tax_breakdown' => $taxed['breakdown'],
            ],
        ];

        $transaction = $ref !== ''
            ? Transaction::firstOrCreate(['payment_id' => 'wallet-app:'.$ref], $attributes)
            : Transaction::create($attributes);

        // P6-252: durable receipt + receipt e-mail (once per transaction).
        app(PurchaseReceipts::class)->issueFor($transaction);

        return $total;
    }

    /**
     * Charge the first period of a paid app at install time. Throws (and the
     * install aborts) when the wallet can't cover it.
     */
    public function chargeInstall(App $app, AppInstallation $installation): void
    {
        /* P4-5 (feature-platform): one_time — charge once at install, never renew.
           Idempotent per installation (a reinstall of a paid-for one_time app
           does not re-charge). */
        if ($app->billing_type === 'one_time' && $app->price > 0) {
            $user = User::findOrFail($installation->user_id);
            $ref = 'app:'.$installation->id.':onetime';
            $this->chargeWallet($user, $app, (float) $app->price, 'app:'.$app->slug, $ref,
                'App purchase: '.$app->slug, $installation);
            SellerPayoutService::creditSale($app->developer_user_id, (float) $app->price, $ref, 'app sale: '.$app->slug, $installation->user_id);

            return;
        }

        if ($app->billing_type !== 'recurring' || $app->price <= 0) {
            return;
        }

        $user = User::findOrFail($installation->user_id);
        $ref = 'app:'.$installation->id.':install:'.$installation->installed_at->getTimestamp();
        // Idempotent per activation moment (installed_at), so a renewal on the
        // same calendar day uses a DIFFERENT ref and still charges.
        $this->chargeWallet($user, $app, (float) $app->price, 'app:'.$app->slug, $ref,
            'App purchase: '.$app->slug, $installation);

        $installation->update(['current_period_ends_at' => now()->addDays($app->billing_period_days)]);

        // P4-6: developer revenue share.
        SellerPayoutService::creditSale($app->developer_user_id, (float) $app->price, $ref, 'app sale: '.$app->slug, $installation->user_id);
    }

    /**
     * Renew every due recurring installation (rides schedule:run → /cronjob).
     * Insufficient funds → SUSPEND: the session-token middleware and OAuth
     * surface both require an active installation, so access stops without
     * destroying the grant — install() reactivates once the wallet is topped up.
     *
     * @return array{renewed:int,suspended:int}
     */
    public function renewDue(): array
    {
        $renewed = 0;
        $suspended = 0;

        /* P4-4 (feature-platform): past_due rows retry here too. P1-23 part 7: juwri bills these installs (the owner's
           platform wallet is debited, the developer gets a revenue share), so Terms 6.4 applies — access continues and
           the install is paused only when the renewal is more than 14 days overdue AND 7+ days after written notice
           (OverduePolicy, as for plans and resellers). Trial conversions need no extra machinery: trial_ends_at
           doubles as the first due date. */
        $due = AppInstallation::with('app')
            ->whereIn('status', ['active', 'past_due'])
            ->whereNotNull('current_period_ends_at')
            ->where('current_period_ends_at', '<=', now())
            ->get();

        foreach ($due as $installation) {
            $app = $installation->app;
            if (! $app || $app->billing_type !== 'recurring' || $app->price <= 0) {
                continue;
            }
            try {
                $user = User::findOrFail($installation->user_id);
                /* F4-3 (feature-platform gap fix): a still-set trial_ends_at means
                   this due period IS the trial end — the charge below is the
                   installation's FIRST real payment, so it belongs in the
                   partner's app_sale bucket, not app_renewal. Captured before the
                   update() that clears the flag. */
                $isTrialConvert = $installation->trial_ends_at !== null;
                /* F5-2 (feature-platform gap fix): remember whether this charge
                   is a RECOVERY (past_due → active). Grants can have been
                   revoked during the lapse — by the suspension path, by an
                   operator, or by a legacy reconcile --fix run — and nothing
                   else on this path ever puts them back. */
                $wasRecovering = $installation->status !== 'active';
                $ref = 'app:'.$installation->id.':renew:'.$installation->current_period_ends_at->getTimestamp();
                // Idempotent per DUE PERIOD — a double-fired cron can never
                // double-charge the same renewal.
                $this->chargeWallet($user, $app, (float) $app->price, 'app:'.$app->slug.' renewal', $ref,
                    'App renewal: '.$app->slug, $installation);
                $installation->update([
                    'status' => 'active',
                    'current_period_ends_at' => now()->addDays($app->billing_period_days),
                    'trial_ends_at' => null,        // converted
                    'grace_period_ends_at' => null, // recovered
                    'suspension_notice_at' => null, // the next overdue period gets its own notice
                ]);
                /* F5-2: re-assert the app's feature grants on recovery. Only on
                   an actual recovery — syncFeatureGrants is idempotent
                   (EntitlementWriter::grant is updateOrCreate), but running it
                   on every ordinary renewal would mint a FeatureGranted event
                   and an audit row per key per cycle for no reason. */
                if ($wasRecovering) {
                    app(AppInstallService::class)->syncFeatureGrants($installation->fresh());
                }
                // P4-6: developer revenue share. F4-3: trial conversion books as
                // the partner's first SALE; every later cycle is a renewal.
                SellerPayoutService::creditSale(
                    $app->developer_user_id,
                    (float) $app->price,
                    $ref,
                    ($isTrialConvert ? 'app sale: ' : 'app renewal: ').$app->slug,
                    $installation->user_id,
                    $isTrialConvert ? 'app_sale' : 'app_renewal',
                );
                $renewed++;
            } catch (\Throwable $e) {
                if ($this->applyOverdue($installation, $app)) {
                    $suspended++;
                }
                PlatformMetrics::record('app.payment_fail', $app->slug);
                /* An empty wallet is the expected dunning path, not a fault —
                   keep it out of the ERROR stream; anything else still reports. */
                if (str_starts_with($e->getMessage(), 'Insufficient wallet balance')) {
                    Log::warning('app renewal: '.$e->getMessage(), ['installation_id' => $installation->id]);
                } else {
                    report($e);
                }
            }
        }

        return ['renewed' => $renewed, 'suspended' => $suspended];
    }

    /**
     * Meter one usage report from a partner app. Idempotent by partner ref.
     *
     * @throws \RuntimeException when the wallet can't cover the charge.
     */
    public function recordUsage(AppInstallation $installation, float $quantity, ?string $ref = null): AppUsageRecord
    {
        $app = $installation->app;
        if ($app->billing_type !== 'usage' || $app->usage_price === null) {
            throw new \RuntimeException('App does not use metered billing.');
        }
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Usage quantity must be positive.');
        }

        if ($ref && ($existing = AppUsageRecord::where('ref', $ref)->first())) {
            return $existing; // partner retry — already recorded and charged
        }

        $charge = max(0.01, ceil($quantity * (float) $app->usage_price * 100) / 100);
        $user = User::findOrFail($installation->user_id);

        /* F4-1/F4-2: taxed + ledgered like every other app charge. `charged`
           on the usage record stays the PRE-TAX amount — it is what the
           developer revenue share is computed from, and what the partner API
           echoes back. The taxed total is on the Transaction's `amount`. */
        $this->chargeWallet($user, $app, $charge, 'app:'.$app->slug.' usage',
            $ref ? 'app-usage:'.$ref : '', 'App usage: '.$app->slug, $installation);

        $record = AppUsageRecord::create([
            'app_installation_id' => $installation->id,
            'quantity' => $quantity,
            'unit' => $app->usage_unit ?? 'unit',
            'charged' => $charge,
            'ref' => $ref,
        ]);

        // P4-6: developer revenue share on metered usage.
        SellerPayoutService::creditSale($app->developer_user_id, $charge, 'app-usage-rec:'.$record->id, 'app usage: '.$app->slug, $installation->user_id);

        return $record;
    }

    /**
     * P1-23 part 7 — Terms 6.4 for a paid app install whose renewal failed. The amount is overdue from the due date
     * (current_period_ends_at, which stays put while past_due):
     *   day 0  → past_due, access kept; one "renewal failed" mail (top up, we retry daily)
     *   day 14 → the written notice (AppDunningMail: amount + pause date = day 21, never sooner than 7 days on)
     *   day 21 (≥ notice + 7) → suspended, the app's feature grants revoked; a later successful charge reactivates.
     * A notice that cannot be sent is not recorded and nothing is paused. Rows past_due before this (old 3-day grace)
     * keep access until notice + 7 days. Returns true when the install was suspended now.
     */
    private function applyOverdue(AppInstallation $installation, App $app): bool
    {
        $now = now();
        $due = $installation->current_period_ends_at ?? $now;
        $planned = OverduePolicy::plannedSuspension($due);
        $owner = User::find($installation->user_id); // NOT the loop's $user — may be unset/stale in the catch

        if ($installation->status !== 'past_due') {
            $installation->update(['status' => 'past_due', 'grace_period_ends_at' => $planned, 'suspension_notice_at' => null]);
            $this->mailOwner($owner, 'failed', $app);
        } elseif ($installation->suspension_notice_at === null
            && ($installation->grace_period_ends_at === null || $installation->grace_period_ends_at->lt($planned))) {
            $installation->update(['grace_period_ends_at' => $planned]); // deploy: an old 3-day grace moves to the rule
        }

        $notice = $installation->suspension_notice_at;
        if ($notice === null && $now->gte(OverduePolicy::noticeDueAt($due, $planned))) {
            $pauseOn = OverduePolicy::noticeSuspensionDate($planned, $now);
            if ($this->mailOwner($owner, 'notice', $app, $pauseOn)) {
                $installation->update(['suspension_notice_at' => $now, 'grace_period_ends_at' => $pauseOn]);
                $notice = $now;
            }
        }

        if ($notice !== null && OverduePolicy::maySuspend($due, $notice, $now)
            && ($installation->grace_period_ends_at === null || $now->gte($installation->grace_period_ends_at))) {
            $installation->update(['status' => 'suspended']);
            app(AppInstallService::class)->syncFeatureGrants($installation->fresh());

            return true;
        }

        return false;
    }

    /** The owner's mail in the owner's language (Community lang/<locale>/sysmail.php). False when it could not go out. */
    private function mailOwner(?User $owner, string $kind, App $app, ?CarbonInterface $pauseOn = null): bool
    {
        if (! $owner || empty($owner->email)) {
            return false;
        }
        $locale = $owner->preferredLocale();
        $price = price_format($app->price);
        $lines = [__('sysmail.greeting', ['name' => (string) $owner->name], $locale)];
        if ($kind === 'notice' && $pauseOn !== null) {
            $date = Carbon::instance($pauseOn)->locale((string) $locale)->translatedFormat('j F Y');
            $subject = __('sysmail.app_overdue.notice_subject', ['app' => $app->name, 'date' => $date], $locale);
            $lines[] = __('sysmail.app_overdue.notice_body', ['app' => $app->name, 'price' => $price, 'date' => $date], $locale);
        } else {
            $subject = __('sysmail.app_overdue.failed_subject', ['app' => $app->name], $locale);
            $lines[] = __('sysmail.app_overdue.failed_body', ['app' => $app->name, 'price' => $price], $locale);
        }

        try {
            Mail::to($owner->email)->queue(
                (new AppDunningMail((string) $subject, array_map('strval', $lines)))->locale($locale)
            );

            return true;
        } catch (\Throwable $mailError) {
            report($mailError);

            return false;
        }
    }
}
