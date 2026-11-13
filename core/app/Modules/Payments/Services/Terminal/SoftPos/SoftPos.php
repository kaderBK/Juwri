<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Terminal\SoftPos;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Models\Store;
use Juwri\Modules\Payments\Contracts\PaymentTerminal;
use Juwri\Modules\Payments\Contracts\TerminalPayments;
use Juwri\Modules\Payments\Contracts\TerminalRejected;
use Juwri\Modules\Payments\Events\TerminalPaymentCaptured;
use Juwri\Modules\Payments\Models\TerminalAccount;
use Juwri\Modules\Payments\Models\TerminalPayment;
use Juwri\Modules\Payments\Services\Webhooks\WebhookReceipts;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * P5-21: the SoftPOS orchestrator. Rows in terminal_payments are the idempotency record (unique store + client UUID):
 * a row is written BEFORE the provider is called (state 'creating'); a transport failure leaves it 'creating' and the
 * till's retry with the same UUID calls the provider again with the same merchant reference (the provider's own
 * duplicate guard), so a payment is never created twice. Refunds reserve their amount on the locked charge row first.
 */
final class SoftPos implements TerminalPayments
{
    public const PROVIDERS = ['geidea_softpos' => 'geidea', 'edfapay_softpos' => 'edfapay'];

    private const SETTLED = [PaymentTerminal::CAPTURED, PaymentTerminal::AUTHORISED];

    /** @var array<string, SoftPosDriver> */
    private array $drivers = [];

    public function __construct(GeideaSoftPos $geidea, EdfaPaySoftPos $edfapay)
    {
        foreach ([$geidea, $edfapay] as $d) {
            $this->drivers[$d->key()] = $d;
        }
    }

    public static function enabled(string $provider): bool
    {
        $k = self::PROVIDERS[$provider] ?? null;

        return $k !== null && (bool) config('softpos.'.$k.'.enabled', false);
    }

    public function anyEnabled(): bool
    {
        return $this->enabledProviders() !== [];
    }

    public function enabledProviders(): array
    {
        return array_values(array_filter(array_keys(self::PROVIDERS), fn (string $p) => self::enabled($p)));
    }

    public function driver(string $provider): SoftPosDriver
    {
        return $this->drivers[$provider] ?? throw new TerminalRejected('not_enabled', ___('Unknown terminal provider.'), 404);
    }

    /** The store's enabled account of a switched-on provider (the first one set up). */
    public function activeAccount(Store $store): ?TerminalAccount
    {
        $on = $this->enabledProviders();
        if ($on === []) {
            return null;
        }

        return TerminalAccount::query()->where('store_id', $store->id)->where('enabled', true)->whereIn('provider', $on)->orderBy('id')->first();
    }

    public function accounts(Store $store): array
    {
        return TerminalAccount::query()->where('store_id', $store->id)->orderBy('id')->get()
            ->map(fn (TerminalAccount $a) => self::maskedAccount($a))->values()->all();
    }

    /** @return array<string, mixed> */
    private static function maskedAccount(TerminalAccount $a): array
    {
        $masked = [];
        foreach ((array) $a->credentials as $k => $v) {
            $v = (string) $v;
            $masked[$k] = $v === '' ? '' : str_repeat('•', 6).(strlen($v) > 8 ? substr($v, -4) : '');
        }

        return ['provider' => $a->provider, 'merchant_id' => $a->merchant_id, 'enabled' => (bool) $a->enabled, 'provider_enabled' => self::enabled((string) $a->provider), 'credentials' => $masked];
    }

    public function saveAccount(Store $store, string $provider, string $merchantId, array $credentials, bool $enabled): array
    {
        if (! self::enabled($provider)) {
            throw new TerminalRejected('not_enabled', ___('This terminal provider is not available.'), 404);
        }
        $account = TerminalAccount::query()->firstOrNew(['store_id' => $store->id, 'provider' => $provider]);
        $kept = (array) ($account->credentials ?? []);
        foreach ($credentials as $k => $v) {
            if ((string) $v !== '') {
                $kept[$k] = (string) $v;
            }
        }
        $account->fill(['merchant_id' => $merchantId, 'enabled' => $enabled, 'credentials' => $kept])->save();

        return self::maskedAccount($account);
    }

    private function accountOrFail(Store $store): TerminalAccount
    {
        return $this->activeAccount($store) ?? throw new TerminalRejected('not_enabled', ___('Card terminal is not set up for this store.'), 404);
    }

    /** The account a stored payment was made with (its provider must still be switched on). */
    private function accountOf(TerminalPayment $p): TerminalAccount
    {
        if (! self::enabled((string) $p->provider)) {
            throw new TerminalRejected('not_enabled', ___('This terminal provider is not available.'), 404);
        }

        return TerminalAccount::query()->where('store_id', $p->store_id)->where('provider', $p->provider)->first()
            ?? throw new TerminalRejected('not_enabled', ___('Card terminal is not set up for this store.'), 404);
    }

    private function find(Store $store, string $clientUuid): ?TerminalPayment
    {
        return TerminalPayment::query()->where('store_id', $store->id)->where('client_uuid', $clientUuid)->first();
    }

    /** @param array<string, mixed> $attrs */
    private function claim(Store $store, array $attrs): TerminalPayment
    {
        try {
            return TerminalPayment::query()->create($attrs + ['store_id' => $store->id, 'state' => 'creating', 'refunded_minor' => 0]);
        } catch (UniqueConstraintViolationException) {
            return $this->find($store, (string) $attrs['client_uuid']) ?? throw new TerminalRejected('key_reused', ___('Try again.'), 409);
        }
    }

    /** @param array<string, mixed> $expect */
    private static function sameRequest(TerminalPayment $row, array $expect): void
    {
        foreach ($expect as $k => $v) {
            if ((string) $row->getAttribute($k) !== (string) $v) {
                throw new TerminalRejected('key_reused', ___('This payment key was already used for a different payment.'), 409);
            }
        }
    }

    public function charge(Store $store, string $clientUuid, int $amountMinor, string $currency, ?int $orderId = null, ?int $tipMinor = null): array
    {
        if ($amountMinor <= 0) {
            throw new TerminalRejected('invalid', ___('The amount must be positive.'));
        }
        $currency = strtoupper($currency);
        $row = $this->find($store, $clientUuid);
        if ($row === null) {
            $account = $this->accountOrFail($store);
            $row = $this->claim($store, ['client_uuid' => $clientUuid, 'kind' => 'charge', 'provider' => $account->provider, 'amount_minor' => $amountMinor,
                'currency_code' => $currency, 'order_id' => $orderId, 'tip_minor' => $tipMinor]);
        }
        self::sameRequest($row, ['kind' => 'charge', 'amount_minor' => $amountMinor, 'currency_code' => $currency, 'order_id' => $orderId, 'tip_minor' => $tipMinor]);
        if ($row->state !== 'creating') {
            $this->settleIfCaptured($row); // a resend retries a settlement that failed

            return self::view($row);
        }
        $account = $this->accountOf($row);
        try {
            $res = $this->driver((string) $row->provider)->create($account, $clientUuid, $amountMinor, $currency);
        } catch (RuntimeException|ConnectionException $e) {
            Log::warning('[softpos] charge create failed: '.$e->getMessage());

            throw new TerminalRejected('provider_unavailable', ___('The card terminal service did not answer. Try again.'), 502);
        }
        $this->apply($row, $res);

        return self::view($row);
    }

    public function refund(Store $store, string $clientUuid, string $chargeClientUuid, int $amountMinor): array
    {
        if ($amountMinor <= 0) {
            throw new TerminalRejected('invalid', ___('The amount must be positive.'));
        }
        $row = DB::transaction(function () use ($store, $clientUuid, $chargeClientUuid, $amountMinor) {
            $charge = TerminalPayment::query()->where('store_id', $store->id)->where('client_uuid', $chargeClientUuid)->where('kind', 'charge')->lockForUpdate()->first()
                ?? throw new TerminalRejected('not_found', ___('Payment not found.'), 404);
            $existing = $this->find($store, $clientUuid);
            if ($existing !== null) {
                self::sameRequest($existing, ['kind' => 'refund', 'parent_id' => $charge->id, 'amount_minor' => $amountMinor]);

                return $existing;
            }
            if (! in_array($charge->state, [PaymentTerminal::CAPTURED, 'refunded'], true) || $charge->provider_reference === null) {
                throw new TerminalRejected('not_refundable', ___('Only a captured card payment can be refunded.'));
            }
            if ((int) $charge->refunded_minor + $amountMinor > (int) $charge->amount_minor) {
                throw new TerminalRejected('over_refund', ___('The refunds would be more than the payment.'));
            }
            $charge->increment('refunded_minor', $amountMinor); // reserved; released if the provider refuses

            return TerminalPayment::query()->create(['store_id' => $store->id, 'client_uuid' => $clientUuid, 'kind' => 'refund', 'parent_id' => $charge->id,
                'provider' => $charge->provider, 'provider_reference' => $charge->provider_reference, 'state' => 'creating', 'amount_minor' => $amountMinor,
                'currency_code' => $charge->currency_code, 'refunded_minor' => 0]);
        });
        if ($row->state !== 'creating') {
            return self::view($row);
        }
        $account = $this->accountOf($row);
        try {
            $res = $this->driver((string) $row->provider)->refund($account, (string) $row->provider_reference, $clientUuid, $amountMinor, (string) $row->currency_code);
        } catch (RuntimeException|ConnectionException $e) {
            Log::warning('[softpos] refund failed: '.$e->getMessage());

            throw new TerminalRejected('provider_unavailable', ___('The card terminal service did not answer. Try again.'), 502);
        }
        DB::transaction(function () use ($row, $res) {
            $charge = TerminalPayment::query()->whereKey($row->parent_id)->lockForUpdate()->firstOrFail();
            if (! empty($res['ok'])) {
                $row->forceFill(['state' => 'refunded'])->save();
                if ((int) $charge->refunded_minor >= (int) $charge->amount_minor) {
                    $charge->forceFill(['state' => 'refunded'])->save();
                }
            } else {
                $row->forceFill(['state' => 'failed'])->save();
                $charge->decrement('refunded_minor', (int) $row->amount_minor);
            }
        });

        return self::view($row->refresh());
    }

    public function void(Store $store, string $clientUuid, string $chargeClientUuid): array
    {
        $row = DB::transaction(function () use ($store, $clientUuid, $chargeClientUuid) {
            $charge = TerminalPayment::query()->where('store_id', $store->id)->where('client_uuid', $chargeClientUuid)->where('kind', 'charge')->lockForUpdate()->first()
                ?? throw new TerminalRejected('not_found', ___('Payment not found.'), 404);
            $existing = $this->find($store, $clientUuid);
            if ($existing !== null) {
                self::sameRequest($existing, ['kind' => 'void', 'parent_id' => $charge->id]);

                return $existing;
            }
            if (! in_array($charge->state, self::SETTLED, true) || (int) $charge->refunded_minor > 0 || $charge->provider_reference === null) {
                throw new TerminalRejected('not_voidable', ___('This card payment cannot be voided.'));
            }

            return TerminalPayment::query()->create(['store_id' => $store->id, 'client_uuid' => $clientUuid, 'kind' => 'void', 'parent_id' => $charge->id,
                'provider' => $charge->provider, 'provider_reference' => $charge->provider_reference, 'state' => 'creating', 'amount_minor' => $charge->amount_minor,
                'currency_code' => $charge->currency_code, 'refunded_minor' => 0]);
        });
        if ($row->state !== 'creating') {
            return self::view($row);
        }
        $account = $this->accountOf($row);
        try {
            $res = $this->driver((string) $row->provider)->void($account, (string) $row->provider_reference, $clientUuid);
        } catch (RuntimeException|ConnectionException $e) {
            Log::warning('[softpos] void failed: '.$e->getMessage());

            throw new TerminalRejected('provider_unavailable', ___('The card terminal service did not answer. Try again.'), 502);
        }
        DB::transaction(function () use ($row, $res) {
            $ok = ! empty($res['ok']);
            $row->forceFill(['state' => $ok ? 'voided' : 'failed'])->save();
            if ($ok) {
                TerminalPayment::query()->whereKey($row->parent_id)->update(['state' => 'voided']);
            }
        });

        return self::view($row->refresh());
    }

    public function payment(Store $store, string $clientUuid): ?array
    {
        $row = $this->find($store, $clientUuid);

        return $row === null ? null : self::view($row);
    }

    public function status(Store $store, string $chargeClientUuid): array
    {
        $row = TerminalPayment::query()->where('store_id', $store->id)->where('client_uuid', $chargeClientUuid)->where('kind', 'charge')->first()
            ?? throw new TerminalRejected('not_found', ___('Payment not found.'), 404);
        if ($row->provider_reference !== null) {
            $this->reconcile($row);
        }

        return self::view($row);
    }

    /** Re-read the charge from the provider and record its state. */
    public function reconcile(TerminalPayment $row): void
    {
        $account = $this->accountOf($row);
        try {
            $res = $this->driver((string) $row->provider)->fetch($account, (string) $row->provider_reference);
        } catch (RuntimeException|ConnectionException $e) {
            Log::warning('[softpos] status failed: '.$e->getMessage());

            throw new TerminalRejected('provider_unavailable', ___('The card terminal service did not answer. Try again.'), 502);
        }
        $this->apply($row, $res);
    }

    /**
     * Record the provider's answer. A final state we wrote (voided / refunded) is not walked back by a later status
     * read; a pending / authorised row moves forward.
     *
     * @param  array<string, mixed>  $res
     */
    private function apply(TerminalPayment $row, array $res): void
    {
        $state = (string) ($res['state'] ?? PaymentTerminal::PENDING);
        $fill = array_intersect_key($res, array_flip(['masked_pan', 'scheme', 'auth_code']));
        if ($row->provider_reference === null && ! empty($res['reference'])) {
            $fill['provider_reference'] = (string) $res['reference'];
        }
        if (! in_array($row->state, ['voided', 'refunded'], true) && in_array($state, [PaymentTerminal::PENDING, PaymentTerminal::AUTHORISED, PaymentTerminal::CAPTURED, PaymentTerminal::DECLINED, 'voided', 'refunded', 'failed'], true)) {
            $fill['state'] = $state;
        }
        $row->forceFill($fill)->save();
        $this->settleIfCaptured($row);
    }

    /** A captured charge for an order that is not settled yet: ask the POS to settle it (the listener claims it once). */
    private function settleIfCaptured(TerminalPayment $row): void
    {
        if ($row->kind === 'charge' && $row->state === PaymentTerminal::CAPTURED && $row->order_id !== null && $row->settled_at === null) {
            event(new TerminalPaymentCaptured((int) $row->id, (int) $row->store_id, (int) $row->order_id, (int) $row->amount_minor,
                $row->tip_minor === null ? null : (int) $row->tip_minor, (string) $row->currency_code));
            $row->refresh();
        }
    }

    public function claimSettlement(Store $store, int $paymentId): bool
    {
        return TerminalPayment::query()->whereKey($paymentId)->where('store_id', $store->id)->where('kind', 'charge')
            ->where('state', PaymentTerminal::CAPTURED)->whereNotNull('order_id')->whereNull('settled_at')
            ->update(['settled_at' => now()]) === 1;
    }

    /** @return array<string, mixed> */
    public static function view(TerminalPayment $p): array
    {
        return array_filter(['client_uuid' => $p->client_uuid, 'kind' => $p->kind, 'provider' => $p->provider, 'reference' => $p->provider_reference,
            'state' => $p->state === 'creating' ? PaymentTerminal::PENDING : $p->state, 'amount_minor' => (int) $p->amount_minor, 'currency' => $p->currency_code,
            'refunded_minor' => (int) $p->refunded_minor, 'order_id' => $p->order_id === null ? null : (int) $p->order_id,
            'settled' => $p->kind === 'charge' && $p->order_id !== null ? $p->settled_at !== null : null, 'masked_pan' => $p->masked_pan, 'scheme' => $p->scheme, 'auth_code' => $p->auth_code], fn ($v) => $v !== null);
    }

    /**
     * A provider's callback to the store's own URL: verified with THAT store's secret, processed once, and the state is
     * re-read from the provider (the callback body is only a hint).
     */
    public function webhook(int $storeId, string $provider, Request $request): Response
    {
        if (! self::enabled($provider)) {
            abort(404);
        }
        $account = TerminalAccount::query()->where('store_id', $storeId)->where('provider', $provider)->first();
        $driver = $this->driver($provider);
        if ($account === null || ! $driver->verifyCallback($account, $request)) {
            Log::warning("[softpos] {$provider} callback for store {$storeId} refused: bad signature");

            return response()->json(['message' => 'invalid signature'], 401);
        }
        $ref = $driver->callbackReference($request);
        $row = $ref === null ? null : TerminalPayment::query()->where('store_id', $storeId)->where('provider', $provider)->where('provider_reference', $ref)->where('kind', 'charge')->first();
        if ($row === null) {
            return response()->json(['ok' => true]); // not one of ours (or not yet recorded): the status read reconciles later
        }

        return WebhookReceipts::once($provider, $storeId.':'.hash('sha256', (string) $request->getContent()), function () use ($row) {
            try {
                $this->reconcile($row);
            } catch (TerminalRejected) {
                return response()->json(['message' => 'retry'], 503); // released: the provider retries
            }

            return response()->json(['ok' => true]);
        }, fn () => response()->json(['ok' => true]));
    }
}
