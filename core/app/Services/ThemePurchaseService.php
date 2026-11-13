<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services;

use App\Models\Theme;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\User;
use Juwri\Modules\AppStore\Models\ThemePurchase;
use Juwri\Modules\Payments\Contracts\Wallet;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\WalletPurchaseReceipt;

/**
 * P4-1: buying a theme license — rides the WalletService choke point
 * (ledgered, idempotent). One purchase licenses the whole ACCOUNT.
 * Seller revenue-share credit lands with P4-6 payouts; price_paid is
 * recorded now so the split can be computed retroactively.
 */
class ThemePurchaseService
{
    /**
     * Render-path entitlement by slug. No Theme row (in-repo reference theme)
     * or a free theme → allowed; a paid theme needs a license. Any DB error →
     * report + allow (an entitlement outage must never blank storefronts).
     */
    public static function entitledForSlug(int $userId, string $slug): bool
    {
        try {
            $theme = Theme::where('slug', strtolower($slug))->first();

            return $theme === null || static::entitled($userId, $theme);
        } catch (\Throwable $e) {
            report($e);

            return true;
        }
    }

    /** Does this account hold a license for the theme? */
    public static function entitled(User|int $user, Theme $theme): bool
    {
        if ((float) $theme->price <= 0) {
            return true; // free themes need no license
        }
        $userId = $user instanceof User ? $user->id : $user;

        return ThemePurchase::where('user_id', $userId)->where('theme_id', $theme->id)->active()->exists();
    }

    /**
     * Purchase a license. Idempotent — buying twice returns the existing row
     * without a second charge.
     */
    public function purchase(User $user, Theme $theme): ThemePurchase
    {
        $existing = ThemePurchase::where('user_id', $user->id)->where('theme_id', $theme->id)->first();
        if ($existing && $existing->refunded_at === null) {
            return $existing;
        }

        // P6-243: a refunded licence bought again is charged and credited again, under refs of its own.
        $round = $existing ? (int) $existing->refund_count : 0;
        $suffix = $round > 0 ? ':r'.$round : '';

        $ref = '';
        if ((float) $theme->price > 0) {
            $ref = 'theme:'.$theme->id.':user:'.$user->id.$suffix;
            app(Wallet::class)->debit($user, (float) $theme->price, 'theme: '.$theme->slug, $ref);
        }

        $fields = ['price_paid' => (float) $theme->price, 'wallet_ref' => $ref ?: null];
        if ($existing) {
            $existing->update($fields + ['refunded_at' => null, 'refunded_by' => null, 'refund_reason' => null]);
            $purchase = $existing;
        } else {
            $purchase = ThemePurchase::create(['user_id' => $user->id, 'theme_id' => $theme->id] + $fields);
        }

        // P6-252: a paid licence gets a transaction (owner billing history / invoice), a receipt and a receipt e-mail.
        if ($ref !== '') {
            $currency = app(Wallet::class)->currency(); // the wallet currency the licence was debited in, never a dashboard store swap
            $transaction = Transaction::firstOrCreate(
                ['payment_id' => 'wallet-theme:'.$ref, 'transaction_gatway' => 'wallet'],
                [
                    'product_name' => 'Theme: '.$theme->name,
                    'product_id' => 0,
                    'user_id' => $user->id,
                    'base_amount' => (float) $theme->price,
                    'amount' => (float) $theme->price,
                    'currency_code' => $currency,
                    'transaction_method' => 'theme_purchase',
                    'transaction_description' => 'Theme licence: '.$theme->slug,
                    'frequency' => 'LIFETIME',
                    'status' => Transaction::STATUS_SUCCESS,
                    'details' => ['theme_id' => $theme->id, 'theme_purchase_id' => $purchase->id, 'wallet_ref' => $ref],
                ],
            );
            WalletPurchaseReceipt::issue($transaction);
        }

        // P4-6: revenue share to the seller's wallet (idempotent, non-blocking).
        SellerPayoutService::creditSale(
            $theme->seller_id,
            (float) $theme->price,
            self::saleRef($purchase),
            'theme sale: '.$theme->slug,
        );

        return $purchase;
    }

    /** The payout / earnings ref of the purchase's current round (P4-6 `theme-sale:{id}`, `:r{n}` after n refunds). */
    public static function saleRef(ThemePurchase $purchase): string
    {
        $round = (int) $purchase->refund_count;

        return 'theme-sale:'.$purchase->id.($round > 0 ? ':r'.$round : '');
    }

    /**
     * P6-243: admin refund of a theme licence. In one transaction, under the purchase row lock: the buyer gets the
     * price back as wallet credit (idempotent ref), the seller's share is reversed (or flagged to the admin when it
     * has matured / been spent — {@see SellerPayoutService::reverseOrFlag()}), and the licence stops licensing.
     * A second click / replay finds the row refunded and does nothing.
     *
     * @return bool false when the licence was already refunded
     */
    public function refund(ThemePurchase $purchase, int $actorId, string $reason): bool
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('A refund reason is required.');
        }

        return DB::transaction(function () use ($purchase, $actorId, $reason): bool {
            $locked = ThemePurchase::whereKey($purchase->getKey())->lockForUpdate()->first();
            if (! $locked || $locked->refunded_at !== null) {
                return false;
            }

            $buyer = User::find($locked->user_id);
            $saleRef = self::saleRef($locked);
            $price = (float) $locked->price_paid;
            if ($buyer && $price > 0 && $locked->wallet_ref) {
                $currency = (string) ($locked->currency_code ?: app(Wallet::class)->currency());
                app(Wallet::class)->creditBack($buyer, $price, $currency, 'theme refund: #'.$locked->id, 'refund-'.$locked->wallet_ref);
            }

            $outcome = SellerPayoutService::reverseOrFlag($saleRef, 'theme refund clawback: purchase #'.$locked->id,
                ['theme_purchase_id' => $locked->id, 'actor_id' => $actorId]);

            $locked->update([
                'refunded_at' => now(),
                'refunded_by' => $actorId,
                'refund_reason' => mb_substr($reason, 0, 500),
                'refund_count' => (int) $locked->refund_count + 1,
            ]);

            log_activity('theme_purchase.refund', $buyer, [
                'theme_purchase_id' => $locked->id, 'theme_id' => $locked->theme_id, 'amount' => $price,
                'seller' => $outcome, 'actor_id' => $actorId, 'reason' => $reason,
            ], null);

            $purchase->refresh();

            return true;
        });
    }
}
