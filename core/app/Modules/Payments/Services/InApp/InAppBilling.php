<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\InApp;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Contracts\GooglePlayBilling;
use Juwri\Modules\Payments\Models\InAppAccount;
use Juwri\Modules\Payments\Models\InAppPurchase;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Webhooks\WebhookReceipts;
use Juwri\Modules\Payments\Support\Fulfilment;

/**
 * P5-17: owner-app in-app billing (Apple App Store, Google Play) on the ONE billing state machine of the web path.
 *
 *  - A purchase is proved by the store (Apple: a JWS verified against the pinned Apple root; Google: the Play Developer
 *    API asked server-side), must be for our bundle / package, a mapped product, and the owner's own account token
 *    (appAccountToken / obfuscatedExternalAccountId). A subscription already linked to another owner is refused.
 *  - Grants and renewals go through Fulfilment::webhook (gateway 'apple' / 'google_play', payment = the store's
 *    transaction / order id, subscription = the original transaction / first purchase token): the same settlement,
 *    receipts and subscription activation as a web checkout, once per payment.
 *  - Grace (billing retry, on hold) → Fulfilment::morPastDue; auto-renew off → morCancelAtPeriodEnd; expired →
 *    morCancel; refund / revoke → morRefund — the merchant-of-record lifecycle the web gateways use.
 *  - Notifications are processed once (WebhookReceipts on the store's notification id) and only when newer than the
 *    last applied store event of that subscription, so a replay never undoes a later state.
 */
class InAppBilling
{
    public const APPLE = 'apple';

    public const GOOGLE = 'google_play';

    public function __construct(private readonly GooglePlayBilling $google) {}

    /**
     * A store product's mapping (plan_id, interval, …). Read from the array: product ids contain dots.
     *
     * @return array<string, mixed>|null
     */
    public static function product(string $platform, string $productId): ?array
    {
        $map = (array) config($platform === self::APPLE ? 'inapp.apple.products' : 'inapp.google.products');
        $hit = $map[$productId] ?? null;

        return is_array($hit) && (int) ($hit['plan_id'] ?? 0) > 0 ? $hit : null;
    }

    public static function enabled(string $platform): bool
    {
        return (bool) config($platform === self::APPLE ? 'inapp.apple.enabled' : 'inapp.google.enabled');
    }

    /** The token the app hands the store at purchase (stable per owner). */
    public function accountToken(User $user): string
    {
        $row = InAppAccount::query()->withoutGlobalScopes()->firstOrCreate(['user_id' => $user->id], ['token' => (string) Str::uuid()]);

        return (string) $row->token;
    }

    // ── Apple ───────────────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array{state: string, plan_id: int} */
    public function linkApple(User $user, string $signedTransaction): array
    {
        $tx = $this->appleTransaction($signedTransaction);
        if (($tx['appAccountToken'] ?? null) === null || ! hash_equals($this->accountToken($user), strtolower((string) $tx['appAccountToken']))) {
            throw new InAppRejected('account_token_mismatch');
        }
        $purchase = $this->claim(self::APPLE, (string) $tx['originalTransactionId'], (string) $tx['originalTransactionId'], $user, (string) $tx['productId'], (string) ($tx['environment'] ?? ''));
        $this->appleApply($purchase, 'SUBSCRIBED', null, $tx, (int) ($tx['signedDate'] ?? 0));

        return ['state' => (string) $purchase->refresh()->state, 'plan_id' => (int) $purchase->plan_id];
    }

    /** App Store Server Notifications V2. Returns false for a notification that is not ours or not verifiable. */
    public function appleNotification(string $signedPayload): bool
    {
        $root = (string) config('inapp.apple.root_sha256');
        $n = AppleJws::verify($signedPayload, $root);
        $data = (array) ($n['data'] ?? []);
        if (($data['bundleId'] ?? null) !== (string) config('inapp.apple.bundle_id')) {
            throw new InAppRejected('wrong_bundle');
        }
        $uuid = (string) ($n['notificationUUID'] ?? '');

        return (bool) WebhookReceipts::once(self::APPLE, $uuid !== '' ? 'notification:'.$uuid : null, function () use ($n, $data) {
            if (empty($data['signedTransactionInfo'])) {
                return true; // TEST / summary notifications carry no transaction
            }
            $tx = $this->appleTransaction((string) $data['signedTransactionInfo']);
            $purchase = $this->purchaseFor(self::APPLE, (string) $tx['originalTransactionId'], $tx['appAccountToken'] ?? null, (string) $tx['productId'], (string) ($tx['environment'] ?? ''));
            if ($purchase === null) {
                return true; // nobody to bill: a purchase made outside our account tokens
            }
            $this->appleApply($purchase, (string) ($n['notificationType'] ?? ''), $n['subtype'] ?? null, $tx, (int) ($n['signedDate'] ?? 0));

            return true;
        }, true);
    }

    /** @return array<string, mixed> a verified transaction of our bundle, environment and a mapped product */
    private function appleTransaction(string $jws): array
    {
        $tx = AppleJws::verify($jws, (string) config('inapp.apple.root_sha256'));
        if (($tx['bundleId'] ?? null) !== (string) config('inapp.apple.bundle_id') || (string) config('inapp.apple.bundle_id') === '') {
            throw new InAppRejected('wrong_bundle');
        }
        if (! in_array((string) ($tx['environment'] ?? ''), (array) config('inapp.apple.environments'), true)) {
            throw new InAppRejected('wrong_environment');
        }
        if (self::product(self::APPLE, (string) ($tx['productId'] ?? '')) === null) {
            throw new InAppRejected('unknown_product');
        }
        if (empty($tx['transactionId']) || empty($tx['originalTransactionId'])) {
            throw new InAppRejected('invalid_transaction');
        }

        return $tx;
    }

    /** @param  array<string, mixed>  $tx */
    private function appleApply(InAppPurchase $purchase, string $type, mixed $subtype, array $tx, int $signedMs): void
    {
        if (! $this->newer($purchase, $signedMs)) {
            return; // replayed or out of order: a newer store event was already applied
        }
        $ref = (string) $purchase->subscription_ref;
        $paymentId = (string) $tx['transactionId'];
        $expires = isset($tx['expiresDate']) ? (int) $tx['expiresDate'] : null;
        $revoked = isset($tx['revocationDate']);
        match (true) {
            $type === 'REFUND' || $type === 'REVOKE' || ($revoked && $type !== 'REFUND_REVERSED') => $this->refund($purchase, self::APPLE, $paymentId, $type === 'REVOKE' ? 'revoked' : 'refunded'),
            $type === 'DID_FAIL_TO_RENEW' => $this->state($purchase, 'grace', fn () => Fulfilment::morPastDue(self::APPLE, $ref)),
            $type === 'EXPIRED' || $type === 'GRACE_PERIOD_EXPIRED' => $this->state($purchase, 'expired', fn () => Fulfilment::morCancel(self::APPLE, $ref)),
            $type === 'DID_CHANGE_RENEWAL_STATUS' && $subtype === 'AUTO_RENEW_DISABLED' => $this->state($purchase, 'cancelling', fn () => Fulfilment::morCancelAtPeriodEnd(self::APPLE, $ref)),
            in_array($type, ['SUBSCRIBED', 'DID_RENEW', 'OFFER_REDEEMED', 'DID_CHANGE_RENEWAL_PREF'], true)
                && ($expires === null || $expires > now()->getTimestampMs()) => $this->grant($purchase, self::APPLE, $paymentId, (string) $tx['productId'],
                    isset($tx['price']) ? ((int) $tx['price']) / 1000 : null, isset($tx['currency']) ? (string) $tx['currency'] : null, $expires),
            default => null, // other types (PRICE_INCREASE, RENEWAL_EXTENDED, TEST, …) change nothing billable
        };
        $purchase->forceFill(['last_event_ms' => max((int) $purchase->last_event_ms, $signedMs)])->save();
    }

    // ── Google ──────────────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array{state: string, plan_id: int} */
    public function linkGoogle(User $user, string $productId, string $purchaseToken): array
    {
        [$sub, $line] = $this->googleSubscription($purchaseToken);
        $owner = (string) data_get($sub, 'externalAccountIdentifiers.obfuscatedExternalAccountId', '');
        if ($owner === '' || ! hash_equals($this->accountToken($user), strtolower($owner))) {
            throw new InAppRejected('account_token_mismatch');
        }
        if ($productId !== (string) $line['productId']) {
            throw new InAppRejected('unknown_product');
        }
        $original = $this->googleOriginal($purchaseToken, $sub);
        $purchase = $this->claim(self::GOOGLE, $original, 'gp-'.substr(hash('sha256', $original), 0, 40), $user, (string) $line['productId'], ! empty($sub['testPurchase']) ? 'test' : 'production');
        $this->googleApply($purchase, $sub, $line, $purchaseToken, null);

        return ['state' => (string) $purchase->refresh()->state, 'plan_id' => (int) $purchase->plan_id];
    }

    /**
     * RTDN (Pub/Sub push, OIDC token verified by the caller). The notification only says "something changed": the
     * purchase is read back from Google and its current state applied, so a replay re-applies the same state.
     *
     * @param  array<string, mixed>  $message  the Pub/Sub message ({data, messageId})
     */
    public function googleNotification(array $message): bool
    {
        $data = json_decode((string) base64_decode((string) ($message['data'] ?? ''), true), true);
        if (! is_array($data)) {
            throw new InAppRejected('invalid_message');
        }
        if (($data['packageName'] ?? null) !== (string) config('inapp.google.package_name') || (string) config('inapp.google.package_name') === '') {
            throw new InAppRejected('wrong_package');
        }
        $id = (string) ($message['messageId'] ?? ($message['message_id'] ?? ''));

        return (bool) WebhookReceipts::once(self::GOOGLE, $id !== '' ? 'rtdn:'.$id : null, function () use ($data) {
            $voided = (array) ($data['voidedPurchaseNotification'] ?? []);
            $token = (string) (data_get($data, 'subscriptionNotification.purchaseToken') ?: ($voided['purchaseToken'] ?? ''));
            if ($token === '') {
                return true; // test / one-time product notifications: nothing to bill
            }
            [$sub, $line] = $this->googleSubscription($token);
            $original = $this->googleOriginal($token, $sub);
            $purchase = $this->purchaseFor(self::GOOGLE, $original, data_get($sub, 'externalAccountIdentifiers.obfuscatedExternalAccountId'), (string) $line['productId'], ! empty($sub['testPurchase']) ? 'test' : 'production');
            if ($purchase === null) {
                return true;
            }
            if ($voided !== []) {
                $this->refund($purchase, self::GOOGLE, (string) ($voided['orderId'] ?? $sub['latestOrderId'] ?? ''), 'refunded');

                return true;
            }
            $this->googleApply($purchase, $sub, $line, $token, (int) data_get($data, 'subscriptionNotification.notificationType'));

            return true;
        }, true);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} the purchase and its line for a mapped product */
    private function googleSubscription(string $token): array
    {
        $sub = $this->google->subscription((string) config('inapp.google.package_name'), $token);
        $line = (array) (($sub['lineItems'] ?? [])[0] ?? []);
        if (self::product(self::GOOGLE, (string) ($line['productId'] ?? '')) === null) {
            throw new InAppRejected('unknown_product');
        }

        return [$sub, $line];
    }

    /**
     * The first token of an upgrade / resubscribe chain (linkedPurchaseToken), so the chain is one subscription.
     *
     * @param  array<string, mixed>  $sub
     */
    private function googleOriginal(string $token, array $sub): string
    {
        $linked = (string) ($sub['linkedPurchaseToken'] ?? '');
        if ($linked !== '') {
            $prev = InAppPurchase::query()->withoutGlobalScopes()->where('platform', 'google')->where('original_key', hash('sha256', $linked))->exists();
            if ($prev) {
                return $linked;
            }
        }

        return $token;
    }

    /**
     * @param  array<string, mixed>  $sub
     * @param  array<string, mixed>  $line
     */
    private function googleApply(InAppPurchase $purchase, array $sub, array $line, string $token, ?int $type): void
    {
        $ref = (string) $purchase->subscription_ref;
        $order = (string) ($sub['latestOrderId'] ?? '');
        $expires = isset($line['expiryTime']) ? (int) (strtotime((string) $line['expiryTime']) * 1000) : null;
        match ((string) ($sub['subscriptionState'] ?? '')) {
            'SUBSCRIPTION_STATE_ACTIVE' => $type === 12 ? $this->refund($purchase, self::GOOGLE, $order, 'revoked')
                : ($type === 3 ? $this->state($purchase, 'cancelling', fn () => Fulfilment::morCancelAtPeriodEnd(self::GOOGLE, $ref))
                : $this->grant($purchase, self::GOOGLE, $order, (string) $line['productId'], null, null, $expires)),
            'SUBSCRIPTION_STATE_CANCELED' => $this->state($purchase, 'cancelling', fn () => Fulfilment::morCancelAtPeriodEnd(self::GOOGLE, $ref)),
            'SUBSCRIPTION_STATE_IN_GRACE_PERIOD', 'SUBSCRIPTION_STATE_ON_HOLD' => $this->state($purchase, 'grace', fn () => Fulfilment::morPastDue(self::GOOGLE, $ref)),
            'SUBSCRIPTION_STATE_EXPIRED' => $type === 12 ? $this->refund($purchase, self::GOOGLE, $order, 'revoked')
                : $this->state($purchase, 'expired', fn () => Fulfilment::morCancel(self::GOOGLE, $ref)),
            default => null, // PENDING, PAUSED: nothing paid / nothing to change yet
        };
        if (($sub['acknowledgementState'] ?? null) === 'ACKNOWLEDGEMENT_STATE_PENDING' && ($sub['subscriptionState'] ?? null) === 'SUBSCRIPTION_STATE_ACTIVE') {
            $this->google->acknowledge((string) config('inapp.google.package_name'), (string) $line['productId'], $token);
        }
    }

    // ── shared ──────────────────────────────────────────────────────────────────────────────────────────────────────────

    /** Link a store subscription to its owner (refused when another owner holds it). */
    private function claim(string $platform, string $original, string $ref, User $user, string $productId, string $environment): InAppPurchase
    {
        $key = hash('sha256', $original);
        $db = $platform === self::APPLE ? 'apple' : 'google';
        $row = InAppPurchase::query()->withoutGlobalScopes()->where('platform', $db)->where('original_key', $key)->first();
        if ($row !== null && (int) $row->user_id !== (int) $user->id) {
            throw new InAppRejected('owned_by_another_account');
        }
        $plan = (int) (self::product($platform, $productId)['plan_id'] ?? 0);
        if ($row === null) {
            $row = InAppPurchase::query()->create(['user_id' => $user->id, 'platform' => $db, 'environment' => mb_substr($environment, 0, 16),
                'original_key' => $key, 'subscription_ref' => $ref, 'product_id' => mb_substr($productId, 0, 191), 'plan_id' => $plan ?: null]);
        }

        return $row;
    }

    /** The linked subscription of a notification, linking it now when its account token names an owner. */
    private function purchaseFor(string $platform, string $original, mixed $accountToken, string $productId, string $environment): ?InAppPurchase
    {
        $db = $platform === self::APPLE ? 'apple' : 'google';
        $row = InAppPurchase::query()->withoutGlobalScopes()->where('platform', $db)->where('original_key', hash('sha256', $original))->first();
        if ($row !== null) {
            return $row;
        }
        $owner = is_string($accountToken) && $accountToken !== ''
            ? InAppAccount::query()->withoutGlobalScopes()->where('token', strtolower($accountToken))->value('user_id') : null;
        $user = $owner !== null ? User::query()->find($owner) : null;
        if ($user === null) {
            return null;
        }

        return $this->claim($platform, $original, $platform === self::APPLE ? $original : 'gp-'.substr(hash('sha256', $original), 0, 40), $user, $productId, $environment);
    }

    private function newer(InAppPurchase $purchase, int $signedMs): bool
    {
        return $signedMs === 0 || $signedMs >= (int) $purchase->last_event_ms;
    }

    /** A paid period: the web path's settlement (once per store payment). */
    private function grant(InAppPurchase $purchase, string $gateway, string $paymentId, string $productId, ?float $price, ?string $currency, ?int $expiresMs): void
    {
        if ($paymentId === '') {
            return;
        }
        $map = self::product($gateway, $productId) ?? [];
        $amount = $price ?? (float) ($map['amount'] ?? 0);
        $metadata = (object) [
            'user_id' => (int) $purchase->user_id, 'product_id' => (int) ($map['plan_id'] ?? 0), 'transaction_method' => 'membership',
            'base_amount' => $amount, 'amount' => $amount, 'currency_code' => $currency ?? (string) ($map['currency'] ?? platform_currency()),
            'frequency' => (string) ($map['interval'] ?? 'MONTHLY'),
            'transaction_description' => $gateway === self::APPLE ? 'App Store' : 'Google Play',
        ];
        Fulfilment::webhook($gateway, $metadata, $paymentId, (string) $purchase->subscription_ref);
        $paid = Transaction::query()->where('transaction_gatway', $gateway)->where('payment_id', $paymentId)->exists();
        if ($paid) {
            $purchase->forceFill(['state' => 'active', 'latest_payment_id' => $paymentId,
                'plan_id' => (int) ($map['plan_id'] ?? 0) ?: $purchase->plan_id,
                'expires_at' => $expiresMs !== null ? now()->setTimestamp(intdiv($expiresMs, 1000)) : $purchase->expires_at])->save();
        }
    }

    private function refund(InAppPurchase $purchase, string $gateway, string $paymentId, string $state): void
    {
        DB::transaction(function () use ($purchase, $gateway, $paymentId, $state) {
            if ($paymentId !== '') {
                Fulfilment::morRefund($gateway, $paymentId);
            }
            Fulfilment::morCancel($gateway, (string) $purchase->subscription_ref); // revoked access ends now either way
            $purchase->forceFill(['state' => $state])->save();
        });
    }

    private function state(InAppPurchase $purchase, string $state, \Closure $apply): void
    {
        $apply();
        $purchase->forceFill(['state' => $state])->save();
    }
}
