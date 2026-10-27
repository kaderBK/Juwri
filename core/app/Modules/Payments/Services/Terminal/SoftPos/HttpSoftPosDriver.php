<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Terminal\SoftPos;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Juwri\Core\Money\Currencies;
use Juwri\Core\Money\Money;
use Juwri\Modules\Payments\Contracts\PaymentTerminal;
use Juwri\Modules\Payments\Models\TerminalAccount;
use RuntimeException;

/** Shared HTTP plumbing of the SoftPOS drivers (config softpos.<provider>: base_url + paths). */
abstract class HttpSoftPosDriver implements SoftPosDriver
{
    abstract protected function configKey(): string;

    abstract protected function client(TerminalAccount $account): PendingRequest;

    /**
     * Provider status → our state.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    abstract protected function normalise(array $body): array;

    protected function url(string $path, ?string $reference = null): string
    {
        $p = (string) config('softpos.'.$this->configKey().'.paths.'.$path);

        return rtrim((string) config('softpos.'.$this->configKey().'.base_url'), '/').str_replace('{ref}', rawurlencode((string) $reference), $p);
    }

    protected static function major(int $minor, string $currency): string
    {
        $exp = Currencies::exponent($currency);

        return number_format(Money::majorFromMinor($minor, $currency), $exp, '.', '');
    }

    /** @return array<string, mixed> */
    protected function json(Response $r, bool $clientErrorIsAnswer = true): array
    {
        if ($r->serverError() || $r->status() === 0 || $r->status() === 429 || (! $clientErrorIsAnswer && $r->clientError())) {
            throw new RuntimeException($this->key().': '.$r->status());
        }
        $body = $r->json();
        if (! is_array($body)) {
            throw new RuntimeException($this->key().': not json');
        }

        return $body + ['__status' => $r->status()];
    }

    /** @return array<string, mixed> */
    public function create(TerminalAccount $account, string $clientUuid, int $amountMinor, string $currency): array
    {
        $body = $this->json($this->client($account)->post($this->url('create'), $this->createBody($account, $clientUuid, $amountMinor, $currency)));

        // a refused request (4xx) is a final answer: the payment failed
        return ($body['__status'] ?? 500) >= 400 ? ['state' => 'failed'] : $this->normalise($body);
    }

    /** @return array<string, mixed> */
    public function fetch(TerminalAccount $account, string $reference): array
    {
        return $this->normalise($this->json($this->client($account)->get($this->url('show', $reference)), false));
    }

    /** @return array<string, mixed> */
    public function refund(TerminalAccount $account, string $reference, string $clientUuid, int $amountMinor, string $currency): array
    {
        $body = $this->json($this->client($account)->post($this->url('refund', $reference), ['amount' => self::major($amountMinor, $currency), 'currency' => $currency, 'refundReference' => $clientUuid]));

        return $this->normalise($body) + ['ok' => ($body['__status'] ?? 500) < 300];
    }

    /** @return array<string, mixed> */
    public function void(TerminalAccount $account, string $reference, string $clientUuid): array
    {
        $body = $this->json($this->client($account)->post($this->url('void', $reference), ['voidReference' => $clientUuid]));

        return $this->normalise($body) + ['ok' => ($body['__status'] ?? 500) < 300];
    }

    /** @return array<string, mixed> */
    abstract protected function createBody(TerminalAccount $account, string $clientUuid, int $amountMinor, string $currency): array;

    protected static function state(string $provider): string
    {
        return match (strtolower($provider)) {
            'captured', 'success', 'paid', 'settled', 'approved' => PaymentTerminal::CAPTURED,
            'authorized', 'authorised' => PaymentTerminal::AUTHORISED,
            'declined', 'failed', 'rejected', 'expired' => PaymentTerminal::DECLINED,
            'voided', 'cancelled', 'canceled', 'reversed' => 'voided',
            'refunded' => 'refunded',
            default => PaymentTerminal::PENDING,
        };
    }
}
